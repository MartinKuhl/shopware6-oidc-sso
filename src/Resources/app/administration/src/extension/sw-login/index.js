/**
 * Extends Shopware's Administration login form with a "Login with SSO"
 * button, a "Login with Passkey" button, and the nonce-exchange step that
 * completes the OIDC bridge — see the plan's "Administration (Backend user)
 * OIDC flow" section.
 *
 * The username/password form (and Shopware's own native SSO-forwarding
 * button) lives in `sw-login-login`, not in `sw-login` itself - `sw-login`
 * is just an outer shell around a <router-view>. Confirmed against
 * Shopware 6.7's actual Administration source
 * (module/sw-login/view/sw-login-login), which is also why `mt-button` /
 * `mt-banner` are used below instead of the older `sw-button` / `sw-alert`.
 */
import template from './sw-login.html.twig';
import './sw-login.scss';
import {
    preparePublicKeyRequestOptions,
    serializeAssertionCredential,
} from '../../service/webauthn-codec';
import { consumeSsoReturnRoute } from '../../service/sso-return-route';
import { rememberLoginSession } from '../../service/login-session';

const { Component } = Shopware;

/**
 * German strings for the handful of sw6oidcTranslate() keys actually
 * rendered on the pre-auth login screen - see the comment on
 * sw6oidcTranslate() below for why $tc() can never resolve these here.
 * Kept in sync with de-DE.json's own "login" section by hand; there's no
 * good way to share this at build time since de-DE.json is never loaded
 * on this screen either.
 */
const FALLBACK_LOCALE_DICTIONARY = {
    'de-DE': {
        'sw6oidc.login.ssoButton': 'Mit SSO anmelden',
        'sw6oidc.login.ssoButtonWithProvider': 'Login mit {name}',
        'sw6oidc.login.passkeyButton': 'Login mit Passkey',
        'sw6oidc.login.passkeyError': 'Die Passkey-Anmeldung ist fehlgeschlagen. Bitte versuchen Sie es erneut oder melden Sie sich mit Benutzername und Passwort an.',
        'sw6oidc.login.error': 'Die Single-Sign-on-Anmeldung ist fehlgeschlagen. Bitte versuchen Sie es erneut oder melden Sie sich mit Benutzername und Passwort an.',
        'sw6oidc.login.errorRoleMissing': 'Ihr Konto konnte nicht automatisch angelegt werden, da keine Administratorrolle zugewiesen werden konnte. Bitte wenden Sie sich an Ihren Administrator.',
        'sw6oidc.login.errorAutoCreateDisabled': 'Die automatische Kontoerstellung ist für diese Anmeldemethode deaktiviert. Bitte wenden Sie sich an Ihren Administrator.',
        'sw6oidc.login.passwordLoginDisabled': 'Die Anmeldung mit Passwort ist deaktiviert. Bitte melden Sie sich mit Single Sign-on oder einem Passkey an.',
        'sw6oidc.login.errorAccessDenied': 'Zugriff verweigert: Ihr Konto erfüllt nicht die Voraussetzungen für die Anmeldung an der Administration.',
        'sw6oidc.login.errorLinkRequired': 'Es gibt bereits ein Konto mit dieser E-Mail-Adresse. Bitte melden Sie sich mit Ihrem Passwort an und verbinden Sie Single Sign-on in Ihrem Profil.',
        'sw6oidc.login.errorEmailNotVerified': 'Ihr Identitätsanbieter hat Ihre E-Mail-Adresse nicht bestätigt.',
        'sw6oidc.login.errorProviderMismatch': 'Dieses Konto ist mit einer anderen Single-Sign-on-Identität verbunden.',
    },
};

Component.override('sw-login-login', {
    template,

    inject: ['loginService'],

    data() {
        return {
            sw6oidcExchangeError: null,
            /** Admin-configured access-control message, redeemed from a one-time error ticket. */
            sw6oidcErrorDetail: null,
            sw6oidcPasskeyError: null,
            sw6oidcPasskeyPending: false,
            /** @type {Array<{id: string, label: string|null}>} one entry per visible admin-scoped provider */
            sw6oidcSsoProviders: [],
            sw6oidcPasskeyAvailable: false,
            /** disable_non_oidc_admin_login is on: hide the native username/password form (the server rejects the password grant regardless). */
            sw6oidcPasswordLoginDisabled: false,
        };
    },

    created() {
        this.sw6oidcHandleCallback();
        this.sw6oidcLoadLoginOptions();
    },

    computed: {
        /**
         * `sw6oidc_error` on the admin callback redirect distinguishes a few
         * known denial reasons (see OidcAdminAuthController::callback()) —
         * map each to its own message, worded generically enough for a
         * pre-auth screen (no group/role names), and fall back to the
         * original generic message for anything else (provider_unavailable,
         * exchange_failed, unrecognized future codes).
         */
        sw6oidcErrorMessage() {
            if (this.sw6oidcErrorDetail) {
                return this.sw6oidcErrorDetail;
            }

            const messages = {
                access_denied: [
                    'sw6oidc.login.errorAccessDenied',
                    'Access denied: your account does not meet the requirements for signing in to the Administration.',
                ],
                admin_role_missing: [
                    'sw6oidc.login.errorRoleMissing',
                    'Your account could not be created automatically because no administrator role could be assigned. Please contact your administrator.',
                ],
                admin_auto_create_disabled: [
                    'sw6oidc.login.errorAutoCreateDisabled',
                    'Automatic account creation is disabled for this login method. Please contact your administrator.',
                ],
                link_required: [
                    'sw6oidc.login.errorLinkRequired',
                    'An account with this email address already exists. Log in with your password and connect single sign-on in your profile.',
                ],
                email_not_verified: [
                    'sw6oidc.login.errorEmailNotVerified',
                    'Your identity provider has not verified your email address.',
                ],
                provider_mismatch: [
                    'sw6oidc.login.errorProviderMismatch',
                    'This account is connected to a different single sign-on identity.',
                ],
            };

            const [key, fallback] = messages[this.sw6oidcExchangeError] ?? [
                'sw6oidc.login.error',
                'Single sign-on login failed. Please try again or log in with your username and password.',
            ];

            return this.sw6oidcTranslate(key, fallback);
        },
    },

    methods: {
        /**
         * Shopware's own bootLogin() (core/application.ts) always stamps
         * sessionStorage['sw-login-should-reload'] = 'true' the moment the
         * pre-auth login screen boots, precisely because bootLogin() skips
         * loadPlugins() and the rest of the full-app initializers (see
         * index.html.twig's own comment on this) - a normal password login's
         * handleLoginSuccess() checks that flag after routing to 'core' and
         * does a full window reload so bootFullApplication() actually runs
         * this time. Our own login paths (passkey, OIDC nonce exchange) skip
         * straight to router.push() and never reload, which is exactly why
         * the dashboard renders as a blank white page until a manual F5 -
         * the SPA never re-initialized any of the modules/stores/menu that
         * only bootFullApplication() sets up. Mirror core's own sequence
         * here instead of just navigating.
         */
        async sw6oidcFinishLogin() {
            // Set when the SSO login was started from the inactivity re-login modal:
            // go back to the page the admin was on, like core's own re-login does.
            const returnPath = consumeSsoReturnRoute();

            if (returnPath) {
                sessionStorage.removeItem('lastKnownUser');
                // Other tabs still showing the inactivity modal forward themselves on this.
                try {
                    const channel = new BroadcastChannel('session_channel');
                    channel.postMessage({ inactive: false });
                    channel.close();
                } catch {
                    // BroadcastChannel unsupported: other tabs just stay on their modal.
                }
            }

            await this.$router.push(returnPath ?? { name: 'core' });

            const shouldReload = sessionStorage.getItem('sw-login-should-reload');

            if (shouldReload) {
                sessionStorage.removeItem('sw-login-should-reload');
                window.location.reload();
            }
        },

        sw6oidcStartLogin(providerId) {
            window.location.href = `/api/sw6oidc/admin/login?providerId=${encodeURIComponent(providerId)}`;
        },

        /**
         * Falls back to the generic translated string only when this
         * specific provider has no displayName set - a shop with more than
         * one active admin provider otherwise has no way to tell their
         * buttons apart.
         */
        sw6oidcSsoButtonLabel(provider) {
            if (!provider.label) {
                return this.sw6oidcTranslate('sw6oidc.login.ssoButton', 'Login with SSO');
            }

            return this.sw6oidcTranslate('sw6oidc.login.ssoButtonWithProvider', 'Login with {name}').replace('{name}', provider.label);
        },

        /**
         * This endpoint is anonymous (no user is known yet), so it can only
         * ever answer "is SSO/Passkey login configured/enabled at all" — not
         * "does the person about to log in have one." Both buttons default
         * to hidden (see data()) and only appear once this resolves true;
         * any error here (network, non-2xx) leaves them hidden rather than
         * risking showing a button for a feature that isn't actually set up.
         */
        async sw6oidcLoadLoginOptions() {
            try {
                const response = await fetch('/api/sw6oidc/admin/login-options');

                if (!response.ok) {
                    return;
                }

                const { ssoProviders, passkeyAvailable, passwordLoginDisabled } = await response.json();

                this.sw6oidcSsoProviders = Array.isArray(ssoProviders) ? ssoProviders : [];
                this.sw6oidcPasskeyAvailable = Boolean(passkeyAvailable);
                // Only hide the password form when this screen offers another way in (F-N4).
                this.sw6oidcPasswordLoginDisabled = Boolean(passwordLoginDisabled)
                    && (this.sw6oidcSsoProviders.length > 0 || this.sw6oidcPasskeyAvailable);
            } catch (exception) {
                // eslint-disable-next-line no-console
                console.error('sw6oidc: failed to load admin login options', exception);
            }
        },

        async sw6oidcStartPasskeyLogin() {
            if (!window.PublicKeyCredential) {
                this.sw6oidcPasskeyError = 'not_supported';
                return;
            }

            this.sw6oidcPasskeyError = null;
            this.sw6oidcPasskeyPending = true;

            try {
                const optionsResponse = await fetch('/api/sw6oidc/admin/passkey/login-options', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams(),
                });

                if (!optionsResponse.ok) {
                    throw new Error(`Passkey options request failed with status ${optionsResponse.status}`);
                }

                const { sessionId, options } = await optionsResponse.json();

                // No allowCredentials hint is sent (email-less), so this
                // relies on discoverable/resident credentials: the browser
                // shows an account chooser from any passkey registered for
                // this Relying Party ID, exactly as registered via
                // sw6oidc-passkey-list's residentKeyAuthenticatorSelection().
                const assertion = await navigator.credentials.get({
                    publicKey: preparePublicKeyRequestOptions(options),
                });

                const verifyResponse = await fetch('/api/sw6oidc/admin/passkey/login-verify', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        sessionId,
                        credential: JSON.stringify(serializeAssertionCredential(assertion)),
                    }),
                });

                if (!verifyResponse.ok) {
                    throw new Error(`Passkey login failed with status ${verifyResponse.status}`);
                }

                const tokenData = await verifyResponse.json();

                // Mirrors loginService.loginByUsername(): resets the
                // inactivity clock localStorage['lastActivity'] tracks. Without
                // this, a stale timestamp left over from a previous session
                // (anything older than 30 minutes) makes the auto-refresh
                // timer that setBearerAuthentication() arms (firing at half
                // the access token's TTL) treat this brand-new login as
                // "inactive" and force a logout instead of refreshing.
                Shopware.Service('userActivityService').updateLastUserActivity();

                this.loginService.setBearerAuthentication({
                    access: tokenData.access_token,
                    refresh: tokenData.refresh_token,
                    expiry: tokenData.expires_in,
                });

                await this.sw6oidcFinishLogin();
            } catch (exception) {
                this.sw6oidcPasskeyError = 'login_failed';
                // eslint-disable-next-line no-console
                console.error('sw6oidc: admin passkey login failed', exception);
            } finally {
                this.sw6oidcPasskeyPending = false;
            }
        },

        async sw6oidcHandleCallback() {
            // window.location.search is always empty here: the Administration
            // router uses hash mode, so the URL this nonce actually arrives on
            // is ".../admin#/login?sw6oidc_nonce=..." - the query string is
            // part of the hash fragment, not the "real" URL query string.
            // Reading window.location.search silently found nothing, so this
            // never even attempted the token exchange - no fetch, no log, no
            // visible error, just a login page that never logs in.
            const params = this.sw6oidcHashUrl().searchParams;
            const nonce = params.get('sw6oidc_nonce');
            const error = params.get('sw6oidc_error');

            if (error) {
                const ticket = params.get('sw6oidc_error_ticket');

                this.sw6oidcExchangeError = error;
                this.sw6oidcCleanUrl();

                if (ticket) {
                    await this.sw6oidcLoadErrorDetail(ticket);
                }

                return;
            }

            if (!nonce) {
                return;
            }

            try {
                const response = await fetch('/api/sw6oidc/admin/token', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        grant_type: 'sw6oidc_admin',
                        client_id: 'administration',
                        sw6oidc_nonce: nonce,
                    }),
                });

                if (!response.ok) {
                    throw new Error(`Token exchange failed with status ${response.status}`);
                }

                const tokenData = await response.json();

                // See the matching comment in sw6oidcStartPasskeyLogin() -
                // same inactivity-clock reset loginByUsername() does, which
                // this nonce-exchange path otherwise skips entirely.
                Shopware.Service('userActivityService').updateLastUserActivity();

                // Mirrors what Shopware's own password-grant login does with
                // the /api/oauth/token response — verify the exact shape
                // loginService expects against the installed version.
                this.loginService.setBearerAuthentication({
                    access: tokenData.access_token,
                    refresh: tokenData.refresh_token,
                    expiry: tokenData.expires_in,
                });
                rememberLoginSession(tokenData.sw6oidc_login_session);

                this.sw6oidcCleanUrl();
                await this.sw6oidcFinishLogin();
            } catch (exception) {
                this.sw6oidcExchangeError = 'exchange_failed';
                this.sw6oidcCleanUrl();
                // eslint-disable-next-line no-console
                console.error('sw6oidc: admin token exchange failed', exception);
            }
        },

        /**
         * Redeems the one-time error ticket an access-control denial carries
         * (the message itself never travels in the URL). Any failure keeps
         * the generic access-denied text.
         */
        async sw6oidcLoadErrorDetail(ticket) {
            try {
                const response = await fetch(`/api/sw6oidc/admin/login-error/${encodeURIComponent(ticket)}`);

                if (!response.ok) {
                    return;
                }

                const { message } = await response.json();

                this.sw6oidcErrorDetail = typeof message === 'string' && message !== '' ? message : null;
            } catch (exception) {
                // eslint-disable-next-line no-console
                console.error('sw6oidc: failed to load login error detail', exception);
            }
        },

        /**
         * Parses the query string out of the hash fragment (window.location.hash,
         * e.g. "#/login?sw6oidc_nonce=...") rather than window.location.search,
         * which the Administration's hash-based router never touches.
         */
        sw6oidcHashUrl() {
            return new URL(window.location.hash.slice(1) || '/', window.location.origin);
        },

        sw6oidcCleanUrl() {
            const hashUrl = this.sw6oidcHashUrl();
            hashUrl.searchParams.delete('sw6oidc_nonce');
            hashUrl.searchParams.delete('sw6oidc_error');
            hashUrl.searchParams.delete('sw6oidc_error_ticket');

            const query = hashUrl.searchParams.toString();
            const newHash = `#${hashUrl.pathname}${query ? `?${query}` : ''}`;

            window.history.replaceState({}, document.title, window.location.pathname + newHash);
        },

        // $tc() always returns the raw key on this specific screen, never
        // the actual translation - not a loading-order fluke, but a
        // deliberate server-side filter: GET /api/_admin/snippets (see
        // AdministrationController::filterByAuthentication() in Shopware
        // core) only ever returns the "sw-login"/"global" snippet
        // namespaces to an unauthenticated request, and this plugin's own
        // "sw6oidc" namespace isn't and can never be on that allow-list.
        // Core's own "Melde Dich bei Shopware an" text on this exact
        // screen is unaffected because it lives in "sw-login" - so it
        // localizes correctly while ours doesn't, unless we resolve the
        // fallback text's own language ourselves instead of hardcoding
        // English. FALLBACK_LOCALE_DICTIONARY intentionally covers only
        // the handful of keys actually rendered on this screen.
        sw6oidcTranslate(key, fallback) {
            const translated = this.$tc(key);

            if (translated && translated !== key) {
                return translated;
            }

            return FALLBACK_LOCALE_DICTIONARY[this.sw6oidcPreAuthLocale()]?.[key] ?? fallback;
        },

        /**
         * Mirrors core's own locale.factory.ts getLastKnownLocale(): prefers
         * the same localStorage key core itself writes on every locale
         * switch (so this matches whatever language "Melde Dich bei
         * Shopware an" is already showing in), then falls back to the
         * browser's own language. Only "de-DE" is special-cased since
         * that's the only other locale this plugin ships translations for
         * at all (see de-DE.json/en-GB.json) - anything else already
         * wants the English fallback.
         */
        sw6oidcPreAuthLocale() {
            let stored = null;

            try {
                stored = window.localStorage.getItem('sw-admin-locale');
            } catch {
                // localStorage can throw (private browsing, blocked storage) - fall through to the browser language below.
            }

            const locale = stored || navigator.language || 'en-GB';

            return locale.toLowerCase().startsWith('de') ? 'de-DE' : 'en-GB';
        },
    },
});

import template from './sw-inactivity-login.html.twig';
import './sw-inactivity-login.scss';
import {
    preparePublicKeyRequestOptions,
    serializeAssertionCredential,
} from '../../service/webauthn-codec';
import { rememberSsoReturnRoute } from '../../service/sso-return-route';

const { Component } = Shopware;

/**
 * Adds "Login with <provider>" (OIDC) buttons and a "Login with Passkey"
 * option to Shopware's own inactivity/session-
 * timeout re-login modal (core module/sw-inactivity-login) - the SPA
 * navigates here in-place (no full page reload) whenever a background token
 * refresh fails while the admin is actively using the app, so - unlike the
 * pre-auth sw-login-login screen - this component is always reached through
 * Shopware's normal loadPlugins() mechanism after a full boot already ran.
 * No forced early script injection and no translation fallback is needed
 * here; our own snippet files are already loaded normally by this point.
 *
 * Reuses the same email-scoped WebAuthn ceremony as the main login screen,
 * but narrowed to lastKnownUser (already known here, unlike the main screen)
 * via PasskeyAdminController::loginOptions()'s optional `email` parameter -
 * tighter/faster than a fully discoverable-credential flow, since we already
 * know exactly which admin is re-authenticating.
 *
 * The SSO buttons start the normal admin OIDC login (full-page redirect);
 * the page the admin was on is carried over in sessionStorage
 * (service/sso-return-route) and restored by the sw-login override after the
 * nonce exchange. With password login disabled for admins, the password
 * field and the "Log in" button are hidden, as on the main login screen.
 */
Component.override('sw-inactivity-login', {
    template,

    inject: ['loginService'],

    data() {
        return {
            sw6oidcPasskeyAvailable: false,
            /** @type {Array<{id: string, label: string|null}>} visible admin-scoped providers */
            sw6oidcSsoProviders: [],
            /** disable_non_oidc_admin_login is on: hide the password field (the server rejects it anyway). */
            sw6oidcPasswordLoginDisabled: false,
            sw6oidcPasskeyPending: false,
            sw6oidcPasskeyError: null,
        };
    },

    created() {
        this.sw6oidcLoadLoginOptions();
    },

    methods: {
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

        sw6oidcSsoButtonLabel(provider) {
            return provider.label
                ? this.$t('sw6oidc.login.ssoButtonWithProvider', { name: provider.label })
                : this.$t('sw6oidc.login.ssoButton');
        },

        /**
         * OIDC re-login is a full-page round trip through the IdP (usually
         * instant while the IdP session is still alive). Core keeps the page
         * the admin was on under sw-admin-previous-route_<hash>; copy it so the
         * sw-login override can return there after the nonce exchange.
         */
        sw6oidcStartSsoLogin(providerId) {
            try {
                const previousRoute = JSON.parse(sessionStorage.getItem(`sw-admin-previous-route_${this.hash}`) || '{}');
                rememberSsoReturnRoute(previousRoute?.fullPath);
            } catch {
                // No previous route: land on the dashboard after re-login.
            }

            window.location.href = `/api/sw6oidc/admin/login?providerId=${encodeURIComponent(providerId)}`;
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
                    body: new URLSearchParams({ email: this.lastKnownUser }),
                });

                if (!optionsResponse.ok) {
                    throw new Error(`Passkey options request failed with status ${optionsResponse.status}`);
                }

                const { sessionId, options } = await optionsResponse.json();

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

                this.loginService.setBearerAuthentication({
                    access: tokenData.access_token,
                    refresh: tokenData.refresh_token,
                    expiry: tokenData.expires_in,
                });

                // handleLoginSuccess() is the ORIGINAL component's own method
                // - Component.override() merges into the same instance, so
                // it's still available on `this`. It calls forwardLogin(),
                // which already does the router-push + window.location.reload()
                // dance itself and notifies the other-tab session channel -
                // no need to duplicate any of that here.
                this.handleLoginSuccess();
            } catch (exception) {
                this.sw6oidcPasskeyError = 'login_failed';
                // eslint-disable-next-line no-console
                console.error('sw6oidc: inactivity passkey login failed', exception);
            } finally {
                this.sw6oidcPasskeyPending = false;
            }
        },
    },
});

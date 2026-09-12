import template from './sw-inactivity-login.html.twig';
import './sw-inactivity-login.scss';
import {
    preparePublicKeyRequestOptions,
    serializeAssertionCredential,
} from '../../service/webauthn-codec';

const { Component } = Shopware;

/**
 * Adds a "Login with Passkey" option to Shopware's own inactivity/session-
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
 */
Component.override('sw-inactivity-login', {
    template,

    inject: ['loginService'],

    data() {
        return {
            sw6oidcPasskeyAvailable: false,
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

                const { passkeyAvailable } = await response.json();
                this.sw6oidcPasskeyAvailable = Boolean(passkeyAvailable);
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

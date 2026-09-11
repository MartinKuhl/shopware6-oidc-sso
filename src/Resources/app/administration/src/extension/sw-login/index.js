/**
 * Extends Shopware's Administration login page with an "Login with SSO"
 * button and the nonce-exchange step that completes the OIDC bridge — see
 * the plan's "Administration (Backend user) OIDC flow" section.
 *
 * VERIFICATION NEEDED: this overrides the `sw-login` component and calls
 * Shopware.Service('loginService') by its documented public method names
 * (setBearerAuthentication / loginByUsername shape). Administration internals
 * change more often than the stable core PHP APIs used elsewhere in this
 * plugin — confirm both the component name and the loginService method
 * signatures against the actual installed Shopware 6.7 Administration source
 * before relying on this (see plan's Verification section: "Administration:
 * confirm the SPA ends up with a real, working access token").
 */
import template from './sw-login.html.twig';
import {
    preparePublicKeyRequestOptions,
    serializeAssertionCredential,
} from '../../service/webauthn-codec';

const { Component } = Shopware;

Component.override('sw-login', {
    template,

    inject: ['loginService'],

    data() {
        return {
            sw6oidcExchangeError: null,
            sw6oidcPasskeyError: null,
            sw6oidcPasskeyPending: false,
        };
    },

    created() {
        this.sw6oidcHandleCallback();
    },

    methods: {
        sw6oidcStartLogin() {
            window.location.href = '/api/sw6oidc/admin/login';
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

                this.loginService.setBearerAuthentication({
                    access: tokenData.access_token,
                    refresh: tokenData.refresh_token,
                    expiry: tokenData.expires_in,
                });

                this.$router.push({ name: 'core' });
            } catch (exception) {
                this.sw6oidcPasskeyError = 'login_failed';
                // eslint-disable-next-line no-console
                console.error('sw6oidc: admin passkey login failed', exception);
            } finally {
                this.sw6oidcPasskeyPending = false;
            }
        },

        async sw6oidcHandleCallback() {
            const params = new URLSearchParams(window.location.search);
            const nonce = params.get('sw6oidc_nonce');
            const error = params.get('sw6oidc_error');

            if (error) {
                this.sw6oidcExchangeError = error;
                this.sw6oidcCleanUrl();

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

                // Mirrors what Shopware's own password-grant login does with
                // the /api/oauth/token response — verify the exact shape
                // loginService expects against the installed version.
                this.loginService.setBearerAuthentication({
                    access: tokenData.access_token,
                    refresh: tokenData.refresh_token,
                    expiry: tokenData.expires_in,
                });

                this.sw6oidcCleanUrl();
                this.$router.push({ name: 'core' });
            } catch (exception) {
                this.sw6oidcExchangeError = 'exchange_failed';
                this.sw6oidcCleanUrl();
                // eslint-disable-next-line no-console
                console.error('sw6oidc: admin token exchange failed', exception);
            }
        },

        sw6oidcCleanUrl() {
            const url = new URL(window.location.href);
            url.searchParams.delete('sw6oidc_nonce');
            url.searchParams.delete('sw6oidc_error');
            window.history.replaceState({}, document.title, url.toString());
        },
    },
});

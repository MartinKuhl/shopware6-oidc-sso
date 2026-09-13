import template from './sw6oidc-profile-passkey.html.twig';
import {
    preparePublicKeyCreationOptions,
    serializeAttestationCredential,
} from '../../../../service/webauthn-codec';

const { Component, Mixin } = Shopware;

/**
 * Self-service passkey management on the admin's own profile page ("Mein
 * Profil" > Passkeys tab) - register/delete only the currently authenticated
 * admin's own credentials, via PasskeyAdminController's my-credentials/delete
 * actions. Deliberately never touches the generic sw6oidc_passkey_credential
 * entity API: that one has no per-row ownership check and is meant for the
 * cross-admin lockout-recovery grid in Settings, not self-service. Mirrors
 * the Magento reference module's own-account passkey management block.
 *
 * Registered as a lazy factory, matching sw6oidc-passkey-list - main.js is
 * force-loaded on the pre-auth login screen too (see
 * Resources/views/administration/index.html.twig), where the "notification"
 * mixin isn't registered yet and this component is never rendered anyway.
 */
Component.register('sw6oidc-profile-passkey', () => Promise.resolve({
    template,

    inject: ['loginService'],

    mixins: [Mixin.getByName('notification')],

    data() {
        return {
            credentials: [],
            isLoading: true,
            isRegistering: false,
            deletingId: null,
        };
    },

    computed: {
        columns() {
            return [
                { property: 'nickname', label: this.$tc('sw6oidc.passkeySettings.list.columnNickname') },
                { property: 'createdAt', label: this.$tc('sw6oidc.passkeySettings.list.columnCreatedAt') },
            ];
        },
    },

    created() {
        this.getList();
    },

    methods: {
        async getList() {
            this.isLoading = true;

            try {
                const response = await this.sw6oidcApiFetch('GET', '/api/sw6oidc/admin/passkey/my-credentials');

                if (!response.ok) {
                    throw new Error(`Failed to load passkeys with status ${response.status}`);
                }

                const { credentials } = await response.json();
                this.credentials = credentials;
            } catch (exception) {
                // eslint-disable-next-line no-console
                console.error('sw6oidc: failed to load own passkeys', exception);
            } finally {
                this.isLoading = false;
            }
        },

        async registerPasskey() {
            if (!window.PublicKeyCredential) {
                this.createNotificationError({ message: this.$tc('sw6oidc.passkeySettings.registerNoSupport') });
                return;
            }

            this.isRegistering = true;

            try {
                const optionsResponse = await this.sw6oidcApiFetch('POST', '/api/sw6oidc/admin/passkey/registration-options', {});

                if (!optionsResponse.ok) {
                    throw new Error(`Registration options request failed with status ${optionsResponse.status}`);
                }

                const { sessionId, options } = await optionsResponse.json();

                const credential = await navigator.credentials.create({
                    publicKey: preparePublicKeyCreationOptions(options),
                });

                const nickname = window.prompt(this.$tc('sw6oidc.passkeySettings.registerNicknamePrompt')) || null;

                const verifyResponse = await this.sw6oidcApiFetch('POST', '/api/sw6oidc/admin/passkey/registration-verify', {
                    sessionId,
                    credential: JSON.stringify(serializeAttestationCredential(credential)),
                    nickname,
                });

                const result = await verifyResponse.json();

                if (!verifyResponse.ok || !result.status) {
                    throw new Error(result.message || `Registration failed with status ${verifyResponse.status}`);
                }

                this.createNotificationSuccess({ message: this.$tc('sw6oidc.passkeySettings.registerSuccess') });
                await this.getList();
            } catch (exception) {
                // eslint-disable-next-line no-console
                console.error('sw6oidc: admin passkey registration failed', exception);
                this.createNotificationError({ message: this.$tc('sw6oidc.passkeySettings.registerError') });
            } finally {
                this.isRegistering = false;
            }
        },

        async deleteCredential(item) {
            // eslint-disable-next-line no-alert
            if (!window.confirm(this.$tc('sw6oidc.passkeySettings.deleteConfirm'))) {
                return;
            }

            this.deletingId = item.id;

            try {
                const response = await this.sw6oidcApiFetch('POST', '/api/sw6oidc/admin/passkey/delete', { id: item.id });
                const result = await response.json();

                if (!response.ok || !result.status) {
                    throw new Error(result.message || `Delete failed with status ${response.status}`);
                }

                // The server tracks which credential authenticated THIS
                // session's own access token (AdminPasskeyLoginTokenTracker)
                // and tells us here if that's exactly the one just deleted -
                // staying logged in under a since-revoked passkey would be
                // wrong, so log out immediately instead of just refreshing
                // the list.
                if (result.forceLogout) {
                    this.createNotificationSuccess({ message: this.$tc('sw6oidc.passkeySettings.deleteSuccessLoggedOut') });
                    this.loginService.logout();
                    return;
                }

                this.createNotificationSuccess({ message: this.$tc('sw6oidc.passkeySettings.deleteSuccess') });
                await this.getList();
            } catch (exception) {
                // eslint-disable-next-line no-console
                console.error('sw6oidc: admin passkey delete failed', exception);
                this.createNotificationError({ message: this.$tc('sw6oidc.passkeySettings.deleteError') });
            } finally {
                this.deletingId = null;
            }
        },

        sw6oidcApiFetch(method, path, bodyFields) {
            const init = {
                method,
                headers: { Authorization: `Bearer ${this.loginService.getToken()}` },
            };

            if (bodyFields) {
                init.headers['Content-Type'] = 'application/x-www-form-urlencoded';
                init.body = new URLSearchParams(
                    Object.fromEntries(Object.entries(bodyFields).filter(([, value]) => value !== null && value !== undefined)),
                );
            }

            return fetch(path, init);
        },
    },
}));

import template from './sw6oidc-passkey-list.html.twig';
import {
    preparePublicKeyCreationOptions,
    serializeAttestationCredential,
} from '../../../../service/webauthn-codec';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

/**
 * Lockout-recovery grid (list + delete, via sw-entity-listing) plus the
 * missing piece: a "Register new passkey" action that runs the actual
 * WebAuthn attestation ceremony for the currently logged-in admin against
 * PasskeyAdminController::registrationOptions/registrationVerify. Registering
 * a passkey has no dedicated DAL "create" form — a public key can't be
 * hand-typed — so this button, not sw-entity-listing's own create route, is
 * the only way to add a credential.
 *
 * Registered as a lazy factory (matching how Shopware's own core components
 * are registered), not a plain object, so that Mixin.getByName('notification')
 * below is only evaluated once Shopware actually builds this component -
 * never during this plugin's forced-early script execution on the login
 * screen (see Resources/views/administration/index.html.twig), where the
 * "notification" mixin isn't registered yet and this component is never
 * rendered anyway.
 */
Component.register('sw6oidc-passkey-list', () => Promise.resolve({
    template,

    inject: ['repositoryFactory', 'acl', 'loginService'],

    mixins: [Mixin.getByName('notification')],

    data() {
        return {
            credentials: null,
            isLoading: true,
            isRegistering: false,
            // Keyed by `${userType}:${userId}` -> a human-readable label.
            // sw6oidc_passkey_credential has no association to user/customer
            // (it's a polymorphic userType/userId pair, not a real FK), so
            // there's nothing the Admin API's own search can join in here -
            // this is resolved with a couple of extra lookups instead.
            ownerNames: {},
        };
    },

    metaInfo() {
        return {
            title: this.$createTitle(),
        };
    },

    computed: {
        credentialRepository() {
            return this.repositoryFactory.create('sw6oidc_passkey_credential');
        },

        userRepository() {
            return this.repositoryFactory.create('user');
        },

        customerRepository() {
            return this.repositoryFactory.create('customer');
        },

        columns() {
            return [
                { property: 'userType', label: this.$tc('sw6oidc.passkeySettings.list.columnUserType') },
                { property: 'owner', label: this.$tc('sw6oidc.passkeySettings.list.columnOwner') },
                { property: 'nickname', label: this.$tc('sw6oidc.passkeySettings.list.columnNickname') },
                { property: 'createdAt', label: this.$tc('sw6oidc.passkeySettings.list.columnCreatedAt') },
            ];
        },
    },

    created() {
        this.getList();
    },

    methods: {
        getList() {
            this.isLoading = true;
            const criteria = new Criteria(1, 25);
            criteria.addSorting(Criteria.sort('createdAt', 'DESC'));

            return this.credentialRepository.search(criteria, Shopware.Context.api).then(async (result) => {
                this.credentials = result;
                await this.loadOwnerNames(result);
                this.isLoading = false;
            });
        },

        async loadOwnerNames(credentials) {
            const adminIds = [...new Set(credentials.filter((credential) => credential.userType === 'admin').map((credential) => credential.userId))];
            const customerIds = [...new Set(credentials.filter((credential) => credential.userType === 'customer').map((credential) => credential.userId))];

            const [admins, customers] = await Promise.all([
                adminIds.length
                    ? this.userRepository.search(new Criteria(1, adminIds.length).addFilter(Criteria.equalsAny('id', adminIds)), Shopware.Context.api)
                    : Promise.resolve([]),
                customerIds.length
                    ? this.customerRepository.search(new Criteria(1, customerIds.length).addFilter(Criteria.equalsAny('id', customerIds)), Shopware.Context.api)
                    : Promise.resolve([]),
            ]);

            const names = {};
            admins.forEach((admin) => {
                names[`admin:${admin.id}`] = admin.username;
            });
            customers.forEach((customer) => {
                names[`customer:${customer.id}`] = `${customer.firstName} ${customer.lastName}`.trim() || customer.email;
            });

            this.ownerNames = names;
        },

        ownerName(item) {
            return this.ownerNames[`${item.userType}:${item.userId}`] || item.userId;
        },

        async registerPasskey() {
            if (!window.PublicKeyCredential) {
                this.createNotificationError({ message: this.$tc('sw6oidc.passkeySettings.registerNoSupport') });
                return;
            }

            this.isRegistering = true;

            try {
                const optionsResponse = await this.sw6oidcApiFetch('/api/sw6oidc/admin/passkey/registration-options', {});

                if (!optionsResponse.ok) {
                    throw new Error(`Registration options request failed with status ${optionsResponse.status}`);
                }

                const { sessionId, options } = await optionsResponse.json();

                const credential = await navigator.credentials.create({
                    publicKey: preparePublicKeyCreationOptions(options),
                });

                const nickname = window.prompt(this.$tc('sw6oidc.passkeySettings.registerNicknamePrompt')) || null;

                const verifyResponse = await this.sw6oidcApiFetch('/api/sw6oidc/admin/passkey/registration-verify', {
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

        sw6oidcApiFetch(path, bodyFields) {
            return fetch(path, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    Authorization: `Bearer ${this.loginService.getToken()}`,
                },
                body: new URLSearchParams(
                    Object.fromEntries(Object.entries(bodyFields).filter(([, value]) => value !== null && value !== undefined)),
                ),
            });
        },
    },
}));

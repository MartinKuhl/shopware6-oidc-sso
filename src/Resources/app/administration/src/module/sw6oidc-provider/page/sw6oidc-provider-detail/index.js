import template from './sw6oidc-provider-detail.html.twig';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

/**
 * VERIFICATION NEEDED: the client-side association-collection API used here
 * (`this.provider.attributeMappings.repository.create()` / `.add()` / `.remove()`)
 * matches Shopware's documented pattern for editing nested one-to-many
 * associations in the Administration, but confirm the exact shape against the
 * installed Shopware 6.7 Administration core before relying on it — see the
 * plan's Verification section.
 */
/**
 * Registered as a lazy factory (matching how Shopware's own core components
 * are registered), not a plain object, so that Mixin.getByName('notification')
 * below is only evaluated once Shopware actually builds this component -
 * never during this plugin's forced-early script execution on the login
 * screen (see Resources/views/administration/index.html.twig), where the
 * "notification" mixin isn't registered yet and this component is never
 * rendered anyway.
 */
Component.register('sw6oidc-provider-detail', () => Promise.resolve({
    template,

    inject: ['repositoryFactory'],

    mixins: [Mixin.getByName('notification')],

    data() {
        return {
            provider: null,
            isLoading: true,
            isSaveSuccessful: false,
        };
    },

    metaInfo() {
        return {
            title: this.$createTitle(this.identifier),
        };
    },

    computed: {
        identifier() {
            return this.provider ? (this.provider.displayName || this.provider.appName) : '';
        },

        providerRepository() {
            return this.repositoryFactory.create('sw6oidc_provider');
        },

        attributeTypeOptions() {
            return [
                'email', 'username', 'firstname', 'lastname', 'birthday', 'gender', 'phone',
                'billing_street', 'billing_zipcode', 'billing_city', 'billing_state', 'billing_country', 'billing_phone',
                'shipping_street', 'shipping_zipcode', 'shipping_city', 'shipping_state', 'shipping_country', 'shipping_phone',
            ].map((value) => ({ value, label: this.$tc(`sw6oidc.provider.detail.attributeType.${value}`) }));
        },

        mappingTypeOptions() {
            return ['admin_role', 'customer_group'].map((value) => ({
                value,
                label: this.$tc(`sw6oidc.provider.detail.mappingType.${value}`),
            }));
        },

        loginTypeOptions() {
            return ['both', 'customer', 'admin'].map((value) => ({
                value,
                label: this.$tc(`sw6oidc.provider.detail.loginType.${value}`),
            }));
        },

        pkceFlowOptions() {
            return ['S256', 'plain'].map((value) => ({ value, label: value }));
        },

        claimEncodingOptions() {
            return ['none', 'base64'].map((value) => ({ value, label: value }));
        },
    },

    created() {
        this.createdComponent();
    },

    methods: {
        createdComponent() {
            if (this.$route.params.id) {
                this.loadEntity(this.$route.params.id);

                return;
            }

            this.provider = this.providerRepository.create(Shopware.Context.api);
            this.provider.scope = 'openid profile email';
            this.provider.pkceFlow = 'S256';
            this.provider.claimEncoding = 'none';
            this.provider.groupAttribute = 'groups';
            this.provider.loginType = 'both';
            this.provider.isActive = true;
            this.provider.autoCreateCustomer = true;
            this.provider.httpTimeout = 30;
            this.provider.jwksCacheTtl = 86400;
            this.isLoading = false;
        },

        loadEntity(id) {
            this.isLoading = true;
            const criteria = new Criteria();
            criteria.addAssociation('attributeMappings');
            criteria.addAssociation('roleMappings');

            return this.providerRepository.get(id, Shopware.Context.api, criteria).then((entity) => {
                this.provider = entity;
                this.isLoading = false;
            });
        },

        onClickSave() {
            this.isLoading = true;
            this.isSaveSuccessful = false;

            return this.providerRepository.save(this.provider, Shopware.Context.api).then(() => {
                this.isSaveSuccessful = true;
                this.isLoading = false;

                if (this.$route.params.id === undefined) {
                    this.$router.push({ name: 'sw6oidc.provider.detail', params: { id: this.provider.id } });

                    return;
                }

                this.loadEntity(this.provider.id);
            }).catch(() => {
                this.isLoading = false;
                this.createNotificationError({
                    message: this.$tc('sw6oidc.provider.detail.saveError'),
                });
            });
        },

        onAddAttributeMapping() {
            const mapping = this.provider.attributeMappings.repository.create(Shopware.Context.api);
            mapping.providerId = this.provider.id;
            mapping.attributeType = 'email';
            mapping.attributeName = '';
            mapping.syncOnSso = false;
            this.provider.attributeMappings.add(mapping);
        },

        onRemoveAttributeMapping(item) {
            this.provider.attributeMappings.remove(item.id);
        },

        onAddRoleMapping() {
            const mapping = this.provider.roleMappings.repository.create(Shopware.Context.api);
            mapping.providerId = this.provider.id;
            mapping.mappingType = 'customer_group';
            mapping.oidcGroup = '';
            mapping.aclRoleId = null;
            mapping.customerGroupId = null;
            mapping.sortOrder = this.provider.roleMappings.length;
            this.provider.roleMappings.add(mapping);
        },

        onRemoveRoleMapping(item) {
            this.provider.roleMappings.remove(item.id);
        },

        /**
         * Clears whichever target FK no longer applies when a row switches
         * between "Administration role" and "Customer group" — otherwise a
         * stale aclRoleId/customerGroupId from before the switch would still
         * get saved even though its picker is no longer shown.
         */
        onMappingTypeChange(item) {
            if (item.mappingType === 'admin_role') {
                item.customerGroupId = null;
            } else {
                item.aclRoleId = null;
            }
        },
    },
}));

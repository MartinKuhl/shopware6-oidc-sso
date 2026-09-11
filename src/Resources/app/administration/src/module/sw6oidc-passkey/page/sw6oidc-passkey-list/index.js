import template from './sw6oidc-passkey-list.html.twig';

const { Component } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('sw6oidc-passkey-list', {
    template,

    inject: ['repositoryFactory', 'acl'],

    data() {
        return {
            credentials: null,
            isLoading: true,
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

        columns() {
            return [
                { property: 'userType', label: this.$tc('sw6oidc.passkeySettings.list.columnUserType') },
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

            return this.credentialRepository.search(criteria, Shopware.Context.api).then((result) => {
                this.credentials = result;
                this.isLoading = false;
            });
        },
    },
});

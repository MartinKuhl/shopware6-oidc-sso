import template from './sw6oidc-provider-list.html.twig';

const { Component } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('sw6oidc-provider-list', {
    template,

    inject: ['repositoryFactory', 'acl'],

    data() {
        return {
            providers: null,
            isLoading: true,
            sortBy: 'sortOrder',
            sortDirection: 'ASC',
        };
    },

    metaInfo() {
        return {
            title: this.$createTitle(),
        };
    },

    computed: {
        providerRepository() {
            return this.repositoryFactory.create('sw6oidc_provider');
        },

        columns() {
            return [
                { property: 'displayName', label: this.$tc('sw6oidc.provider.list.columnDisplayName') },
                { property: 'appName', label: this.$tc('sw6oidc.provider.list.columnAppName') },
                { property: 'loginType', label: this.$tc('sw6oidc.provider.list.columnLoginType') },
                { property: 'isActive', label: this.$tc('sw6oidc.provider.list.columnActive') },
                { property: 'lastTestStatus', label: this.$tc('sw6oidc.provider.list.columnLastTestStatus') },
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
            criteria.addSorting(Criteria.sort(this.sortBy, this.sortDirection));

            return this.providerRepository.search(criteria, Shopware.Context.api).then((result) => {
                this.providers = result;
                this.isLoading = false;
            });
        },

        onChangeLanguage() {
            this.getList();
        },
    },
});

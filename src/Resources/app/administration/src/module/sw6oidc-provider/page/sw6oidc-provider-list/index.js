import template from './sw6oidc-provider-list.html.twig';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

/**
 * Provider list with working paging/sorting (listing mixin, F-M4) and error
 * handling (F-M5). Registered as a lazy factory so the mixins resolve only
 * when the component is built (never on the pre-auth login screen).
 */
Component.register('sw6oidc-provider-list', () => Promise.resolve({
    template,

    inject: ['repositoryFactory', 'acl'],

    mixins: [
        Mixin.getByName('notification'),
        Mixin.getByName('listing'),
    ],

    data() {
        return {
            providers: null,
            isLoading: true,
            limit: 25,
            sortBy: 'sortOrder',
            sortDirection: 'ASC',
            requestId: 0,
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
                { property: 'displayName', label: this.$tc('sw6oidc.provider.list.columnDisplayName'), routerLink: 'sw6oidc.provider.detail', primary: true },
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
        async getList() {
            const requestId = ++this.requestId;
            this.isLoading = true;

            const criteria = new Criteria(this.page, this.limit);
            criteria.addSorting(Criteria.sort(this.sortBy, this.sortDirection));

            try {
                const result = await this.providerRepository.search(criteria, Shopware.Context.api);

                if (requestId === this.requestId) {
                    this.total = result.total;
                    this.providers = result;
                }
            } catch {
                if (requestId === this.requestId) {
                    this.createNotificationError({ message: this.$tc('sw6oidc.provider.list.loadError') });
                }
            } finally {
                if (requestId === this.requestId) {
                    this.isLoading = false;
                }
            }
        },
    },
}));

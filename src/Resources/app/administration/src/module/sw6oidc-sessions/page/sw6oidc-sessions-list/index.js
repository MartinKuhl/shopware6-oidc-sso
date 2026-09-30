import template from './sw6oidc-sessions-list.html.twig';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

/**
 * Lists sw6oidc_session_activity rows, newest first, with an "only active"
 * filter and a "Force logout" action (SessionActivityController). Owner names
 * are resolved with extra lookups, like sw6oidc-passkey-list: the activity
 * row only has a polymorphic userType/userId pair, no real association.
 *
 * Registered as a lazy factory so Mixin.getByName('notification') is only
 * evaluated once Shopware builds the component (see sw6oidc-passkey-list).
 */
Component.register('sw6oidc-sessions-list', () => Promise.resolve({
    template,

    inject: ['repositoryFactory', 'acl', 'loginService'],

    mixins: [Mixin.getByName('notification')],

    data() {
        return {
            activities: null,
            isLoading: true,
            page: 1,
            limit: 25,
            onlyActive: false,
            ownerNames: {},
            forceLogoutPendingId: null,
        };
    },

    metaInfo() {
        return {
            title: this.$createTitle(),
        };
    },

    computed: {
        activityRepository() {
            return this.repositoryFactory.create('sw6oidc_session_activity');
        },

        userRepository() {
            return this.repositoryFactory.create('user');
        },

        customerRepository() {
            return this.repositoryFactory.create('customer');
        },

        canForceLogout() {
            return this.acl.can('sw6oidc_session_activity.editor');
        },

        columns() {
            return [
                { property: 'loggedInAt', label: this.$tc('sw6oidc.sessions.list.columnLoggedInAt') },
                { property: 'userType', label: this.$tc('sw6oidc.sessions.list.columnUserType') },
                { property: 'owner', label: this.$tc('sw6oidc.sessions.list.columnOwner') },
                { property: 'loginMethod', label: this.$tc('sw6oidc.sessions.list.columnLoginMethod') },
                { property: 'provider', label: this.$tc('sw6oidc.sessions.list.columnProvider') },
                { property: 'ipAddress', label: this.$tc('sw6oidc.sessions.list.columnIpAddress') },
                { property: 'loggedOutAt', label: this.$tc('sw6oidc.sessions.list.columnLoggedOutAt') },
                { property: 'logoutReason', label: this.$tc('sw6oidc.sessions.list.columnLogoutReason') },
            ];
        },
    },

    watch: {
        onlyActive() {
            this.page = 1;
            this.getList();
        },
    },

    created() {
        this.getList();
    },

    methods: {
        getList() {
            this.isLoading = true;
            const criteria = new Criteria(this.page, this.limit);
            criteria.addAssociation('provider');
            criteria.addSorting(Criteria.sort('loggedInAt', 'DESC'));

            if (this.onlyActive) {
                criteria.addFilter(Criteria.equals('loggedOutAt', null));
            }

            return this.activityRepository.search(criteria, Shopware.Context.api).then(async (result) => {
                this.activities = result;
                await this.loadOwnerNames(result);
                this.isLoading = false;
            }).catch(() => {
                this.isLoading = false;
            });
        },

        onPageChange({ page, limit }) {
            this.page = page;
            this.limit = limit;
            this.getList();
        },

        async loadOwnerNames(activities) {
            const idsOf = (type) => [...new Set(activities.filter((activity) => activity.userType === type).map((activity) => activity.userId))];
            const adminIds = idsOf('admin');
            const customerIds = idsOf('customer');

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

        providerName(item) {
            return item.provider ? (item.provider.displayName || item.provider.appName) : '—';
        },

        formatDate(value) {
            return value ? Shopware.Utils.format.date(value) : '—';
        },

        translated(prefix, value) {
            return value ? this.$tc(`sw6oidc.sessions.${prefix}.${value}`) : '—';
        },

        async onForceLogout(item) {
            if (!window.confirm(this.$tc(item.userType === 'admin' ? 'sw6oidc.sessions.forceLogoutConfirmAdmin' : 'sw6oidc.sessions.forceLogoutConfirm'))) {
                return;
            }

            this.forceLogoutPendingId = item.id;

            try {
                const response = await fetch(`/api/_action/sw6oidc/session-activity/${encodeURIComponent(item.id)}/force-logout`, {
                    method: 'POST',
                    headers: { Authorization: `Bearer ${this.loginService.getToken()}` },
                });

                if (!response.ok) {
                    throw new Error(`Force logout failed with status ${response.status}`);
                }

                const { endedAllSessions } = await response.json();

                this.createNotificationSuccess({
                    message: this.$tc(endedAllSessions ? 'sw6oidc.sessions.forceLogoutSuccessAll' : 'sw6oidc.sessions.forceLogoutSuccess'),
                });
                await this.getList();
            } catch (exception) {
                // eslint-disable-next-line no-console
                console.error('sw6oidc: force logout failed', exception);
                this.createNotificationError({ message: this.$tc('sw6oidc.sessions.forceLogoutError') });
            } finally {
                this.forceLogoutPendingId = null;
            }
        },
    },
}));

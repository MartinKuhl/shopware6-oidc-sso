import template from './sw6oidc-sessions-list.html.twig';
import './sw6oidc-sessions-list.scss';

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

    inject: ['repositoryFactory', 'acl', 'sw6oidcApiService'],

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
            confirmItem: null,
            sessionLifetimeSeconds: {},
            requestId: 0,
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
            return this.acl.can('sw6oidc_session_activity.force_logout');
        },

        columns() {
            return [
                // Always newest first; the listing can't re-sort (F-N11).
                { property: 'loggedInAt', label: this.$tc('sw6oidc.sessions.list.columnLoggedInAt'), sortable: false },
                { property: 'userType', label: this.$tc('sw6oidc.sessions.list.columnUserType'), sortable: false },
                { property: 'owner', label: this.$tc('sw6oidc.sessions.list.columnOwner'), sortable: false },
                { property: 'loginMethod', label: this.$tc('sw6oidc.sessions.list.columnLoginMethod'), sortable: false },
                { property: 'provider', label: this.$tc('sw6oidc.sessions.list.columnProvider'), sortable: false },
                { property: 'ipAddress', label: this.$tc('sw6oidc.sessions.list.columnIpAddress'), sortable: false },
                { property: 'loggedOutAt', label: this.$tc('sw6oidc.sessions.list.columnLoggedOutAt'), sortable: false },
                { property: 'logoutReason', label: this.$tc('sw6oidc.sessions.list.columnLogoutReason'), sortable: false },
            ];
        },

        confirmText() {
            if (!this.confirmItem) {
                return '';
            }

            // Only a registered customer OIDC session is ended exactly; passkey
            // logins and admins lose all their sessions (F-N12).
            const endsAll = this.confirmItem.userType === 'admin' || this.confirmItem.loginMethod === 'passkey';

            return this.$tc(endsAll ? 'sw6oidc.sessions.forceLogoutConfirmAll' : 'sw6oidc.sessions.forceLogoutConfirm');
        },
    },

    watch: {
        onlyActive() {
            this.page = 1;
            this.getList();
        },
    },

    created() {
        this.loadSettings();
        this.getList();
    },

    methods: {
        async loadSettings() {
            try {
                const { sessionLifetimeSeconds } = await this.sw6oidcApiService.get('_action/sw6oidc/session-activity/settings');
                this.sessionLifetimeSeconds = sessionLifetimeSeconds ?? {};
            } catch {
                this.sessionLifetimeSeconds = {};
            }
        },

        async getList() {
            // Only the newest request may update the list (F-N11).
            const requestId = ++this.requestId;
            this.isLoading = true;

            const criteria = new Criteria(this.page, this.limit);
            criteria.addAssociation('provider');
            criteria.addSorting(Criteria.sort('loggedInAt', 'DESC'));

            if (this.onlyActive) {
                criteria.addFilter(Criteria.equals('loggedOutAt', null));
            }

            try {
                const result = await this.activityRepository.search(criteria, Shopware.Context.api);
                await this.loadOwnerNames(result);

                if (requestId === this.requestId) {
                    this.activities = result;
                }
            } catch (exception) {
                if (requestId === this.requestId) {
                    this.createNotificationError({ message: this.$tc('sw6oidc.sessions.loadError') });
                }
            } finally {
                if (requestId === this.requestId) {
                    this.isLoading = false;
                }
            }
        },

        /**
         * 'active' | 'expired' (no logout recorded, but older than the
         * session lifetime) | 'ended' — a missing logout is not proof the
         * session still exists (F-N3).
         */
        sessionState(item) {
            if (item.loggedOutAt) {
                return 'ended';
            }

            const lifetime = this.sessionLifetimeSeconds[item.userType];

            if (!lifetime || !item.loggedInAt) {
                return 'active';
            }

            return Date.now() - new Date(item.loggedInAt).getTime() > lifetime * 1000 ? 'expired' : 'active';
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

        onForceLogout(item) {
            this.confirmItem = item;
        },

        onCancelForceLogout() {
            this.confirmItem = null;
        },

        async onConfirmForceLogout() {
            const item = this.confirmItem;
            this.confirmItem = null;

            if (!item) {
                return;
            }

            this.forceLogoutPendingId = item.id;

            try {
                const { endedAllSessions } = await this.sw6oidcApiService.post(
                    `_action/sw6oidc/session-activity/${encodeURIComponent(item.id)}/force-logout`,
                );

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

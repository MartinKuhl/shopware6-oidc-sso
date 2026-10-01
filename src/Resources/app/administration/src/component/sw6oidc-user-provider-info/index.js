import template from './sw6oidc-user-provider-info.html.twig';
import './sw6oidc-user-provider-info.scss';
import { fetchUserProviderBindings, unlinkUserProvider } from '../../service/user-provider-api';

const { Component, Mixin } = Shopware;

/**
 * "OIDC Provider: <name> (<bound at>)" or "none", plus an "Unlink IdP"
 * button, shown on the admin user and customer detail pages.
 *
 * Registered as a lazy factory like sw6oidc-profile-passkey: main.js is also
 * force-loaded on the pre-auth login screen, where the "notification" mixin
 * isn't registered yet.
 */
Component.register('sw6oidc-user-provider-info', () => Promise.resolve({
    template,

    inject: ['acl'],

    mixins: [Mixin.getByName('notification')],

    props: {
        userType: {
            type: String,
            required: true,
            validator: (value) => ['admin', 'customer'].includes(value),
        },
        userId: {
            type: String,
            required: true,
        },
        allowUnlink: {
            type: Boolean,
            default: true,
        },
    },

    data() {
        return {
            binding: null,
            isLoading: true,
            isUnlinking: false,
            showUnlinkConfirm: false,
        };
    },

    computed: {
        canUnlink() {
            return this.allowUnlink && this.acl.can(this.userType === 'admin' ? 'users_and_permissions.editor' : 'customer.editor');
        },

        unlinkConfirmText() {
            return this.$tc(`sw6oidc.userProvider.${this.userType === 'admin' ? 'unlinkConfirmAdmin' : 'unlinkConfirmCustomer'}`);
        },

        formattedCreatedAt() {
            return this.binding?.createdAt ? Shopware.Utils.format.date(this.binding.createdAt) : '';
        },
    },

    watch: {
        userId: {
            immediate: true,
            handler() {
                this.loadBinding();
            },
        },
    },

    methods: {
        async loadBinding() {
            const userId = this.userId;
            this.isLoading = true;

            try {
                const bindings = await fetchUserProviderBindings(this.userType, [userId]);

                // A response for a previous userId must not overwrite the current one (F-N17).
                if (userId === this.userId) {
                    this.binding = bindings[userId] ?? null;
                }
            } catch (exception) {
                // eslint-disable-next-line no-console
                console.error('sw6oidc: failed to load OIDC provider binding', exception);

                if (userId === this.userId) {
                    this.binding = null;
                }
            } finally {
                if (userId === this.userId) {
                    this.isLoading = false;
                }
            }
        },

        onUnlink() {
            this.showUnlinkConfirm = true;
        },

        onCancelUnlink() {
            this.showUnlinkConfirm = false;
        },

        async onConfirmUnlink() {
            this.showUnlinkConfirm = false;
            this.isUnlinking = true;

            try {
                await unlinkUserProvider(this.userType, this.userId);
                this.binding = null;
                this.createNotificationSuccess({ message: this.$tc('sw6oidc.userProvider.unlinkSuccess') });
                this.$emit('unlinked');
            } catch (exception) {
                // eslint-disable-next-line no-console
                console.error('sw6oidc: failed to unlink OIDC provider', exception);
                this.createNotificationError({ message: this.$tc('sw6oidc.userProvider.unlinkError') });
            } finally {
                this.isUnlinking = false;
            }
        },
    },
}));

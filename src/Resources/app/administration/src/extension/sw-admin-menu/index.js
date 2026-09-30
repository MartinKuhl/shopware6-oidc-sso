const { Component } = Shopware;

/**
 * RP-Initiated Logout for Administration users: core's user-menu logout
 * (loginService.logoutSso()) is purely client-side, so an admin who logged in
 * via OIDC would keep their IdP session and be signed straight back in by the
 * next "Login with SSO". Before the normal logout, ask the plugin for the
 * IdP's logout URL; if there is one, do the same local cleanup core does and
 * navigate there instead of to #/login. Any failure falls back to core's
 * stock logout. Inactivity logouts don't pass through here and stay local.
 */
Component.override('sw-admin-menu', {
    methods: {
        async onLogoutUser() {
            const token = this.loginService.getToken();
            const logoutUrl = await this.sw6oidcFetchLogoutUrl(token);

            if (!logoutUrl) {
                await this.$super('onLogoutUser');
                return;
            }

            try {
                // Same best-effort server-side revocation core's logoutSso() does,
                // via native fetch to bypass the refresh-token interceptor.
                await fetch('/api/_action/user/logout', {
                    method: 'POST',
                    headers: { Authorization: `Bearer ${token}` },
                });
            } catch {
                // Best-effort: continue even if server-side revocation fails
            }

            this.loginService.logout(false, false);
            // logout() pushes #/login and flags it to reload itself on mount;
            // that reload would cancel the navigation to the IdP below.
            sessionStorage.removeItem('refresh-after-logout');

            this.adminMenuStore.clearExpandedMenuEntries();
            Shopware.Store.get('session').removeCurrentUser();
            Shopware.Store.get('notification').clearGrowlNotificationsForCurrentUser();
            Shopware.Store.get('notification').clearNotificationsForCurrentUser();

            window.location.href = logoutUrl;
        },

        async sw6oidcFetchLogoutUrl(token) {
            if (!token) {
                return null;
            }

            try {
                const response = await fetch('/api/sw6oidc/admin/logout', {
                    method: 'POST',
                    headers: { Authorization: `Bearer ${token}` },
                });

                if (!response.ok) {
                    return null;
                }

                const { logoutUrl } = await response.json();

                return typeof logoutUrl === 'string' && logoutUrl !== '' ? logoutUrl : null;
            } catch (exception) {
                // eslint-disable-next-line no-console
                console.error('sw6oidc: failed to resolve admin IdP logout URL', exception);
                return null;
            }
        },
    },
});

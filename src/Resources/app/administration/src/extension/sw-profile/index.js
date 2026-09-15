import tabTemplate from './sw-profile-index.html.twig';
import './page/sw6oidc-profile-passkey';

const { Component, Module } = Shopware;

/**
 * Adds a "Passkeys" tab to the admin's own profile page ("Mein Profil") for
 * self-service register/delete of that admin's own credentials - mirrors the
 * Magento reference module's own-account passkey management, and is kept
 * entirely separate from the Settings > Passkey-Einstellungen grid (that one
 * is the cross-admin lockout-recovery tool; this is "manage my own").
 *
 * sw-profile-index's tab bar is plain, hardcoded <sw-tabs-item> markup in
 * the exact v6.7.14.0 source this plugin targets (not the data-driven
 * mt-tabs used from 6.8 onward behind a feature flag) - appending a sibling
 * item right after the existing "General" tab's own named block is the
 * reliable way to inject into the middle of that markup via twig block
 * overriding, since block substitution happens on the raw template string
 * before Vue ever compiles it into a render tree.
 */
Component.override('sw-profile-index', {
    template: tabTemplate,
});

/**
 * routeMiddleware is Shopware's supported mechanism for adding a new child
 * route into an EXISTING module's route tree instead of creating a new
 * top-level menu entry - confirmed against module.factory.ts's
 * registerModule()/getModuleRoutes(): every module's already-built routes
 * (children already flattened to arrays, names/paths already absolute) are
 * passed through every registered routeMiddleware exactly once, so pushing
 * onto currentRoute.children here is enough; no routes manifest is needed
 * for this module at all.
 */
Module.register('sw6oidc-profile-passkey', {
    type: 'plugin',

    routeMiddleware(next, currentRoute) {
        if (currentRoute.name === 'sw.profile.index') {
            currentRoute.children.push({
                name: 'sw.profile.index.sw6oidcPasskey',
                path: `${currentRoute.path}/sw6oidc-passkey`,
                component: 'sw6oidc-profile-passkey',
                meta: {
                    parentPath: 'sw.profile.index',
                    privilege: 'user.update_profile',
                },
            });
        }

        next(currentRoute);
    },
});

/**
 * sw-profile-index's onSave() calls `ssoSettingsService.isSso()` and, only
 * when that resolves true, skips Shopware's "confirm your password" modal
 * and saves directly - but that flag is Shopware's own shop-wide native-SSO
 * toggle (Framework/Sso/LoginConfigService), unrelated to whether *this*
 * particular admin actually logged in via OIDC/Passkey. An account this
 * plugin provisions never has a usable password
 * (AdminProvisioningService::create() sets a random, permanently unknown
 * one), so without this decorator that modal can never be completed and
 * OIDC/Passkey admins are locked out of editing their own profile (name,
 * avatar, locale, timezone, ...) - including every superadmin created via
 * `allow_superadmin_group_mapping`, since superadmin bypasses ACL and hits
 * Shopware's server-side `user:editor` scope check too.
 *
 * Decorating isSso() - rather than overriding onSave()/saveUser() directly -
 * means the rest of Shopware's own save flow (validation, error handling,
 * that server-side scope check) runs completely untouched, exactly as it
 * does today for a shop-wide native-SSO admin; only the "does this session
 * need password reconfirmation" signal changes, and only for accounts this
 * plugin actually provisioned. OidcAdminAuthController::verifySession()
 * enforces that server-side (403s for a normal local-password admin), so
 * this falls straight through to Shopware's real isSso() check for them.
 */
Shopware.Application.addServiceProviderDecorator('ssoSettingsService', (ssoSettingsService) => ({
    ...ssoSettingsService,

    async isSso(...args) {
        if (await sw6oidcVerifyCurrentSession()) {
            return { isSso: true };
        }

        return ssoSettingsService.isSso(...args);
    },
}));

/**
 * Mints a fresh access/refresh token pair carrying the `user-verified` OAuth
 * scope for the current admin, without a password, and installs it as the
 * active session token - mirrors what Shopware's own real password-confirm
 * flow does after a successful check (loginService.setBearerAuthentication()
 * with a freshly re-scoped token), just sourced from our own endpoint
 * instead of a real password grant. Any failure (network error, or a 403
 * because this account isn't actually OIDC/Passkey-provisioned) resolves to
 * false rather than throwing, so the caller always falls back to Shopware's
 * normal password-confirmation flow.
 */
async function sw6oidcVerifyCurrentSession() {
    try {
        const token = Shopware.Service('loginService').getToken();

        if (!token) {
            return false;
        }

        const response = await fetch('/api/sw6oidc/admin/verify-session', {
            method: 'POST',
            headers: { Authorization: `Bearer ${token}` },
        });

        if (!response.ok) {
            return false;
        }

        const tokenData = await response.json();

        Shopware.Service('loginService').setBearerAuthentication({
            access: tokenData.access_token,
            refresh: tokenData.refresh_token,
            expiry: tokenData.expires_in,
        });

        return true;
    } catch (exception) {
        // eslint-disable-next-line no-console
        console.error('sw6oidc: admin session verification failed', exception);
        return false;
    }
}

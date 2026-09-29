import template from './sw-sso-users-permission-user-detail.html.twig';

/**
 * "Single Sign-On (OIDC)" card (OIDC Provider + "Unlink IdP") for the
 * user.sso.detail route Shopware uses instead of sw-users-permissions-user-detail
 * when its native SSO mode is active (a separate component, not an extension
 * of the regular one). Same separate-card layout as the profile page; the
 * template re-declares the core content block because there is no block
 * between the page's cards - see the note in the twig file.
 */
Shopware.Component.override('sw-sso-users-permission-user-detail', {
    template,
});

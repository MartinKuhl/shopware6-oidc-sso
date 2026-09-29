import template from './sw-users-permissions-user-detail.html.twig';

/**
 * Read-only "OIDC Provider" row (+ "Unlink IdP") in the user detail's
 * basic information card - mirrors the Magento reference module's
 * OidcUserInfoPlugin on the admin user edit form.
 */
Shopware.Component.override('sw-users-permissions-user-detail', {
    template,
});

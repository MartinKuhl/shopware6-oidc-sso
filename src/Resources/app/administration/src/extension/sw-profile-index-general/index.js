import template from './sw-profile-index-general.html.twig';

/**
 * Shows the logged-in admin their own "OIDC Provider" binding on
 * "Mein Profil" > General. Read-only: unlinking stays an action on the user
 * detail page, for admins holding users_and_permissions.editor.
 *
 * Rendered as its own card before the image card rather than inside the
 * profile information card: that card's email/timezone row is an
 * auto-fit sw-container with no wrapping block, and a full-width item in it
 * keeps the empty auto-fit tracks from collapsing.
 */
Shopware.Component.override('sw-profile-index-general', {
    template,
});

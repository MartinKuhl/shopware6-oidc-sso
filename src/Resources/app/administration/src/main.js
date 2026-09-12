/**
 * This entry is now loaded twice in a single browser session: once via a
 * forced <script> tag injected into the pre-auth login page (see
 * Resources/views/administration/index.html.twig - needed because Shopware's
 * boot process never runs loadPlugins() on the login screen), and again via
 * the normal loadPlugins() mechanism after the full-page reload Shopware
 * itself triggers on successful login. Guard against registering everything
 * twice (Component.register/override, Module.register all warn or misbehave
 * on a duplicate name) - dynamic import() rather than static import since a
 * static import is hoisted and always runs regardless of the surrounding if.
 */
if (!window.__sw6oidcLoaded) {
    window.__sw6oidcLoaded = true;

    Promise.all([
        import('./component/sw6oidc-rp-id-field'),
        import('./extension/sw-login'),
        import('./module/sw6oidc-provider'),
        import('./module/sw6oidc-passkey'),
    ]).catch((exception) => {
        // eslint-disable-next-line no-console
        console.error('sw6oidc: Administration extension failed to load', exception);
    });
}

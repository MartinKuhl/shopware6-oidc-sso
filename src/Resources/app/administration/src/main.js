/**
 * This entry is now referenced twice in a single browser session: once via
 * a forced <script> tag injected into the pre-auth login page (see
 * Resources/views/administration/index.html.twig - needed because
 * Shopware's boot process never runs loadPlugins() on the login screen),
 * and again via the normal loadPlugins() mechanism after the full-page
 * reload Shopware itself triggers on successful login. That's not actually
 * a double *load*, though - both reference the exact same built file URL,
 * and the browser's native ES module cache evaluates any given module URL
 * exactly once no matter how many times it's imported, so plain static
 * imports here are correct as-is. (An earlier attempt at guarding this with
 * dynamic import() introduced a real regression instead: it made every
 * registration below asynchronous, so on ordinary post-login pages the
 * custom config.xml component and the Settings > Plugins entries could
 * render before their dynamic imports had resolved.)
 */
import './component/sw6oidc-rp-id-field';
import './extension/sw-login';
import './extension/sw-profile';
import './module/sw6oidc-provider';
import './module/sw6oidc-passkey';

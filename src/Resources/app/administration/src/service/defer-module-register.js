/**
 * Module.register() with a `settingsItem` array touches a Pinia
 * "settingsItems" store synchronously and throws "Store with id
 * \"settingsItems\" not found" if that store doesn't exist yet. It doesn't
 * during this plugin's forced-early script execution on the login screen
 * (see Resources/views/administration/index.html.twig): that store is only
 * created once Shopware's own Application.start() actually runs, which
 * hasn't happened yet at the point our forced-early script's top-level code
 * runs. Retry until the store exists rather than guessing a fixed delay -
 * on an ordinary post-login page load this resolves on the very first
 * attempt.
 */
export function registerModuleWhenReady(name, config, attemptsLeft = 100) {
    try {
        Shopware.State.get('settingsItems');
    } catch (exception) {
        if (attemptsLeft <= 0) {
            // eslint-disable-next-line no-console
            console.error(`sw6oidc: settingsItems store never became ready, "${name}" module not registered`, exception);
            return;
        }

        setTimeout(() => registerModuleWhenReady(name, config, attemptsLeft - 1), 50);
        return;
    }

    Shopware.Module.register(name, config);
}

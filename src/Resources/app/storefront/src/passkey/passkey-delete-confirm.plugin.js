import Plugin from 'src/plugin-system/plugin.class';

/**
 * Confirms before submitting a passkey delete form - a plain
 * PluginManager-registered listener rather than an inline `onclick`
 * attribute, consistent with how every other interaction in this plugin's
 * Storefront JS works (and safer under CSPs that disallow inline handlers).
 */
export default class Sw6OidcPasskeyDeleteConfirmPlugin extends Plugin {
    init() {
        this.el.addEventListener('submit', this.onSubmit.bind(this));
    }

    onSubmit(event) {
        // eslint-disable-next-line no-alert
        if (!window.confirm(this.el.dataset.sw6oidcConfirmText)) {
            event.preventDefault();
        }
    }
}

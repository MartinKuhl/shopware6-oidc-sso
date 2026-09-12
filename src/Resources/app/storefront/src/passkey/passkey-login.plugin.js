import Plugin from 'src/plugin-system/plugin.class';
import {
    preparePublicKeyRequestOptions,
    serializeAssertionCredential,
} from './webauthn-codec';

/**
 * Usernameless/discoverable Passkey login on the Storefront login page. Calls
 * this plugin's own login-options/login-verify endpoints
 * (Storefront/Controller/PasskeyController.php); on success, reloads so the
 * server-set sw-context-token cookie takes effect, matching the "hard
 * redirect" mental model a real login always has.
 */
export default class Sw6OidcPasskeyLoginPlugin extends Plugin {
    init() {
        this.el.addEventListener('click', this.login.bind(this));
    }

    async login() {
        if (!window.PublicKeyCredential) {
            return;
        }

        this.el.disabled = true;

        try {
            const optionsResponse = await fetch('/sw6oidc/passkey/login-options', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            });
            const { sessionId, options } = await optionsResponse.json();

            const assertion = await navigator.credentials.get({
                publicKey: preparePublicKeyRequestOptions(options),
            });

            const verifyResponse = await fetch('/sw6oidc/passkey/login-verify', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    sessionId,
                    credential: JSON.stringify(serializeAssertionCredential(assertion)),
                }),
            });
            const result = await verifyResponse.json();

            if (result.status) {
                window.location.reload();

                return;
            }

            this.showError();
        } catch (error) {
            // eslint-disable-next-line no-console
            console.error('sw6oidc: passkey login failed', error);
            this.showError();
        } finally {
            this.el.disabled = false;
        }
    }

    showError() {
        // A dedicated flash-message partial is a follow-up; a console error
        // plus leaving the button re-enabled is the safe minimum for now.
    }
}

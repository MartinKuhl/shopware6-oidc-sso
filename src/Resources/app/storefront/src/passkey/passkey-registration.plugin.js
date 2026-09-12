import Plugin from 'src/plugin-system/plugin.class';
import {
    preparePublicKeyCreationOptions,
    serializeAttestationCredential,
} from './webauthn-codec';

/**
 * Self-service Passkey registration under My Account > Passkeys - calls this
 * plugin's own registration-options/registration-verify endpoints
 * (Storefront/Controller/PasskeyController.php), reloading on success so the
 * newly registered credential shows up in the server-rendered list below
 * (AccountPasskeyController renders that list itself, no client-side
 * rendering here).
 */
export default class Sw6OidcPasskeyRegistrationPlugin extends Plugin {
    init() {
        this.el.addEventListener('click', this.register.bind(this));
    }

    async register() {
        if (!window.PublicKeyCredential) {
            // eslint-disable-next-line no-alert
            window.alert(this.el.dataset.sw6oidcNoSupportText);
            return;
        }

        this.el.disabled = true;

        try {
            const optionsResponse = await fetch('/sw6oidc/passkey/registration-options', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            });

            if (!optionsResponse.ok) {
                throw new Error(`Registration options request failed with status ${optionsResponse.status}`);
            }

            const { sessionId, options } = await optionsResponse.json();

            const credential = await navigator.credentials.create({
                publicKey: preparePublicKeyCreationOptions(options),
            });

            // eslint-disable-next-line no-alert
            const nickname = window.prompt(this.el.dataset.sw6oidcNicknamePromptText) || null;

            const verifyResponse = await fetch('/sw6oidc/passkey/registration-verify', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    sessionId,
                    credential: JSON.stringify(serializeAttestationCredential(credential)),
                    ...(nickname ? { nickname } : {}),
                }),
            });

            const result = await verifyResponse.json();

            if (!verifyResponse.ok || !result.status) {
                throw new Error(result.message || `Registration failed with status ${verifyResponse.status}`);
            }

            window.location.reload();
        } catch (error) {
            // eslint-disable-next-line no-console
            console.error('sw6oidc: passkey registration failed', error);
            // eslint-disable-next-line no-alert
            window.alert(this.el.dataset.sw6oidcErrorText);
        } finally {
            this.el.disabled = false;
        }
    }
}

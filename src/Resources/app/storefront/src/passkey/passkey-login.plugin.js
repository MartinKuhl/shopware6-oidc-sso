import Plugin from 'src/plugin-system/plugin.class';

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

            const publicKey = {
                ...options,
                challenge: base64UrlToBuffer(options.challenge),
                allowCredentials: (options.allowCredentials || []).map((credential) => ({
                    ...credential,
                    id: base64UrlToBuffer(credential.id),
                })),
            };

            const assertion = await navigator.credentials.get({ publicKey });

            const credentialJson = JSON.stringify({
                id: assertion.id,
                rawId: bufferToBase64Url(assertion.rawId),
                type: assertion.type,
                response: {
                    clientDataJSON: bufferToBase64Url(assertion.response.clientDataJSON),
                    authenticatorData: bufferToBase64Url(assertion.response.authenticatorData),
                    signature: bufferToBase64Url(assertion.response.signature),
                    userHandle: assertion.response.userHandle ? bufferToBase64Url(assertion.response.userHandle) : null,
                },
            });

            const verifyResponse = await fetch('/sw6oidc/passkey/login-verify', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ sessionId, credential: credentialJson }),
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

function base64UrlToBuffer(value) {
    const padded = value.replace(/-/g, '+').replace(/_/g, '/').padEnd(value.length + ((4 - (value.length % 4)) % 4), '=');
    const binary = window.atob(padded);
    const buffer = new Uint8Array(binary.length);

    for (let i = 0; i < binary.length; i += 1) {
        buffer[i] = binary.charCodeAt(i);
    }

    return buffer.buffer;
}

function bufferToBase64Url(buffer) {
    const bytes = new Uint8Array(buffer);
    let binary = '';

    bytes.forEach((byte) => {
        binary += String.fromCharCode(byte);
    });

    return window.btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

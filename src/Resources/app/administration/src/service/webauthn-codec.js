/**
 * Minimal base64url <-> ArrayBuffer helpers for the WebAuthn ceremonies below.
 * `web-auth/webauthn-lib` (the PHP side, via PasskeyRegistrationService /
 * PasskeyAuthenticationService) serializes challenge/id fields as base64url
 * strings in both directions — the browser's `navigator.credentials` API only
 * speaks ArrayBuffer, so every field has to be converted crossing that
 * boundary.
 */

export function base64UrlToBuffer(base64url) {
    const padding = '='.repeat((4 - (base64url.length % 4)) % 4);
    const base64 = (base64url + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = window.atob(base64);
    const buffer = new ArrayBuffer(raw.length);
    const bytes = new Uint8Array(buffer);

    for (let i = 0; i < raw.length; i += 1) {
        bytes[i] = raw.charCodeAt(i);
    }

    return buffer;
}

export function bufferToBase64Url(buffer) {
    const bytes = new Uint8Array(buffer);
    let raw = '';

    for (let i = 0; i < bytes.byteLength; i += 1) {
        raw += String.fromCharCode(bytes[i]);
    }

    return window.btoa(raw).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

export function preparePublicKeyCreationOptions(options) {
    return {
        ...options,
        challenge: base64UrlToBuffer(options.challenge),
        user: {
            ...options.user,
            id: base64UrlToBuffer(options.user.id),
        },
        excludeCredentials: (options.excludeCredentials || []).map((descriptor) => ({
            ...descriptor,
            id: base64UrlToBuffer(descriptor.id),
        })),
    };
}

export function preparePublicKeyRequestOptions(options) {
    return {
        ...options,
        challenge: base64UrlToBuffer(options.challenge),
        allowCredentials: (options.allowCredentials || []).map((descriptor) => ({
            ...descriptor,
            id: base64UrlToBuffer(descriptor.id),
        })),
    };
}

export function serializeAttestationCredential(credential) {
    // `id` is derived from `rawId` with our own encoder rather than trusting
    // credential.id verbatim - webauthn-lib decodes `id` via a stricter path
    // (sodium's no-padding urlsafe variant) than `rawId`, and it requires
    // both to decode to the exact same bytes. Deriving both from the same
    // source with the same encoder guarantees that instead of depending on
    // the browser's own `.id` string formatting exactly matching.
    const rawId = bufferToBase64Url(credential.rawId);

    return {
        id: rawId,
        rawId,
        type: credential.type,
        response: {
            attestationObject: bufferToBase64Url(credential.response.attestationObject),
            clientDataJSON: bufferToBase64Url(credential.response.clientDataJSON),
        },
    };
}

export function serializeAssertionCredential(credential) {
    // See serializeAttestationCredential() above for why `id` is derived from
    // `rawId` instead of read directly off the credential.
    const rawId = bufferToBase64Url(credential.rawId);

    return {
        id: rawId,
        rawId,
        type: credential.type,
        response: {
            authenticatorData: bufferToBase64Url(credential.response.authenticatorData),
            clientDataJSON: bufferToBase64Url(credential.response.clientDataJSON),
            signature: bufferToBase64Url(credential.response.signature),
            userHandle: credential.response.userHandle ? bufferToBase64Url(credential.response.userHandle) : null,
        },
    };
}

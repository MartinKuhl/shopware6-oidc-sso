/**
 * Carries "where was the admin before re-login" across the full-page OIDC
 * round trip (inactivity modal → IdP → /admin#/login?sw6oidc_nonce=…).
 * sessionStorage survives same-tab navigations, and core's own
 * sw-admin-previous-route_<hash> entry is gone by then (or keyed by a hash
 * the login screen doesn't know), so the path is copied under our own key.
 */
const STORAGE_KEY = 'sw6oidc-sso-return-route';
const MAX_AGE_MS = 10 * 60 * 1000;

export function rememberSsoReturnRoute(fullPath) {
    // Only in-app hash-router paths; never an absolute or protocol-relative URL.
    if (typeof fullPath !== 'string' || !fullPath.startsWith('/') || fullPath.startsWith('//')) {
        return;
    }

    try {
        sessionStorage.setItem(STORAGE_KEY, JSON.stringify({ fullPath, at: Date.now() }));
    } catch {
        // Storage unavailable: re-login still works, it just lands on the dashboard.
    }
}

/**
 * @return {string|null} the remembered path (consumed), or null
 */
export function consumeSsoReturnRoute() {
    let entry = null;

    try {
        entry = JSON.parse(sessionStorage.getItem(STORAGE_KEY) || 'null');
        sessionStorage.removeItem(STORAGE_KEY);
    } catch {
        return null;
    }

    if (!entry || typeof entry.fullPath !== 'string' || !entry.fullPath.startsWith('/') || entry.fullPath.startsWith('//')) {
        return null;
    }

    return Date.now() - Number(entry.at) <= MAX_AGE_MS ? entry.fullPath : null;
}

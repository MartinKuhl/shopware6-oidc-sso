/**
 * Thin client for OidcUserProviderAdminController - the "OIDC Provider"
 * binding shown on the admin user and customer pages. Plain fetch() with the
 * current bearer token, form-encoded, matching the other sw6oidc admin calls
 * (see sw6oidc-profile-passkey).
 */
function apiFetch(path, bodyFields) {
    return fetch(path, {
        method: 'POST',
        headers: {
            Authorization: `Bearer ${Shopware.Service('loginService').getToken()}`,
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams(bodyFields),
    });
}

/**
 * @returns {Promise<Object<string, {providerId: string, providerName: ?string, createdAt: ?string}>>}
 *          keyed by user/customer id; unbound ids are absent
 */
export async function fetchUserProviderBindings(userType, userIds) {
    if (!userIds.length) {
        return {};
    }

    const response = await apiFetch('/api/_action/sw6oidc/user-provider/info', {
        userType,
        userIds: userIds.join(','),
    });

    if (!response.ok) {
        throw new Error(`Loading OIDC provider bindings failed with status ${response.status}`);
    }

    const { bindings } = await response.json();

    return bindings;
}

export async function unlinkUserProvider(userType, userId) {
    const response = await apiFetch('/api/_action/sw6oidc/user-provider/unlink', { userType, userId });

    if (!response.ok) {
        throw new Error(`Unlinking OIDC provider failed with status ${response.status}`);
    }
}

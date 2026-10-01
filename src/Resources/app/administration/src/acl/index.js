/**
 * Role privileges for the plugin's modules (F-H5, F-N7): without these
 * mappings the entities' privileges can't be granted to a normal role, so
 * only superadmins could use the modules. Owner names in the passkey and
 * session lists need user/customer read access.
 */
const privileges = Shopware.Service('privileges');

if (privileges) {
    privileges.addPrivilegeMappingEntry({
        category: 'permissions',
        parent: 'settings',
        key: 'sw6oidc_provider',
        roles: {
            viewer: {
                privileges: [
                    'sw6oidc_provider:read',
                    'sw6oidc_attribute_mapping:read',
                    'sw6oidc_role_mapping:read',
                    'sw6oidc_access_control_rule:read',
                    'acl_role:read',
                    'customer_group:read',
                ],
                dependencies: [],
            },
            editor: {
                // Live test, discovery, connection test and lockout
                // confirmation are checked against sw6oidc_provider:update.
                privileges: [
                    'sw6oidc_provider:update',
                    'sw6oidc_attribute_mapping:create',
                    'sw6oidc_attribute_mapping:update',
                    'sw6oidc_attribute_mapping:delete',
                    'sw6oidc_role_mapping:create',
                    'sw6oidc_role_mapping:update',
                    'sw6oidc_role_mapping:delete',
                    'sw6oidc_access_control_rule:create',
                    'sw6oidc_access_control_rule:update',
                    'sw6oidc_access_control_rule:delete',
                ],
                dependencies: ['sw6oidc_provider.viewer'],
            },
            creator: {
                privileges: ['sw6oidc_provider:create'],
                dependencies: ['sw6oidc_provider.viewer', 'sw6oidc_provider.editor'],
            },
            deleter: {
                privileges: [
                    'sw6oidc_provider:delete',
                    'sw6oidc_attribute_mapping:delete',
                    'sw6oidc_role_mapping:delete',
                    'sw6oidc_access_control_rule:delete',
                ],
                dependencies: ['sw6oidc_provider.viewer'],
            },
        },
    });

    privileges.addPrivilegeMappingEntry({
        category: 'permissions',
        parent: 'settings',
        key: 'sw6oidc_passkey_credential',
        roles: {
            viewer: {
                privileges: ['sw6oidc_passkey_credential:read', 'user:read', 'customer:read'],
                dependencies: [],
            },
            deleter: {
                privileges: ['sw6oidc_passkey_credential:delete'],
                dependencies: ['sw6oidc_passkey_credential.viewer'],
            },
        },
    });

    privileges.addPrivilegeMappingEntry({
        category: 'permissions',
        parent: 'settings',
        key: 'sw6oidc_session_activity',
        roles: {
            viewer: {
                privileges: ['sw6oidc_session_activity:read', 'sw6oidc_provider:read', 'user:read', 'customer:read'],
                dependencies: [],
            },
            // Ends a customer's session, or all sessions of an admin.
            force_logout: {
                privileges: ['sw6oidc_session_activity:force_logout'],
                dependencies: ['sw6oidc_session_activity.viewer'],
            },
        },
    });
}

import Sw6OidcPasskeyLoginPlugin from './passkey/passkey-login.plugin';
import Sw6OidcPasskeyRegistrationPlugin from './passkey/passkey-registration.plugin';
import Sw6OidcPasskeyDeleteConfirmPlugin from './passkey/passkey-delete-confirm.plugin';

const PluginManager = window.PluginManager;
PluginManager.register('Sw6OidcPasskeyLogin', Sw6OidcPasskeyLoginPlugin, '[data-sw6oidc-passkey-login]');
PluginManager.register('Sw6OidcPasskeyRegistration', Sw6OidcPasskeyRegistrationPlugin, '[data-sw6oidc-passkey-register]');
PluginManager.register('Sw6OidcPasskeyDeleteConfirm', Sw6OidcPasskeyDeleteConfirmPlugin, '[data-sw6oidc-passkey-delete-form]');

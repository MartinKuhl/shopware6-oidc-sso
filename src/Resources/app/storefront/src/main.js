import Sw6OidcPasskeyLoginPlugin from './passkey/passkey-login.plugin';

const PluginManager = window.PluginManager;
PluginManager.register('Sw6OidcPasskeyLogin', Sw6OidcPasskeyLoginPlugin, '[data-sw6oidc-passkey-login]');

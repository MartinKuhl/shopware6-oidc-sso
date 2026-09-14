# Shopware 6 OIDC & Passkey SSO

<p align="center">
  <img src="src/Resources/config/plugin.png" alt="Sw6Oidc Logo" width="160" />
</p>

OpenID Connect (OIDC) and Passkey (WebAuthn) single sign-on for Shopware 6 Storefront customers and Administration users, with just-in-time (JIT) account provisioning and OIDC-group-to-Shopware-role/group mapping.

> **Status**: v0.1.0 — early-stage. This plugin has not yet been exercised against a live Shopware instance in an automated test suite; several settings described below are present in the schema/UI but not yet functional (see [Known Limitations](#known-limitations)). Review that section before relying on this in production.

## Why This Plugin?

Shopware's built-in authentication is password-based. This plugin bridges Shopware 6 to your corporate Identity Provider (IdP), so Storefront customers and Administration users can sign in with the same identity your organization already manages — with optional passwordless Passkey login as a fully independent alternative that needs no external IdP at all.

## Key Features

- **Dual SSO Flows**: separate Storefront (customer) and Administration (admin) OIDC login, sharing one verification pipeline
- **Multi-Provider Support**: configure multiple OIDC providers, each with its own client credentials, endpoints, and behavior
- **Auto-Discovery**: populate endpoints from an IdP's `.well-known/openid-configuration`
- **JIT Provisioning**: auto-create Storefront customers and/or Administration users on first login
- **Group/Role Mapping**: map OIDC group claims to Shopware customer groups and ACL roles, case-insensitively, first match wins, with configurable defaults
- **Rich Attribute Mapping**: map claims to 19 Shopware fields — identity (email, username, name, birthday, gender, phone) plus full billing/shipping address
- **Per-User IdP Binding**: the IdP that first authenticates an account is permanently bound to it; login via a different provider is rejected
- **RP-Initiated Logout**: redirects to the IdP's end-session endpoint on logout and revokes the access token (RFC 7009), for the Storefront/customer flow
- **PKCE + Nonce**: always-on PKCE (S256 or plain, configurable) and single-use state/nonce for every authorization request
- **JWT Verification**: RS256/384/512 signature verification with JWKS caching
- **Base64 Claim Encoding**: supports Zitadel-style Base64-encoded claim values and nested role objects
- **Passkey (WebAuthn/FIDO2) Login**: independent passwordless sign-in for both Administration and Storefront, self-service registration and login, bridged into native authentication the same way OIDC is
- **Public Client Support**: PKCE-only flows without a client secret (RFC 6749 §2.1)

---

## Requirements

- **PHP**: 8.2 – 8.5
- **Shopware**: `>=6.7.0.0 <6.8.0.0`
- **Identity Provider**: any OIDC-compliant IdP (Authelia, Keycloak, Auth0, Okta, Azure AD, Google Workspace, Zitadel, etc.)
- **HTTPS**: required in production — WebAuthn requires a secure context, and IdP redirects should always use HTTPS

Composer dependencies (installed automatically): `web-token/jwt-framework`, `web-auth/webauthn-lib` (`^4.7` — see [Known Limitations](#known-limitations)), `league/oauth2-server`, `symfony/psr-http-message-bridge`, `nyholm/psr7`.

---

## Installation

```bash
composer require martinkuhl/shopware6-oidc-sso
bin/console plugin:refresh
bin/console plugin:install --activate Sw6Oidc
bin/console cache:clear
```

The plugin's database schema is created by a migration, not an install hook — it runs automatically as part of `plugin:install`. If you ever need to run it explicitly (or re-run after a manual reset):

```bash
bin/console database:migrate Sw6Oidc --all
```

### Register URLs with Your Identity Provider

| URL | IdP field | Required | Notes |
|-----|-----------|----------|-------|
| `https://your-shop.com/sw6oidc/callback` | Redirect URI (Storefront) | **Yes**, for customer SSO | Authorization code callback for Storefront login |
| `https://your-shop.com/api/sw6oidc/admin/callback` | Redirect URI (Admin) | **Yes**, for admin SSO | Authorization code callback for Administration login |
| `https://your-shop.com/api/sw6oidc/provider/test-callback` | Redirect URI | Optional | Only needed if you use the **Run live login test** button on a provider's detail page in the Administration — the IdP redirects back here with the same strict exact-match check as the other two URIs |
| Your shop's account login page | Post Logout Redirect URI | Optional | Only the Storefront/customer flow redirects back from the IdP on logout today (see [Known Limitations](#known-limitations)) |

Register only the redirect URI(s) for the flow(s) you intend to use — you don't need both if, say, only customer SSO is enabled for a given provider.

> There is currently no Back-Channel or Front-Channel Logout URL to register — the plugin does not implement either (see [Known Limitations](#known-limitations)).

---

## Configuration Guide

Unlike a typical Shopware plugin, providers are **not** configured in `Settings > System > Plugins` config fields — each provider is a database-backed entity managed through a dedicated Administration module.

### Configuring a Provider

1. Navigate to the **OIDC & Passkey SSO** section in the Administration (the plugin's own provider management module).
2. Add a new provider and fill in:
   - **App Name**: an identifier for this IdP (e.g. `keycloak`, `authelia`)
   - **Display Name**, **Sort Order**, **Button Label**, **Button Color**: SSO button appearance/ordering when multiple providers are configured
   - **Client ID** / **Client Secret**: from your IdP (see [Security Considerations](#security-considerations) regarding secret storage)
   - **Public Client**: enable for PKCE-only flows with no client secret
   - **Well-Known Config URL** *(optional)*: your IdP's discovery document — populates the endpoint fields below automatically
   - **Authorize / Token / User Info / End Session / Revocation / JWKS Endpoints**, **Issuer**: filled by discovery, or entered manually
   - **Scope** (default: `openid profile email`)
   - **PKCE Method**: `S256` (default) or `plain`
   - **Claim Encoding**: `none` (default) or `base64` for providers that Base64-encode claim values (e.g. Zitadel)
   - **Group Attribute**: the claim key holding group memberships (default: `groups`)
   - **Login Type**: `customer`, `admin`, or `both`
   - **Auto Create Customer** / **Auto Create Admin**: enable JIT provisioning per user type
   - **Show Customer Link** / **Show Admin Link**: whether the SSO button appears on the respective login page
   - **Is Active**: whether this provider is usable at all
   - **Default Customer Group** / **Default ACL Role**: fallback assignment when no group mapping matches
   - **HTTP Timeout**, **JWKS Cache TTL**: per-provider tuning (defaults: 30s, 86400s)
3. Save.

### Attribute Mapping

Per provider, map OIDC claims to Shopware fields. Identity fields have OIDC-standard defaults and work out of the box if your IdP uses standard claim names; address fields have **no default** and must be mapped explicitly if you want them populated.

| Type | Default claim | Notes |
|---|---|---|
| Email | `email` | Required — login fails if this resolves to nothing valid |
| Username | `preferred_username` | |
| First name | `given_name` | |
| Last name | `family_name` | |
| Birthday | `birthdate` | Format expected: `YYYY-MM-DD` |
| Gender | `gender` | Recognizes English and German values (`male`/`female`, `mann`/`männlich`/`frau`/`weiblich`, etc.) → Shopware salutation |
| Phone | `phone_number` | |
| Billing/Shipping address (city, state, country, street, phone, zip) | *(none)* | Configure per field if you want auto-populated addresses |

### Group / Role Mapping

Per provider, map OIDC group names to Shopware ACL roles (admin) or customer groups (Storefront):

1. Add a mapping: **OIDC Group** → **ACL Role** (or **Customer Group**), with a **Sort Order**.
2. Matching is case-insensitive; the first matching mapping (by sort order) wins.
3. If nothing matches, the provider's configured **Default ACL Role** / **Default Customer Group** applies.
4. For admin users, JIT creation is refused outright if no role can be resolved at all (no match and no default) — the plugin will not create an admin without an ACL role.

### Passkey Settings

Passkeys are configured independently of OIDC — no external IdP involved. Found under the plugin's system config:

- **Enable Passkey Login for Administration users**
- **Enable Passkey Login for Storefront customers** (configurable per sales channel)
- **Relying Party Name**: shown in the browser/OS passkey prompt (defaults to the shop name)
- **Relying Party ID (domain) override**: the domain a passkey link is bound to (a custom field that shows your current hostname as a placeholder). Defaults to the current host if left blank.

**Multi-domain caveat**: a passkey is bound to a single Relying Party ID. If you change this setting, or if the shop is reachable under multiple hostnames, previously registered passkeys will stop validating and users must re-register.

**Requirements**: HTTPS (WebAuthn requires a secure context; `localhost` is exempt for local development) and a browser with WebAuthn support.

---

## Usage Examples

### Customer Login Flow

1. Customer clicks the SSO button on the Storefront login page (`/sw6oidc/login`).
2. Shopware redirects to the IdP's authorization endpoint with PKCE and a single-use state/nonce.
3. Customer authenticates at the IdP.
4. IdP redirects back to `/sw6oidc/callback` with an authorization code.
5. Shopware exchanges the code for tokens, verifies the ID token's JWT signature and claims, fetches userinfo, and maps claims to a customer profile.
6. If the email matches an existing customer, that account logs in (after verifying it's bound to this same provider); otherwise, if auto-create is enabled, a new customer is created with mapped group/profile/address.
7. Customer session established.

### Admin Login Flow

1. Admin clicks the SSO button on the Administration login screen, hitting `/api/sw6oidc/admin/login`.
2. Same authorization/callback pipeline as the customer flow runs against `/api/sw6oidc/admin/callback`.
3. On success, the admin is redirected back into the Administration SPA at `#/login?sw6oidc_nonce=...` with a short-lived, one-time nonce.
4. The Administration frontend exchanges that nonce for a real OAuth2 access/refresh token pair via a background request, then completes login the same way a password login would.

### Passwordless Login (Passkeys)

**Customer flow**: a logged-in customer registers a passkey from **My Account > Passkeys**; on a later visit, they click "Login with Passkey" with no email/username needed (usernameless/discoverable login) — the browser resolves the matching credential.

**Admin flow**: an already-authenticated admin registers a passkey from their own account; on a later visit, they enter their email and click "Login with Passkey" — login is scoped to that admin's own registered passkeys.

---

## Security Considerations

### HTTPS is Required

Production deployments must use HTTPS for both the IdP redirect and WebAuthn ceremonies. `localhost` is exempt for local development only.

### PKCE, State, and Nonce

Every authorization request generates a single-use state token, PKCE code verifier, and nonce, cached with a 600-second TTL and consumed exactly once (atomic get-and-delete) — replay of a used or expired state is rejected outright.

### JWT Verification

ID tokens are verified for signature (RS256/384/512 only — HS*/ES* are not supported), expiry, not-before, issuer, audience, and nonce. JWKS keys are fetched and cached per provider.

### Per-User IdP Binding

The first IdP to authenticate (or claim) an account is permanently bound to it. A later login attempt for the same email via a **different** provider is rejected — this prevents an account's effective security level from being the weakest of multiple IdPs. An administrator can change a binding directly in the `sw6oidc_user_provider` table if a deliberate IdP migration is needed.

### RP-Initiated Logout

On Storefront logout, the plugin redirects to the IdP's end-session endpoint (if configured) and fire-and-forget revokes the access token via RFC 7009. A failed revocation call never blocks the user from logging out locally.

### Passkey (WebAuthn) Security

- Public-key cryptography only — the server stores a public key and signature counter, never a shared secret; credentials are phishing-resistant (bound to the origin).
- Registration requires a discoverable/resident credential, enabling usernameless customer login.
- Attestation conveyance is `none` — the plugin only verifies the public key, not the authenticator's hardware provenance. This favors broad device compatibility over attestation-based trust.
- Passkeys are bound to a single Relying Party ID (domain) — see [Passkey Settings](#passkey-settings).
- All of a user's passkeys are still tied to their Shopware account row; deleting the account should be followed by confirming credential cleanup for your compliance needs.

### Client Secret Storage — Read This

**Client secrets are currently stored in plaintext** in the `sw6oidc_provider` table. Unlike the sibling Magento module (which encrypts secrets at rest), this has not yet been implemented here. Restrict database access accordingly until this is addressed, and treat a database backup/export as containing live credentials.

---

## Known Limitations

- **Client secrets are stored in plaintext** — see above. Treat database access as equivalent to credential access.
- **No OIDC Back-Channel Logout** — an IdP cannot push a server-side logout notification to this plugin.
- **No admin-side RP-Initiated Logout** — only the Storefront/customer logout flow redirects to the IdP's end-session endpoint; logging an admin out of Shopware does not currently log them out at the IdP.
- **Most "sync on SSO" toggles are not yet functional** — of the five sync flags exposed in the provider schema, only "sync admin role on SSO" is actually applied on repeat logins today; customer profile/address/group re-sync and admin profile re-sync are not yet wired up.
- **Attribute value transforms are not implemented** — the per-attribute transform function/params fields exist in the schema but are not applied anywhere.
- **The "Enable debug logging" toggle does not control log verbosity** — the plugin's log level is set via the `SW6OIDC_LOG_LEVEL` environment variable (default `debug`), not this UI toggle. Logs are written to a plugin-specific log file/channel and can contain claim data — handle with the same care as any log containing PII.
- **Single-node atomic cache by default** — one-time tokens/nonces are consumed via a sequential get-then-delete against Shopware's app cache, which is safe for single-node deployments but not truly atomic under concurrent requests on the same key. A Redis-backed atomic implementation exists in the codebase but requires a manual dependency-injection override to enable for multi-node/HA deployments.
- **webauthn-lib is pinned to `^4.7`** — a 5.x migration is planned (see `TODO.md`) but deferred until the OIDC/Passkey flows are proven in production.
- **Early-stage test coverage** — only two narrow unit tests exist; there is no automated integration testing against a live Shopware instance yet.
- **No CHANGELOG.md is currently committed** — there's no changelog tracking what changed between versions yet (a `LICENSE.txt` is present).

---

## Troubleshooting

### "Callback URL mismatch" or similar error from the IdP

Verify the redirect URI registered at the IdP exactly matches:
- Storefront: `https://your-shop.com/sw6oidc/callback`
- Admin: `https://your-shop.com/api/sw6oidc/admin/callback`
- Live login test (only if you use that button): `https://your-shop.com/api/sw6oidc/provider/test-callback`

Check protocol (HTTPS required in production) and trailing slashes. Most IdPs (Authelia, Keycloak, etc.) require an *exact* string match against every registered `redirect_uri` — if you see an error like Authelia's "The 'redirect_uris' registered with OAuth 2.0 Client ... did not match 'redirect_uri' value ...", add the missing URI to the client's registered list rather than trying to make the plugin send a different one.

### Login succeeds but profile fields are empty

The OIDC claim names from your IdP likely don't match your attribute mapping. Set `SW6OIDC_LOG_LEVEL=debug` and check the plugin's log output for the raw claims received, then adjust the attribute mapping to match (claim names are case-sensitive).

### "This account was created with a different identity provider" (or similar rejection)

Per-user IdP binding is enforced — the account is already bound to a different provider. Check the `sw6oidc_user_provider` table (columns `user_type`, `user_id`, `provider_id`) to see which provider is bound. If a deliberate migration to a new IdP is intended, update or delete the relevant row(s) so the next login can rebind.

### Admin JIT creation fails with "no suitable role"

Admin JIT creation requires a resolvable ACL role — either a matching group mapping or a configured default. Verify the **Group Attribute** name matches what your IdP actually sends, and that at least one role mapping (or a Default ACL Role) is configured for the provider.

### "Login with Passkey" doesn't appear

Confirm the corresponding toggle (admin/customer) is enabled, the site is served over HTTPS (or `localhost`), and you're using a current browser with WebAuthn support (Chrome, Edge, Safari, Firefox).

### A previously working passkey no longer authenticates

Passkeys are bound to one Relying Party ID (domain). If the RP ID override changed, or the shop is now reached via a different hostname, the user must re-register a passkey under the current domain.

---

## Documentation

- **Developer Guide**: [CLAUDE.md](CLAUDE.md) — architecture, flow-by-flow internals, directory reference, and known implementation gaps
- **Migration Notes**: [TODO.md](TODO.md) — planned `web-auth/webauthn-lib` 5.x migration

## Version

- **Plugin Version**: 0.1.0
- **Package**: `martinkuhl/shopware6-oidc-sso`
- **License**: MIT (see [LICENSE.txt](LICENSE.txt))
- **Requirements**: PHP 8.2 – 8.5, Shopware `>=6.7.0.0 <6.8.0.0`

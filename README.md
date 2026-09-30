# Shopware 6 OIDC & Passkey SSO

<p align="center">
  <img src="src/Resources/config/plugin.png" alt="Sw6Oidc Logo" width="160" />
</p>

OpenID Connect (OIDC) and Passkey (WebAuthn) single sign-on for Shopware 6 Storefront customers and Administration users, with just-in-time (JIT) account provisioning and OIDC-group-to-Shopware-role/group mapping.

> **Status**: v0.1.0 + unreleased changes (see [CHANGELOG.md](CHANGELOG.md)) — early-stage. Unit-tested, but not yet exercised against a live Shopware instance in an automated integration suite. Review [Known Limitations](#known-limitations) before relying on this in production.

## Why This Plugin?

Shopware's built-in authentication is password-based. This plugin bridges Shopware 6 to your corporate Identity Provider (IdP), so Storefront customers and Administration users can sign in with the same identity your organization already manages — with optional passwordless Passkey login as a fully independent alternative that needs no external IdP at all.

## Key Features

- **Dual SSO Flows**: separate Storefront (customer) and Administration (admin) OIDC login, sharing one verification pipeline
- **Multi-Provider Support**: configure multiple OIDC providers, each with its own client credentials, endpoints, and behavior
- **Auto-Discovery**: populate endpoints from an IdP's `.well-known/openid-configuration`
- **JIT Provisioning**: auto-create Storefront customers and/or Administration users on first login
- **Group/Role Mapping**: map OIDC group claims to Shopware customer groups and ACL roles, case-insensitively, first match wins, with configurable defaults
- **Claims-Based Access Control**: per-provider rules (`equals`, `contains`, `exists`, …) on the IdP's claims that must all pass before anyone is logged in or provisioned, each with its own denial message
- **Rich Attribute Mapping**: map claims to 19 Shopware fields — identity (email, username, name, birthday, gender, phone) plus full billing/shipping address
- **Per-User IdP Binding**: the IdP that first authenticates an account is permanently bound to it; login via a different provider is rejected
- **RP-Initiated Logout**: redirects to the IdP's end-session endpoint on logout and revokes the access token (RFC 7009), for both Storefront customers and Administration users
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

Composer dependencies (installed automatically): `web-token/jwt-framework`, `web-auth/webauthn-lib` (`^5.3`), `league/oauth2-server`, `symfony/psr-http-message-bridge`, `nyholm/psr7`.

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
| `https://your-shop.com/sw6oidc/backchannel-logout` | Back-Channel Logout URI | Optional | Lets the IdP end shop sessions when the user logs out at the IdP (see [Back-Channel Logout](#back-channel-logout)). Enable "session required" / `backchannel_logout_session_required` if the IdP offers it |
| `https://your-shop.com/sw6oidc/frontchannel-logout` | Front-Channel Logout URI | Optional | Alternative to Back-Channel Logout for IdPs that only support the browser-based variant; enable "session required" (`frontchannel_logout_session_required`) — the plugin needs `iss` and `sid` |
| Your shop's account login page and `https://your-shop.com/admin/` — **or** only `https://your-shop.com/sw6oidc/postlogout` | Post Logout Redirect URI | Optional | Where the IdP returns the user after RP-initiated logout. By default customers return to `/account/login` and admins to the Administration. If the IdP accepts only one URI, set the provider's **Post-logout redirect URI** to `https://your-shop.com/sw6oidc/postlogout` and register just that: it sends customers and admins to the right login page |

Register only the redirect URI(s) for the flow(s) you intend to use — you don't need both if, say, only customer SSO is enabled for a given provider.

> Prefer Back-Channel Logout where the IdP supports it: it does not depend on the user's browser still being open on the IdP's logout page.

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
   - **Disable non-OIDC Customer Login** / **Disable non-OIDC Admin Login**: turn off native *password* login for that user type shop-wide (Storefront form, Store API and Admin `/api/oauth/token` password grant). OIDC and Passkey logins keep working. Can only be switched on once at least one account of that type has signed in through this provider, so enabling it can't lock everyone out. Emergency override: set `SW6OIDC_ALLOW_PASSWORD_LOGIN=1`.
   - **Is Active**: whether this provider is usable at all
   - **Default Customer Group** / **Default ACL Role** *(Account creation card)*: fallback assignment when no group mapping matches
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

Each mapping can optionally **transform** the claim value before it is stored (applied on every login):

| Transform | Parameters | Example |
|---|---|---|
| Concatenate claims | claims to append, separator (default space) | street `Main St` + `house_no` → `Main St 5` |
| Split | separator, part index (`-1` = last) | `name` split on space, index `-1` → last name |
| Prefix | text | `42` → `OIDC-42` |
| Regex replace | PCRE pattern, replacement | `/\D+/` → `` strips non-digits from a phone number |

A misconfigured transform never breaks login — the untransformed value is used and a warning is logged.

### Group / Role Mapping

Per provider, map OIDC group names to Shopware ACL roles (admin) or customer groups (Storefront):

1. Add a mapping: **OIDC Group** → **ACL Role** (or **Customer Group**), with a **Sort Order**.
2. Matching is case-insensitive; the first matching mapping (by sort order) wins.
3. If nothing matches, the provider's configured **Default ACL Role** / **Default Customer Group** applies.
4. For admin users, JIT creation is refused outright if no role can be resolved at all (no match and no default) — the plugin will not create an admin without an ACL role.

#### Granting full superadmin via an OIDC group

A Shopware **superadmin** (`admin = true` on the user) bypasses ACL entirely — it is a different mechanism from ACL roles, and no ACL role, however permissive, is fully equivalent to it. Because of that, an `admin_role` mapping (or the Default ACL Role) can never make an OIDC-provisioned admin a true superadmin.

If you specifically need that, it requires **two deliberate, independent steps** on the provider — this is intentionally not a side effect of any other setting, since granting full superadmin from IdP group membership is security-sensitive:

1. Enable **Allow "Grant superadmin" group mappings** (Superadmin grant card).
2. Add a Group/Role Mapping row with mapping type **Grant superadmin**, with the OIDC group that should receive it.

Only when *both* are true does a group match result in `admin = true`. Only grant this for a narrow, tightly controlled IdP group — everyone in it gets unrestricted access to the entire shop. Superadmin is only ever granted, never automatically revoked by a later login whose groups no longer match (to avoid a transient IdP claims issue silently locking out your only superadmin) — revoke it manually in the Administration if a person's access should be downgraded.

### Claims-based access control

The **Access control** card on a provider lets you restrict who may log in at all, based on the claims the IdP returns — for example "only members of the `staff` group" or "only verified email addresses". Rules are checked after the id_token/userinfo claims are verified and **before** any account is looked up, created or synced, so a denied login never provisions anything.

| Operator | Passes when |
|---|---|
| equals / does not equal | The claim's value equals (does not equal) the rule value. A missing claim passes "does not equal". |
| contains / does not contain | For a list claim (e.g. `groups`): the list has (does not have) an entry equal to the value. For a text claim: the text contains (does not contain) the value, e.g. `email` contains `@example.com`. A missing claim passes "does not contain". |
| exists / does not exist | The claim is present (absent). No value needed. |

- **All rules must pass** (AND), checked in sort order. The first failing rule denies the login and shows its **message** to the user (Storefront flash message, Administration login screen); without a message a generic "access denied" text is shown. Messages are plain text.
- **Claim keys** use the flattened dot notation, e.g. `realm_access.roles` for Keycloak realm roles. List claims are matched by entry (`groups`, not `groups.0`); for Zitadel-style role objects (`{"Admins": {...}}`) the role names are the entries.
- Comparisons ignore case and surrounding whitespace; `true`/`1` and `false`/`0` are treated as equal. An unknown operator denies (fails closed).
- No rules = everyone who authenticates at the IdP may log in (the previous behavior).
- Rules are included in `sw6oidc:config:export`/`import`.

### Sync on every login

By default, a mapped claim is only ever applied once, at account creation — logging in again afterwards just authenticates the existing account, untouched. The **Sync on every login** card adds five independent, opt-in toggles that instead re-apply the current claims/mapping on every single login, for an account already bound to this provider:

| Toggle | Re-applies on every login |
|---|---|
| Sync customer profile | First name, last name, date of birth, salutation (Storefront) |
| Sync customer address | The existing default billing address (Storefront) — no effect unless a billing address is mapped |
| Sync customer group | The Group/Role Mapping's resolved customer group (Storefront) |
| Sync admin profile | First name, last name (Administration) |
| Sync admin role | The Group/Role Mapping's resolved ACL role, or superadmin grant (Administration) |

Each is a partial update: a claim that isn't mapped, or a group/role mapping that doesn't resolve to anything, is simply left as-is rather than being cleared or reset to a placeholder. Address sync only ever updates a customer's *existing* address in place — it never creates one. None of the role/group sync toggles ever revoke an existing superadmin grant (see above).

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

Every IdP URL the plugin fetches server-side (discovery, token, userinfo, JWKS, revocation, end-session) must be **HTTPS on a public address** — this is enforced when a provider is saved (SSRF protection: private, loopback, link-local, CGNAT and similar ranges are rejected) and again on every outbound request, including redirects (so DNS changes after saving can't be abused). For a local development IdP on plain HTTP or a private/docker network address, set `SW6OIDC_ALLOW_INSECURE_IDP_URLS=1` — never in production.

### PKCE, State, and Nonce

Every authorization request generates a single-use state token, PKCE code verifier, and nonce, cached with a 600-second TTL and consumed exactly once (atomic get-and-delete) — replay of a used or expired state is rejected outright.

### JWT Verification

ID tokens are verified for signature (RS256/384/512 only — HS*/ES* are not supported), expiry, not-before, issuer, audience, and nonce. JWKS keys are fetched and cached per provider; a failed fetch pauses further fetches for 60s (circuit breaker), and a token signed with a key missing from the cached set triggers one refetch so IdP key rotation doesn't lock users out until the cache expires.

### Per-User IdP Binding

The first IdP to authenticate (or claim) an account is permanently bound to it. A later login attempt for the same email via a **different** provider is rejected — this prevents an account's effective security level from being the weakest of multiple IdPs. The binding is shown as **OIDC Provider** (provider name and bind date, or "none") in the Administration: as a column in *Settings > Users & permissions*, on each admin user detail page, on the logged-in admin's own profile (*My profile > General*, read-only), and in the customer detail base info next to *Last login*. If a deliberate IdP migration is needed, the **Unlink IdP** button there removes the binding (requires `users_and_permissions.editor` / `customer.editor`), so the next SSO login can bind to a different provider. Deleting a user or customer also removes its binding.

### RP-Initiated Logout

On Storefront logout and on Administration logout (user menu → Log out), the plugin redirects to the IdP's end-session endpoint (if configured) and fire-and-forget revokes the access token via RFC 7009. A failed revocation call never blocks the user from logging out locally. After the IdP logout, customers land on `/account/login` and admins on the Administration login page — unless the provider's **Post-logout redirect URI** is set, which replaces both (use `https://your-shop.com/sw6oidc/postlogout` to keep the per-flow login pages with a single registered URI; its `state` parameter is signed, so a crafted link can't choose the destination). Inactivity/session-timeout logouts in the Administration stay local, so the admin can simply re-authenticate.

**Authelia note**: Authelia does not implement standards-based RP-Initiated Logout / OIDC Session Management — its discovery document has no `end_session_endpoint` at all, so **auto-discovery leaves this field blank** and it must be set manually to Authelia's own portal logout page:
```
https://auth.your-domain.example/logout
```
(the bare portal path, not anything under `/api/oidc/...`). The plugin auto-detects this shape — any End-Session Endpoint whose path ends in `/logout` and contains neither `/oauth2/` nor `/oidc/` is treated as Authelia-style forward-auth logout, and the plugin sends `?rd=<url>` instead of the standard `id_token_hint`/`state`/`post_logout_redirect_uri` params. No Post Logout Redirect URI needs registering with Authelia for this.

### Back-Channel Logout

With [OIDC Back-Channel Logout](https://openid.net/specs/openid-connect-backchannel-1_0.html), the IdP notifies the shop server-to-server when a user's IdP session ends (logout at the IdP or in another application, session revoked by an administrator). Register `https://your-shop.com/sw6oidc/backchannel-logout` as the client's Back-Channel Logout URI.

- The logout token is verified like an id_token (signature against the provider's JWKS, `iss`, `aud`, `exp`) plus the logout-specific rules: a back-channel logout `events` claim, `sub` and/or `sid`, and **no** `nonce`. Replayed tokens (same `jti`) are ignored.
- With a `sid`, only the shop sessions created from that IdP session end; with only a `sub`, all of that user's shop sessions from this provider end. This needs the IdP to put `sid` into the id_token (usually enabled together with "backchannel logout session required").
- **Customers** are logged out of exactly that session. **Administration users** are logged out of *all* their Administration sessions: Shopware's admin access tokens can't be revoked one by one, so the plugin revokes the user's refresh tokens and invalidates every access token issued so far (the mechanism Shopware uses after a password change — the password itself is not changed).
- Only logins made after this feature was installed are known to the plugin; older sessions are not affected.
- Invalid requests are answered with HTTP 400; an address sending more than 10 invalid requests per minute gets HTTP 429 for the rest of the minute. Valid logout notifications are never rate-limited.

### Front-Channel Logout

With [OIDC Front-Channel Logout](https://openid.net/specs/openid-connect-frontchannel-1_0.html), the IdP's logout page loads `https://your-shop.com/sw6oidc/frontchannel-logout?iss=…&sid=…` in a hidden iframe. The plugin ends every shop session created from that IdP session (same customer/admin rules as Back-Channel Logout) and always answers with a 1×1 transparent GIF, whatever the outcome.

- `iss` and `sid` are required ("session required" at the IdP, and `sid` in the id_token). Without them nothing happens: the shop's own cookies are not sent inside a cross-site iframe, so the browser alone can't identify the session.
- The request is unauthenticated by design of the protocol — anyone who knows a `sid` can end that session. Unknown `sid`s count as failed requests for rate limiting, which prevents guessing.

### Rate limiting

The unauthenticated endpoints (OIDC callbacks, Back- and Front-Channel Logout) count **failed** requests per client IP address — invalid state, forged tokens, garbage. After 10 failures within 60 seconds, that address is refused for the rest of the window. Successful logins and valid logout notifications never count, so an office behind one NAT address or a busy IdP is not throttled. The counters live in Shopware's `cache.rate_limiter` pool (falls back to the app cache).

### Passkey (WebAuthn) Security

- Public-key cryptography only — the server stores a public key and signature counter, never a shared secret; credentials are phishing-resistant (bound to the origin).
- Registration requires a discoverable/resident credential, enabling usernameless customer login.
- Attestation conveyance is `none` — the plugin only verifies the public key, not the authenticator's hardware provenance. This favors broad device compatibility over attestation-based trust.
- Passkeys are bound to a single Relying Party ID (domain) — see [Passkey Settings](#passkey-settings).
- All of a user's passkeys are still tied to their Shopware account row; deleting the account should be followed by confirming credential cleanup for your compliance needs.

### Client Secret Storage — Read This

Client secrets are **encrypted at rest** (libsodium secretbox, key derived from Shopware's `APP_SECRET`) and are **write-only** in the Administration: after saving, the secret is never shown or returned by the Admin API again — leave the field empty to keep the stored value, or type a new one to replace it. Existing plaintext secrets are encrypted by the plugin's migration on `plugin:update`.

**Keep `APP_SECRET` stable.** Rotating it makes every stored client secret undecryptable; OIDC logins for those providers then fail with a "re-enter the client secret" error until an admin saves each provider with its secret again. A database dump alone no longer exposes the secrets, but a dump *plus* the `APP_SECRET` does.

---

## Known Limitations

- **IdP-initiated logout ends all Administration sessions of the user** — not just the one created from the IdP session (Shopware admin access tokens cannot be revoked individually).
- **"Sync on SSO" is per provider, not per attribute** — all five provider-level toggles (customer profile/address/group, admin profile/role) are applied on repeat logins, but there is no per-attribute sync control.
- **The "Enable debug logging" toggle does not control log verbosity** — the plugin's log level is set via the `SW6OIDC_LOG_LEVEL` environment variable (default `debug`), not this UI toggle. Logs are written to a plugin-specific log file/channel and can contain claim data — handle with the same care as any log containing PII.
- **Single-node atomic cache unless Redis is configured** — without `SW6OIDC_REDIS_DSN`, one-time tokens/nonces are consumed via a sequential get-then-delete against Shopware's app cache, which is safe for single-node deployments but not truly atomic under concurrent requests on the same key. Multi-node/HA deployments must set `SW6OIDC_REDIS_DSN` (e.g. `redis://:password@redis:6379/2`, or `rediss://` for TLS); it is picked up at runtime.
- **No automated integration tests yet** — the unit suite covers the OIDC core, provisioning, WebAuthn ceremonies and every security component, but nothing runs the full login flows against a live Shopware instance and IdP in CI.

---

## Troubleshooting

### "Callback URL mismatch" or similar error from the IdP

Verify the redirect URI registered at the IdP exactly matches:
- Storefront: `https://your-shop.com/sw6oidc/callback`
- Admin: `https://your-shop.com/api/sw6oidc/admin/callback`
- Live login test (only if you use that button): `https://your-shop.com/api/sw6oidc/provider/test-callback`

Check protocol (HTTPS required in production) and trailing slashes. Most IdPs (Authelia, Keycloak, etc.) require an *exact* string match against every registered `redirect_uri` — if you see an error like Authelia's "The 'redirect_uris' registered with OAuth 2.0 Client ... did not match 'redirect_uri' value ...", add the missing URI to the client's registered list rather than trying to make the plugin send a different one.

### Token exchange fails with HTTP 401 (`invalid_client` or similar)

For a **confidential client** (i.e. `Public client` is *off* on the provider), the plugin authenticates to the token endpoint via **HTTP Basic auth** (`client_id`/`client_secret` in the `Authorization` header), matching the `client_secret_basic` default most IdPs — including Authelia — use unless configured otherwise. If your IdP's client is instead configured for `client_secret_post` (credentials expected in the POST body) and doesn't also accept Basic auth, the token endpoint will reject the request with a 401.

For Authelia specifically, this "just works" as long as the client either omits `token_endpoint_auth_method` (defaults to `client_secret_basic`) or sets it explicitly:
```yaml
token_endpoint_auth_method: 'client_secret_basic'
```
Also double-check the `client_secret` value entered in the Shopware provider matches exactly (no trailing whitespace, and note Authelia typically expects the **hashed** secret in its own config while the *plaintext* value is what you enter in Shopware).

### What endpoint value should I use for a given field?

Rather than guessing at an IdP's exact endpoint paths (they vary by product and version), click **Load configuration** after entering the **Well-known configuration URL** (`https://your-idp.example/.well-known/openid-configuration`) — it fetches and fills in the endpoint fields directly from what the IdP itself publishes. You can also open that well-known URL in a browser to inspect the raw JSON if you want to verify a specific value.

**Exception: End-Session (Logout) Endpoint on Authelia.** Authelia's discovery document doesn't include an `end_session_endpoint` at all (it has no standards-based RP-Initiated Logout), so this one field always stays blank after "Load configuration" for an Authelia provider and must be set manually — see the Authelia note under [RP-Initiated Logout](#rp-initiated-logout).

### Login succeeds but profile fields are empty

The OIDC claim names from your IdP likely don't match your attribute mapping. Set `SW6OIDC_LOG_LEVEL=debug` and check the plugin's log output for the raw claims received, then adjust the attribute mapping to match (claim names are case-sensitive).

### "This account was created with a different identity provider" (or similar rejection)

Per-user IdP binding is enforced — the account is already bound to a different provider. The bound provider is shown as **OIDC Provider** on the admin user / customer detail page in the Administration. If a deliberate migration to a new IdP is intended, click **Unlink IdP** there so the next login can rebind.

### Admin JIT creation fails with "no suitable role"

Admin JIT creation requires a resolvable ACL role — either a matching group mapping or a configured default. Verify the **Group Attribute** name matches what your IdP actually sends, and that at least one role mapping (or a **Default ACL Role**) is configured for the provider. The **Default ACL Role** / **Default Customer Group** selects live in the provider's **Account creation** card — the simplest fix is usually to set a Default ACL Role there, so a role is always resolved even without any group match.

If `autoCreateAdmin` is on but neither a Default ACL Role nor an `admin_role`/`superadmin` mapping is configured, the provider detail page shows a warning banner in the Provisioning card so this can be caught before anyone actually tries to log in. When the denial does happen at login time, the admin login screen shows a specific message (rather than a generic "SSO login failed") and `sw6oidc.log` includes the denial `reason`, the `providerId`, and the `groups` the IdP actually sent — useful for spotting a group-attribute/claim-name mismatch.

Note that an ACL role, however permissive, is never a full substitute for Shopware's native superadmin — see [Granting full superadmin via an OIDC group](#granting-full-superadmin-via-an-oidc-group) if that's actually what you need.

### "Login with Passkey" doesn't appear

Confirm the corresponding toggle (admin/customer) is enabled, the site is served over HTTPS (or `localhost`), and you're using a current browser with WebAuthn support (Chrome, Edge, Safari, Firefox).

### A previously working passkey no longer authenticates

Passkeys are bound to one Relying Party ID (domain). If the RP ID override changed, or the shop is now reached via a different hostname, the user must re-register a passkey under the current domain.

---

## Environment Variables

| Variable | Default | Purpose |
|---|---|---|
| `SW6OIDC_REDIS_DSN` | *(unset)* | `redis://[[user]:password@]host:port[/db]` or `rediss://…` — truly atomic one-time tokens via Redis; **required for multi-node deployments**. Picked up at runtime. |
| `SW6OIDC_ALLOW_INSECURE_IDP_URLS` | `0` | `1` allows plain-http IdP URLs and private/loopback addresses (local development IdPs only — disables SSRF protection). |
| `SW6OIDC_ALLOW_PASSWORD_LOGIN` | `0` | `1` is a break-glass override that re-enables password login even when a provider disables it. |
| `SW6OIDC_LOG_LEVEL` | `debug` | Log level of the plugin's own log channel (`var/log/sw6oidc-<env>.log`). |
| `APP_SECRET` | *(Shopware)* | The client-secret encryption key is derived from it — keep it stable. |

## Command-Line Tools

```bash
# Export providers (incl. attribute/role mappings and access-control rules) as JSON — the client secret is omitted by default
bin/console sw6oidc:config:export -o providers.json [--provider-id=<id>] [--keep-encrypted|--plaintext]

# Import on another installation — validate first, then apply
bin/console sw6oidc:config:import -i providers.json --dry-run
bin/console sw6oidc:config:import -i providers.json [--overwrite] [--skip-unresolved]
```

`--keep-encrypted` exports the encrypted secret, importable only where `APP_SECRET` is identical; `--plaintext` exports it readable (treat the file as a credential). ACL roles and customer groups are matched by id, then by name. Imports run the same validation as saving in the Administration (SSRF, lockout guard).

## Extension Points (Events)

Subscribe to these (all `ShopwareEvent`s) to customize JIT provisioning:

| Event | When | Can change |
|---|---|---|
| `MartinKuhl\Sw6Oidc\Event\AttributeMappingCompletedEvent` | Every OIDC login, after claims were mapped | The mapped profile (`setProfile()`) |
| `…\CustomerBeforeCreateEvent` / `…\AdminBeforeCreateEvent` | Right before a new account is created | The create payload (`setPayload()`; the admin payload includes `admin`/`aclRoles` — handle with care) |
| `…\CustomerAfterCreateEvent` / `…\AdminAfterCreateEvent` | After a new account was created and bound | — (read-only) |

## Documentation

- **Developer Guide**: [CLAUDE.md](CLAUDE.md) — architecture, flow-by-flow internals, directory reference, and known implementation gaps
- **Changelog**: [CHANGELOG.md](CHANGELOG.md)
- **Roadmap**: [TODO.md](TODO.md) — remaining work

## Version

- **Plugin Version**: 0.1.0
- **Package**: `martinkuhl/shopware6-oidc-sso`
- **License**: MIT (see [LICENSE.txt](LICENSE.txt))
- **Requirements**: PHP 8.2 – 8.5, Shopware `>=6.7.0.0 <6.8.0.0`

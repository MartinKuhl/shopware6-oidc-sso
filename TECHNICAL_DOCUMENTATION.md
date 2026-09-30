# Technical Documentation — Shopware 6 OIDC & Passkey SSO

This document is a guided tour for a developer picking up this codebase for the first time. It assumes you know PHP, Symfony-style DI, and OAuth2/OIDC concepts in general, but nothing about this specific plugin or Shopware's plugin conventions.

- For a dense, component-by-component architecture reference once you're oriented, see [CLAUDE.md](CLAUDE.md).
- For admin/end-user setup instructions, see [README.md](README.md) and the IdP-specific guides in [Docs/](Docs/) (Authelia, ZITADEL, Dex).
- For what changed recently, see [CHANGELOG.md](CHANGELOG.md).

---

## 1. Overview

### What it does

This is a Shopware 6.7 plugin (`MartinKuhl\Sw6Oidc`, composer package `martinkuhl/shopware6-oidc-sso`) that adds two independent ways to log in without a Shopware-managed password:

1. **OIDC (OpenID Connect).** Authentication is delegated to an external Identity Provider (IdP) such as Keycloak, Authelia, Okta, Azure AD/Entra, ZITADEL or Dex. It works for **Storefront customers** and **Administration users**. On first login it can create the account (JIT provisioning) and assign customer groups, ACL roles or superadmin based on IdP group claims.
2. **Passkey (WebAuthn/FIDO2).** A separate passwordless method with no IdP involved. The browser's platform authenticator (Face ID, Windows Hello, a security key) signs a challenge and the plugin verifies the signature.

Both paths end the same way. The verified identity goes to Shopware's *native* authentication: a normal sales-channel context on the Storefront, and a real OAuth2 access/refresh token pair in the Administration. Nothing downstream needs to know SSO was involved.

Around these two login paths, the plugin also covers most of the session lifecycle an SSO integration needs:

- logout in both directions (RP-initiated, plus Back-/Front-Channel Logout)
- a session activity log with force-logout
- claims-based access rules
- enforcement of "SSO only, no password login"
- provider health checks with webhook alerting
- config export/import for moving providers between environments

### Why it exists

Shopware ships with password auth only. Organizations with a corporate IdP don't want to manage a separate Shopware password. They also want their central MFA, onboarding and offboarding rules to apply to the shop's admin panel and customer accounts. Passkeys solve a related problem: passwordless, phishing-resistant login for people who aren't routed through a corporate IdP.

The architecture deliberately mirrors the sibling `magento2-oidc-sso` module, so concepts (bindings, role mapping, passkey trade-offs) carry across.

### Project status

This is early-stage software: version `0.1.0` plus a large set of unreleased changes, MIT-licensed, with support for PHP 8.2–8.5 and Shopware `>=6.7.0.0 <6.8.0.0`. Test coverage:

- ~380 unit tests
- an integration suite that runs a real Shopware kernel against Dex

Several items in the `[Unreleased]` changelog section are **breaking** (HTTPS-only IdP URLs, enforced password-login flags, a write-only client secret, `APP_SECRET`-bound encryption). Read them before upgrading a running shop.

---

## 2. Structure

### Directory layout

```
src/
├── Sw6Oidc.php                 # Plugin bootstrap; only uninstall() is custom (drops tables unless "keep data")
├── Migration/                  # 11 migrations: schema is migration-driven, not install()-driven
├── Core/Content/               # DAL entity definitions (Provider, AttributeMapping, RoleMapping,
│                               #   AccessControlRule, UserProvider, PasskeyCredential, SessionActivity)
│   └── Provider/Field/         #   Sw6OidcEncryptedField: transparent at-rest encryption for secrets
├── Service/
│   ├── Oidc/                   # OIDC protocol: authorize URL, callback pipeline, token exchange, JWT, logout
│   ├── AdminAuth/              # Bridge from verified identity → real Shopware admin OAuth2 tokens
│   ├── Passkey/                # WebAuthn ceremonies (registration + assertion)
│   ├── Provisioning/           # Claims → Shopware customer/admin: mapping, transforms, JIT create, sync
│   ├── Security/               # State/PKCE/nonce, encryption, SSRF guard, access rules, rate limiter, CSP
│   ├── Session/                # Session registry, IdP-initiated logout, session destruction, activity log
│   ├── Health/                 # Provider config inspection, reachability probe, alert state machine, webhook
│   ├── Config/                 # Provider export/import (used by the Console commands)
│   ├── Cache/                  # Atomic get-and-delete cache for one-time tokens (Redis or cache.app)
│   ├── Http/                   # SSRF-guarded HTTP client factory + OidcHttpClient wrapper
│   ├── Jwt/                    # Unverified payload reader for tokens verified elsewhere
│   ├── Provider/               # Provider lookup (by id, by issuer)
│   └── Logging/                # Dedicated Monolog channel + sensitive-data scrubbing
├── Controller/
│   ├── Api/                    # Admin-facing endpoints (/api/sw6oidc/admin/*, /api/_action/sw6oidc/*)
│   ├── Oidc/                   # Back-/Front-Channel Logout, shared post-logout landing
│   └── HealthCheckController   # GET /sw6oidc/health
├── Storefront/
│   ├── Controller/             # Customer login, callback, passkey, "My passkeys" account page
│   ├── Service/                # Passwordless login route, logout decorator, password-login guard
│   └── EventSubscriber/
├── Subscriber/                 # Write guard (SSRF + lockout), admin password guard, CSP, binding cleanup
├── ScheduledTask/              # Session activity cleanup (daily), health check alerting (5 min)
├── Console/                    # sw6oidc:config:export / sw6oidc:config:import
├── Event/                      # Extension points for integrators (see Section 4)
├── Twig/                       # Injects SSO/passkey buttons and the admin login JS
└── Resources/
    ├── config/                 # services.xml (DI), config.xml (plugin settings), routes.xml
    ├── app/administration/     # Vue: provider module, sessions module, sw-login / sw-profile overrides
    ├── views/storefront/       # Login page buttons, account passkey page
    └── snippet/                # de-DE / en-GB translations
tests/
├── Unit/                       # No Shopware kernel needed
└── Integration/                # Real kernel + Dex (docker-compose.yml), see its README
```

### The three-layer mental model

It helps to think of the code in three layers:

1. **Protocol layer** (`Service/Oidc/`, `Service/Passkey/`). This layer talks to the outside world: the IdP, or the browser's WebAuthn API. It deals in tokens, claims and credentials and knows nothing about Shopware accounts.
2. **Provisioning layer** (`Service/Provisioning/`). This layer turns a verified identity into a Shopware customer or admin. It finds an existing account, enforces the provider binding, creates the account if allowed, maps attributes and resolves groups to roles.
3. **Bridge layer** (controllers, `Storefront/Service/`, `Service/AdminAuth/`). This is the HTTP glue that hands the result to Shopware's real authentication system, where "OIDC/Passkey verified you" becomes a real session or token.

Cross-cutting services sit next to these layers:

- `Service/Security/` guards the edges: SSRF, rate limiting, access rules, password-login policy.
- `Service/Session/` tracks what each login created, so it can be ended later.

Two flows run through the layers: **Storefront/customer** and **Administration/admin**. They share the protocol and provisioning layers almost completely. `OidcCallbackProcessor` is the single callback pipeline for both, "so the two flows can never drift". The bridge layer differs a lot, because customer contexts and admin OAuth2 tokens are fundamentally different mechanisms (see Section 4).

### Data model

| Table | Purpose |
|---|---|
| `sw6oidc_provider` | One row per IdP: credentials (secret encrypted), endpoints, behavior flags, sync toggles, health-alert settings and state |
| `sw6oidc_attribute_mapping` | Per-provider claim → Shopware field, with optional value transform |
| `sw6oidc_role_mapping` | Per-provider OIDC group → ACL role / customer group / superadmin grant |
| `sw6oidc_access_control_rule` | Per-provider claim rules that must all pass before a login is accepted |
| `sw6oidc_user_provider` | Permanent binding: which provider owns which account (first login wins) |
| `sw6oidc_passkey_credential` | One row per registered WebAuthn credential |
| `sw6oidc_session_activity` | Audit log: one row per OIDC/Passkey login, with logout time and reason |

Providers are managed as **DAL entities** through the plugin's own Administration module, **not** through `config.xml`. The plugin settings screen only holds the passkey toggles, RP name/ID and one debug-logging toggle that isn't wired to anything (see Section 5).

The short-lived state lives in caches, not tables. This covers flow state, nonces, the session registry, logout context and JWKS.

### Configuration surface

| Where | What |
|---|---|
| Admin → OIDC provider module | Everything per provider |
| Plugin settings (`config.xml`) | `passkeyEnabledAdmin`, `passkeyEnabledCustomer` (per sales channel), `passkeyRpName`, `passkeyRpId` |
| `SW6OIDC_LOG_LEVEL` | Log verbosity of the `sw6oidc` channel (default `debug`) |
| `SW6OIDC_REDIS_DSN` | `redis://`/`rediss://` for atomic one-time tokens; **required on multi-node** |
| `SW6OIDC_ALLOW_INSECURE_IDP_URLS=1` | Allow http/private-network IdPs (local dev only) |
| `SW6OIDC_ALLOW_PASSWORD_LOGIN=1` | Break-glass: ignore every "disable password login" flag |
| `SW6OIDC_SESSION_ACTIVITY_RETENTION_DAYS` | Activity log retention (default 90, `0` = keep forever) |

---

## 3. Quick Start

### Step 1: Install

```bash
composer require martinkuhl/shopware6-oidc-sso
bin/console plugin:refresh
bin/console plugin:install --activate Sw6Oidc
bin/console cache:clear
```

Installing runs the migrations. On a PHP-FPM host that already runs a shop, also reset opcache after updates (e.g. `cachetool opcache:reset`). Otherwise stale plugin classes can keep being served.

### Step 2: Add a provider

In the Administration, open **OIDC & Passkey SSO** and add a provider:

- **App Name**, **Client ID** and **Client Secret** (or mark it as a public client)
- a **Well-Known Config URL** to auto-discover endpoints, or the endpoints by hand
- **Login Type**: `customer`, `admin` or `both`
- **Auto Create Customer/Admin** if you want JIT provisioning, plus a default customer group or ACL role

IdP URLs must be HTTPS on a public address. For a local IdP, set `SW6OIDC_ALLOW_INSECURE_IDP_URLS=1`.

### Step 3: Register redirect URIs at the IdP, then test

Register these at the IdP:

- `https://your-shop.com/sw6oidc/callback` (Storefront)
- `https://your-shop.com/api/sw6oidc/admin/callback` (Admin)
- optionally, `https://your-shop.com/sw6oidc/postlogout` as the post-logout URI

Use the **live login test** on the provider detail page first. It runs a real login in a popup and shows the claims the IdP returned, and those claims then feed the attribute mapping picker. After that, use the SSO button on the Storefront or Administration login page.

If something is off, set `SW6OIDC_LOG_LEVEL=debug` and read the `sw6oidc` log channel. Also run **Run diagnostics** on the provider.

**Passkeys** need no provider setup. Enable them in the plugin settings, and users register a credential from their Storefront account page or the Administration profile.

---

## 4. Functionalities and Use Cases

### OIDC login, Storefront

1. `GET /sw6oidc/login` creates state, a PKCE verifier and a nonce, caches them for 600s, and redirects to the IdP. PKCE is always on (`plain` or `S256`).
2. `GET /sw6oidc/callback` runs `OidcCallbackProcessor`:
   - consumes the state (single use)
   - exchanges the code
   - verifies the ID token (RS256/384/512 only; JWKS cached with a circuit breaker and one forced refetch on key rotation)
   - merges in userinfo claims
   - normalizes groups and flattens the claims
   - evaluates access-control rules
   - maps the claims to a `MappedProfile`
3. `CustomerProvisioningService` finds the customer by email, or creates one. `OidcCustomerLoginRoute` then logs them in.

**Use case:** B2B storefronts where customer identity lives in a corporate directory instead of self-service registration.

### OIDC login, Administration

The protocol pipeline is the same, but the bridge is different. The Admin SPA authenticates with OAuth2 tokens, not a server session, so the plugin runs a **second, plugin-owned `league/oauth2-server` instance** wired to Shopware core's *own* client, token and scope repositories. This instance mints genuine tokens via a custom grant (`AdminOidcGrant`).

The OIDC redirect is a full-page navigation, so the hand-back to the SPA works like this:

1. The callback stores a 120s nonce.
2. It redirects to `/admin#/login?sw6oidc_nonce=…`.
3. The `sw-login` override POSTs the nonce to `/api/sw6oidc/admin/token` and receives a normal token response.

**Use case:** staff SSO into the backend with central MFA and offboarding. Removing someone at the IdP removes their shop access.

Two admin-specific extras:

- **Password reconfirmation.** Plugin-provisioned admins have a random, unknown password, so Shopware's "confirm your password" modal could never succeed for them. `POST /api/sw6oidc/admin/verify-session` mints a `user-verified`-scoped token for accounts that are bound to a provider or own a passkey.
- **Inactivity re-login.** The inactivity modal gets one SSO button per admin provider and returns the admin to the page they were on.

### Passkey login

A user registers a device credential once, while logged in, and then signs in with a fingerprint, face, PIN or security key. The plugin stores and verifies the credential itself.

The Storefront uses usernameless (discoverable) login. The Administration uses email-scoped login when an email is typed. Admin passkey login uses the same `AdminOidcGrant` directly, because it's a same-page AJAX ceremony and needs no nonce.

**Use case:** phishing-resistant, password-free login without a corporate IdP, or a second option alongside OIDC.

### JIT provisioning, mapping and sync

**Attribute mapping** covers 19 target fields (identity plus billing and shipping address). Only the identity fields have OIDC-standard default claims. Address fields stay unmapped until you configure them.

Each mapping can apply a **transform**:

- `concat`
- `split`
- `prefix`
- `regex_replace`

**Group mapping** resolves the groups claim to an ACL role or customer group. The first match by `sort_order` wins, then the provider default applies.

**Superadmin** is opt-in behind two gates: the provider flag `allow_superadmin_group_mapping` **and** an explicit `superadmin` mapping row. A stray row alone never grants it.

**Sync-on-SSO** re-applies claims on every login. Five independent per-provider toggles control it (customer profile, address, group; admin profile, role). Sync is a partial update: unmapped or unresolved values are left alone and never reset.

**Use case:** onboarding happens entirely on the IdP side. Add someone to "Engineering" there, and their first login creates a Shopware admin with the right role.

### Provider binding

The first provider that authenticates an account owns it permanently (`sw6oidc_user_provider`). A later login of the same email through a different provider fails with `ProviderMismatchException`.

This stops a weaker IdP from being used to take over an account governed by a stronger one. Admins can unlink a binding from the user or customer detail page.

### Access control rules

Per-provider rules on flattened claims use the operators `eq`, `neq`, `contains`, `not_contains`, `exists` and `not_exists`. All rules must pass, and they're evaluated before any account lookup or creation.

- Matching is case-insensitive and list-aware: `contains` on a groups list tests membership.
- Each rule can carry its own denial message.
- The Admin login screen receives the message through a one-time error ticket, so free text never goes into a URL.

**Use case:** "only `department=sales` may log in through this provider", without touching IdP client config.

### "SSO only": disabling password login

`disable_non_oidc_{admin,customer}_login` blocks password login on the Storefront, the Store API and the Admin `password` grant. It also hides the password forms.

A lockout guard refuses to enable the flag until at least one account of that type is bound to the provider. The break-glass override is `SW6OIDC_ALLOW_PASSWORD_LOGIN=1`.

### Logout, in both directions

**Shop → IdP (RP-initiated).** Storefront and Admin logout redirect to the IdP's `end_session_endpoint` and revoke the token (RFC 7009, fire-and-forget).

- Authelia's forward-auth logout is special-cased.
- For IdPs that allow only one post-logout URI, `/sw6oidc/postlogout` routes customers and admins using an HMAC-signed `state`.

**IdP → shop.**

- `POST /sw6oidc/backchannel-logout` accepts a fully verified logout token (signature, `iss`/`aud`/`exp`, `events`, no `nonce`, `jti` replay protection).
- `GET /sw6oidc/frontchannel-logout?iss=&sid=` handles iframe-based logout and always returns a 1×1 GIF.

Both endpoints look sessions up in the **session registry**, which records the context token or access-token `jti` each OIDC login created, keyed by `sub` and `sid`.

### Session activity log and force logout

Every OIDC or Passkey login writes a row to `sw6oidc_session_activity`. The row records provider, IP, user agent, login and logout time, and logout reason (`logout`, `backchannel`, `frontchannel`, `forced`).

The **OIDC & Passkey sessions** Admin module lists these rows and offers **Force logout**. A daily task prunes old rows.

Auditing never blocks a login or logout: every recorder method swallows and logs its own errors.

### Health checks and alerting

- **`GET /sw6oidc/health`** is for uptime monitors. It is unauthenticated, reports counts only and makes no outbound calls. It returns 503 when degraded.
- **Run diagnostics** on a provider checks config completeness and runs a live JWKS/discovery probe.
- **Scheduled alerting** runs every 5 minutes. After N consecutive failures it POSTs one webhook alert per outage, with an optional recovery message. The webhook URL is encrypted and SSRF-checked.

### Security hardening you get for free

- **SSRF protection.** Every IdP URL is checked on save (HTTPS, public IPs only), and `NoPrivateNetworkHttpClient` re-checks every connection and redirect at runtime.
- **Encrypted secrets.** Client secrets and webhook URLs are encrypted at rest (libsodium, key derived from `APP_SECRET`) and are write-only over the Admin API.
- **Rate limiting.** OIDC callbacks and Back-/Front-Channel endpoints allow 10 **failed** requests per minute per client IP. Legitimate denials don't count.
- **CSP.** IdP origins are appended to CSP directives the shop already declares. The plugin never adds a directive.

### Operations: config export/import

```bash
bin/console sw6oidc:config:export -o providers.json
bin/console sw6oidc:config:import -i providers.json --dry-run
```

The export is versioned JSON covering providers, mappings and access rules. ACL roles and customer groups are resolved by id, then by unique name.

The secret is omitted by default. `--keep-encrypted` keeps the stored envelope, which only imports where `APP_SECRET` is identical. `--plaintext` exports the decrypted value.

**Use case:** promoting a tested provider setup from staging to production.

### Extension points (`src/Event/`)

| Event | When | What you can do |
|---|---|---|
| `AttributeMappingCompletedEvent` | After mapping, every OIDC login | Replace the `MappedProfile` (email is re-validated afterwards) |
| `CustomerBeforeCreateEvent` / `AdminBeforeCreateEvent` | Just before JIT create | Change the create payload |
| `CustomerAfterCreateEvent` / `AdminAfterCreateEvent` | After create + binding | React (read-only); not fired for existing accounts |

`AdminBeforeCreateEvent` can set `admin` and `aclRoles`. A listener can therefore bypass the two-gate superadmin rule. This is intentional power, so treat such listeners as security-sensitive code.

### What it deliberately is not

It is an OIDC **client**, not an OAuth2 provider for third parties. It doesn't replace password login unless you set the per-provider "disable" flags, so SSO and passwords coexist by default. Passkey attestation is `none`: the plugin favors broad device compatibility over verifying authenticator provenance.

---

## 5. Gotchas: edge cases, limitations, and things that will bite you

### Keep `APP_SECRET` stable

The key that encrypts client secrets and webhook URLs is derived from `APP_SECRET`. If you rotate it, entities still load, but `TokenExchangeService` throws `ClientSecretUnavailableException` and every provider's secret has to be entered again.

The same applies to `--keep-encrypted` exports: they only import into an installation with the identical `APP_SECRET`. A short `APP_SECRET` (e.g. from a template) also breaks admin token signing in tests.

### State, PKCE and nonce are genuinely single-use

The flow context is read with an atomic get-and-delete. Re-hitting a callback URL after a successful **or failed** first attempt always fails with `InvalidStateException`. When debugging, restart from `/sw6oidc/login`. Don't replay the callback.

### The atomic cache is only atomic with Redis

Without `SW6OIDC_REDIS_DSN`, one-time tokens use a sequential get-then-delete on `cache.app`. That's fine on a single node, but it isn't race-safe across several nodes.

The backend is selected **at runtime** on purpose, not in a compiler pass, so the choice isn't frozen into the cached container and changing the variable needs no cache clear. Redis errors fall back per call. If you see intermittent "state already used" errors under load, check this variable first, then look for "Redis … failed" warnings in the log.

### Order matters: normalize groups *before* flattening claims

`OidcCallbackProcessor` normalizes the raw groups claim before it calls `ClaimsNormalizer::flatten()`. ZITADEL sends roles as nested objects (`{"Engineering": {"orgId": "…"}}`). Flattening first would turn the group names into dotted paths like `roles.Engineering.orgId` and lose them. Keep this order if you touch claim handling.

Flattening also has limits: depth 5 and 2000 keys, beyond which it throws `ClaimsTooComplexException`.

### `AdminOidcGrant` trusts its caller completely

The grant has no password or credential check. It reads a pre-verified user id from a PSR-7 request attribute and issues a token. That's safe only because every caller verifies the user first, via a JWT-verified OIDC login or a verified WebAuthn assertion. If you add a caller, you own that verification.

### Never alias the plugin's OAuth2 server to the League class id

`AdminAuthorizationServerFactory` registers the server under the plugin's own service id. Shopware core already has its own `League\OAuth2\Server\AuthorizationServer` for `/api/oauth/token`. A "cleanup" that aliases the bare class id breaks one of the two.

Admin passkey login must also set `client_id=administration` on the request manually. League validates the client before the grant's `validateUser()` runs.

### Admin sessions can't be ended individually

Shopware admin access tokens are stateless JWTs, and revoking one is a no-op in core. Refresh-token ids also rotate on every refresh. So force logout and Back-/Front-Channel Logout for an **admin** end **all** of that admin's sessions. They do this by revoking all refresh tokens and bumping `user.last_updated_password_at` (the password itself is untouched). Customers, by contrast, lose exactly the one affected context.

### The session registry only knows what it saw

IdP-initiated logout only finds sessions created after the registry shipped. A login without a `sub` isn't registered, and passkey logins never are, because there's no IdP session. Registry entries expire after 24h. The index lists use unlocked read-modify-write and are capped at 50 entries per key. A race can make a logout *miss* a session, but it can never grant access.

### Passkey session-kill has a 10-minute window

Deleting the passkey that authenticates the current admin session can force that session out. `AdminPasskeyLoginTokenTracker` implements this by keying on the access token's `jti`. After a silent refresh, a new `jti` exists that the tracker never learns about, and the guarantee stops applying. This is an accepted scope limit, so don't advertise it as "delete a passkey to kill every session".

### Passkeys are bound to one domain

A passkey is cryptographically bound to one Relying Party ID, which is effectively the domain. Changing `passkeyRpId` or moving the shop to a new hostname invalidates every registered passkey. Users must register again.

### webauthn-lib 5.x: you own credential lookup and persistence

webauthn-lib 5.x has no repository contract. `PasskeyAuthenticationService` loads the `CredentialRecord` itself and must persist what `check()` returns via `updateAfterAssertion()`. If that step is skipped, signature-counter replay detection is silently disabled.

The ceremony caches hold **raw inputs**, not serialized options. The options are rebuilt on verify. This started as a workaround for a 4.x base64 bug and was kept because it doesn't depend on symmetric (de)serialization.

### "Disable password login" is shop-wide

`PasswordLoginPolicy` returns true if *any* active provider serving that login type has the flag set. Providers aren't sales-channel scoped, so one provider's flag disables password login for every sales channel.

Superadmin sync is also one-way: `sync_admin_role_on_sso` only ever **grants** superadmin and never revokes it. Downgrading an admin is a manual step. This avoids a claims glitch locking out the only superadmin.

### `OidcCustomerLoginRoute` is not a decorator, on purpose

`OidcCustomerLoginRoute` extends `AbstractLoginRoute`, but its `getDecorated()` throws. This keeps the real login route doing password checks for everyone else, while `PasswordLoginGuardLoginRoute` separately decorates the core route to enforce the disable flag.

Storefront logout needs two classes, a route decorator and a response subscriber. A `CustomerLogoutEvent` listener can't work, because core swaps the context token *before* it dispatches that event.

### The admin login JS reload is load-bearing

After the nonce exchange, the `sw-login` override forces a router push and sometimes a full reload. Without it, a login that didn't come from core's own component leaves the SPA's modules and menu uninitialized, and the dashboard stays blank.

`AdminEntrypointsExtension` exists for a related reason. Shopware normally skips plugin JS on the pre-auth login screen, so this Twig extension loads the plugin's admin bundle there explicitly.

### Unwired leftovers

Check that code is actually used before you build on it:

- The **"Enable debug logging"** plugin setting does nothing. Only `SW6OIDC_LOG_LEVEL` controls verbosity, and it defaults to `debug`, which is verbose in production.
- `ClaimsNormalizer::extractEmail()` has no callers.

### Smaller traps

- **Missing nonce.** If no nonce was expected, `JwtVerifier` logs a warning and *skips* the nonce check instead of failing.
- **No `id_token`.** If the token response has no `id_token`, the flow relies on userinfo alone, and userinfo wins on claim collisions anyway.
- **Placeholder addresses.** Customers created without address claims get `-` placeholders in the billing address. Address sync only updates the existing default billing address and never creates one.
- **Unresolvable hosts.** The write guard blocks unresolvable hosts even in insecure mode. Test fixtures need resolvable hostnames or IP literals.
- **Front-channel logout.** It needs both `iss` and `sid`, because SameSite cookies never reach a cross-site iframe.
- **Live login test.** It does **not** apply access-control rules. It only reports claims.

### Test coverage and its blind spots

- **Unit tests** (`composer test`, no kernel) cover the OIDC core, provisioning, WebAuthn ceremonies against the real 5.x validators, and every security component.
- **Integration tests** (`SHOPWARE_PROJECT_ROOT=/path/to/shop composer test-integration`) run a real kernel and database with Dex (`tests/Integration/docker-compose.yml`). They cover Back-Channel Logout, full Storefront and Admin OIDC logins, and access rules. They must use the **shop's** PHPUnit and autoloader, because the plugin's own `vendor/` contains a second `shopware/core`. `tests/Integration/README.md` has the setup details.

Neither suite covers:

- the Administration Vue code or Storefront JS
- passkey logins end to end in a real browser
- quirks of real IdPs other than Dex

After you change controllers, `services.xml`, templates or Vue code, do a manual login round-trip: Storefront and Admin, OIDC and Passkey.

`composer ci` runs the same gates as CI: PHPCS, PHPStan level 5, Psalm level 4, Rector dry-run, then unit tests.

---

## 6. Future Improvements

Roughly in order of risk reduction per unit of effort:

1. **Wire up or remove the debug-logging toggle.** It suggests control that doesn't exist. Also consider defaulting `SW6OIDC_LOG_LEVEL` to `info` in production, since `debug` is noisy and logs claim-level detail.
2. **Delete dead code.** Remove `ClaimsNormalizer::extractEmail()` or wire it in.
3. **Add browser-level end-to-end tests** (Playwright or similar) for the Admin SPA overrides and passkeys, using a virtual WebAuthn authenticator. The admin login reload, inactivity re-login and password reconfirmation have no automated coverage yet.
4. **Warn about multi-node setups without Redis.** Surface a missing `SW6OIDC_REDIS_DSN` in the health endpoint and diagnostics (e.g. when several app servers share the DB), instead of relying on documentation.
5. **Add an `APP_SECRET` rotation command.** For example, `sw6oidc:secrets:reencrypt --old-secret=…` would re-encrypt stored secrets, instead of every provider's secret having to be re-entered.
6. **Make admin session termination granular.** Tracking refresh-token lineage per login (or storing session ids in a custom token claim) would let force logout and Back-Channel Logout end one admin session instead of all of them. The same work would close the passkey tracker's refresh gap.
7. **Scope providers and password-login flags per sales channel.** Today one provider's flag affects every sales channel. Multi-brand shops will eventually want per-channel control.
8. **Make registry index updates atomic.** Redis sets, or locking, would replace the unlocked read-modify-write and remove the "a race can miss a session" caveat.
9. **Support optional passkey attestation.** An opt-in attestation policy with an allow-list (MDS/AAGUID) would help admin accounts in regulated environments.
10. **Add a dev environment.** A committed docker-compose Shopware setup (the integration suite already brings Dex) would give new contributors a disposable shop instead of a personal staging install.
11. **Prepare for Shopware 6.8.** The plugin pins `<6.8`. The admin overrides (`sw-login`, `sw-inactivity-login`, `sw-profile`) and the second League server are the parts most likely to break on a major upgrade, so they're the first places to check.

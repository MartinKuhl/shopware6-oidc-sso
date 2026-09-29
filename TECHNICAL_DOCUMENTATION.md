# Technical Documentation — Shopware 6 OIDC & Passkey SSO

This document is a guided tour for a developer picking up this codebase for the first time. It assumes you know PHP, Symfony-style DI, and OAuth2/OIDC concepts in general, but assumes nothing about this specific plugin or Shopware's plugin conventions. For a terse architecture reference once you're oriented, see [CLAUDE.md](CLAUDE.md); for end-user/admin setup instructions, see [README.md](README.md).

---

## 1. Overview

### What it does

This is a Shopware 6 plugin (`MartinKuhl\Sw6Oidc`) that adds two independent ways to log in without a Shopware-managed password:

1. **OIDC (OpenID Connect)** — delegate authentication to an external Identity Provider (IdP) like Keycloak, Authelia, Okta, Azure AD, or Zitadel. Works for both **Storefront customers** and **Administration (backend) users**, and can auto-create accounts on first login (JIT provisioning), assigning customer groups or ACL roles based on group claims from the IdP.
2. **Passkey (WebAuthn/FIDO2)** — a completely separate passwordless login method. No external IdP involved; the browser's platform authenticator (Face ID, Windows Hello, a security key, etc.) proves the user's identity, and the plugin just verifies a cryptographic signature.

Both mechanisms end the same way: they hand a verified identity to Shopware's *native* authentication machinery (customer sessions on the Storefront side, a real OAuth2 access/refresh token pair on the Administration side), so nothing downstream needs to know SSO happened at all.

### Why it exists

Shopware ships with password-based auth only. Organizations that already run a corporate IdP (for SSO across many internal tools) don't want a separate Shopware password to manage, and want centralized MFA/access-revocation policies to actually apply to their shop's admin panel and customer accounts too. Passkeys solve a related but distinct problem — passwordless login for people who don't have (or don't want to be routed through) a corporate IdP at all.

### Project status

This is early-stage software: version `0.1.0` plus unreleased changes (see `CHANGELOG.md`), MIT-licensed, unit-tested but with no integration tests against a live Shopware instance. Read [Section 5 — Gotchas](#5-gotchas--edge-cases--limitations) before you assume any given feature is fully wired end to end.

---

## 2. Structure

### How the plugin is organized

```
src/
├── Sw6Oidc.php                  # Plugin bootstrap (Shopware\Core\Framework\Plugin)
├── Migration/                   # DB schema (one migration creates 5 tables)
├── Service/
│   ├── Oidc/                    # OIDC protocol machinery — the core flow
│   ├── AdminAuth/               # Bridges OIDC/Passkey into Shopware's admin OAuth2 server
│   ├── Passkey/                 # WebAuthn ceremony (registration + assertion)
│   ├── Provisioning/            # Claim → Shopware user/customer mapping & JIT creation
│   ├── Cache/                   # Atomic (get-and-delete) cache abstraction for one-time tokens
│   └── Logging/                 # Dedicated Monolog channel + sensitive-data scrubbing
├── Storefront/
│   ├── Controller/               # Customer-facing HTTP endpoints
│   └── Service/                  # OidcCustomerLoginRoute (passwordless login route)
├── Controller/Api/               # Admin-facing HTTP endpoints (OIDC + Passkey)
├── Twig/                         # Injects SSO buttons / admin login JS into templates
└── Resources/
    ├── config/                   # services.xml (DI), config.xml (plugin settings UI), routes.xml
    └── ...                       # Administration SPA assets (provider management module)
```

### The three-layer mental model

It helps to think of the codebase in three layers, each with a distinct job:

1. **Protocol layer** (`Service/Oidc/`, `Service/Passkey/`) — talks to the outside world (the IdP, or the browser's WebAuthn API). Doesn't know anything about Shopware customers or admins; deals in tokens, claims, and credentials.
2. **Provisioning layer** (`Service/Provisioning/`) — translates a verified identity (a set of claims, or a WebAuthn credential) into a Shopware customer or admin user: finds an existing match, creates one if allowed, maps attributes, resolves group/role membership.
3. **Bridge layer** (`Controller/*`, `Storefront/Service/OidcCustomerLoginRoute`, `Service/AdminAuth/`) — the HTTP-facing glue that ties the two layers together and hands the result to Shopware's real authentication system. This is where the "OIDC/Passkey verified you, now here's a real Shopware session/token" handoff happens.

Two flows run through all three layers independently: **Storefront/customer** and **Administration/admin**. They share the protocol and provisioning layers almost entirely — `OidcCallbackProcessor` explicitly processes both — but the bridge layer differs a lot, because Shopware's customer sessions and its admin OAuth2 tokens are fundamentally different mechanisms (see Section 4).

### Data model

Five DB tables, all created by a single migration (`Migration1730000001CreateOidcSchema`):

| Table | Purpose |
|---|---|
| `sw6oidc_provider` | One row per configured IdP — credentials, endpoints, behavior flags |
| `sw6oidc_attribute_mapping` | Per-provider claim-name → Shopware-field mapping |
| `sw6oidc_role_mapping` | Per-provider OIDC-group → ACL role / customer group mapping |
| `sw6oidc_user_provider` | Permanent binding: which provider first authenticated which account |
| `sw6oidc_passkey_credential` | One row per registered WebAuthn credential |

Providers are managed as **DAL entities** through a custom Administration module — not through Shopware's usual `config.xml` plugin-settings screen (only Passkey's two global toggles and RP name/ID live there).

---

## 3. Quick Start

Getting OIDC login working end to end, in three steps:

### Step 1 — Install the plugin

```bash
composer require martinkuhl/shopware6-oidc-sso
bin/console plugin:refresh
bin/console plugin:install --activate Sw6Oidc
bin/console cache:clear
```
This runs the migration automatically and creates the 5 tables listed above.

### Step 2 — Configure one provider

In the Administration, open the plugin's own **OIDC & Passkey SSO** provider management module and add a provider: give it an **App Name**, **Client ID**/**Client Secret** from your IdP, and either a **Well-Known Config URL** (to auto-populate endpoints via discovery) or the endpoint URLs by hand. Set **Login Type** to `customer`, `admin`, or `both`, and toggle **Auto Create Customer**/**Auto Create Admin** if you want JIT provisioning. Save.

### Step 3 — Register the redirect URI(s) with your IdP, then test

Register whichever of these your IdP client needs:
- `https://your-shop.com/sw6oidc/callback` (Storefront)
- `https://your-shop.com/api/sw6oidc/admin/callback` (Admin)

Then click the SSO button on the corresponding login page (`/sw6oidc/login` for the Storefront, or the Administration login screen for admin) and walk through the flow. If claims aren't landing where you expect, set `SW6OIDC_LOG_LEVEL=debug` and check the plugin's dedicated log output — see Section 5 for how to read it.

That's OIDC. Passkeys are a separate, independent setup: enable the two toggles under the plugin's Passkey settings, and users register their own credential from their account page — no provider configuration needed at all.

---

## 4. Functionalities and Use Case

### What problem each piece solves

**OIDC login (Storefront)** — a customer clicks "Login with SSO," is bounced to the IdP, comes back with an authorization code, and the plugin exchanges it for tokens, verifies the ID token's JWT, fetches userinfo, and either logs in a matching customer or creates one. Use case: B2B storefronts where customer identity is managed by a corporate directory rather than self-service registration.

**OIDC login (Admin)** — same protocol pipeline, different bridge. Because Shopware's Administration SPA authenticates via a real OAuth2 access/refresh token pair (not a server-side session), the plugin can't just "log the user in" the way it does for customers. Instead it runs a **second, plugin-owned `league/oauth2-server` instance**, wired to Shopware core's *actual* client/token repositories, and mints a genuine OAuth2 token response after independently verifying the admin via OIDC. The SPA never knows the token didn't come from a password grant. Use case: giving internal staff SSO into the backend using the same corporate IdP, with centralized MFA/revocation.

**Passkey login (both)** — a user registers a device credential once (from their account/profile page while already logged in) and from then on can authenticate with a fingerprint/face/PIN/security key instead of a password. No IdP involved at all — this is a self-contained credential the plugin stores and verifies itself. Use case: reducing password fatigue and phishing risk for accounts that don't have (or don't want) a corporate SSO story, or as a stronger second option alongside OIDC.

**JIT provisioning + attribute/group mapping** — rather than requiring every SSO user to already exist in Shopware, the plugin can create the account on first login and populate it from claims (name, address, etc.), and assign it a customer group or ACL role based on IdP group membership. Use case: onboarding is driven entirely from the IdP side — add someone to an "Engineering" group there, and their first login creates a Shopware admin account with the right role, no manual Shopware-side account creation ever needed.

**Per-user IdP binding** — once an account is first authenticated by a given provider, it stays bound to that provider. Use case: prevents a scenario where the same email exists in two IdPs with different security postures — without binding, an attacker who compromises the weaker IdP could log in as a user whose "real" identity is meant to be governed by the stronger one.

**RP-Initiated Logout + token revocation** — logging out of the Storefront also redirects to the IdP's own logout endpoint and revokes the access token server-side (RFC 7009), so "logging out" actually ends the session at the IdP too, not just locally.

### What it deliberately does *not* try to be

It's not a general-purpose OAuth2 *provider* (Shopware isn't issuing tokens to third parties here) — it's an OAuth2/OIDC *client*, consuming someone else's IdP. It also doesn't replace Shopware's password auth outright unless you explicitly disable it per provider (`disable_non_oidc_*_login`) — SSO and password login coexist by default.

---

## 5. Gotchas — edge cases, limitations, and things that will bite you

### Ordering: groups must be normalized *before* claims are flattened

`OidcCallbackProcessor` extracts the raw groups claim and runs it through `ClaimsNormalizer::normalizeGroups()` **before** calling `ClaimsNormalizer::flatten()` on the rest of the claims. This isn't arbitrary — some IdPs (Zitadel in particular) send group membership as a nested object like `{"Engineering": {"orgId": "..."}}` rather than a flat array. If you flattened first, the group names would become dot-notation leaf keys (`groups.Engineering.orgId`) and the actual group names would be lost. If you're modifying claim handling, preserve this order.

### State/nonce/PKCE are genuinely single-use

The authorization flow context (state, PKCE verifier, nonce) is stored via an atomic get-and-delete cache read, not a plain read — retrying a callback with the same `state` after the first successful (or failed) consumption will always fail with `InvalidStateException`, even if the first attempt errored out before completing. If you're debugging a failed callback by re-hitting the same URL, it will *never* work the second time — you have to restart the flow from `/sw6oidc/login`.

### Admin login UX requires a forced page reload

The Administration SPA has to be manually kicked (a router push plus, in some cases, a full `window.location.reload()`) after the OIDC token exchange completes, because a token obtained outside of Shopware's normal login component leaves the SPA's modules/menu uninitialized otherwise. If you ever refactor the `sw-login` JS override, be aware this reload isn't cosmetic — removing it produces a blank/broken dashboard after a successful login.

### The admin OAuth2 server must never be aliased to the bare League class id

`AdminAuthorizationServerFactory`'s output is deliberately registered under the plugin's own service id, **not** aliased to `League\OAuth2\Server\AuthorizationServer`. Shopware core already has its own instance of that class for `/api/oauth/token`. If a future refactor "cleans up" this DI wiring by aliasing to the generic class id, it will silently break (or get broken by) Shopware's own OAuth2 token endpoint.

### `AdminOidcGrant` trusts the caller completely

There is no password check, no credential check, inside the grant itself — it reads a pre-verified user id off a PSR-7 request attribute and issues a token for that user, full stop. This is safe *only* because every caller sets that attribute strictly after independently verifying the user via OIDC (JWT-verified claims) or Passkey (a verified WebAuthn assertion). If you ever add a new caller of this grant, you are personally responsible for verifying the user *before* calling it — the grant will not save you.

### webauthn-lib 5.x: the caller owns credential lookup and persistence

- **Both ceremony caches hold raw inputs, not serialized options.** Registration and login rebuild the options object from the cached challenge/rpId/user/allow-list via the constructor on verify. This originally worked around a webauthn-lib 4.9.3 base64 round-trip bug (url-safe encode vs standard decode of the user handle); it is kept because it is simpler and doesn't depend on the library's (de)serialization being symmetric.
- **There is no repository contract anymore.** `PasskeyAuthenticationService` looks the `CredentialRecord` up itself, passes it into `check()`, and must persist the returned record (`PasskeyCredentialRepository::updateAfterAssertion()`) — skipping that silently disables signature-counter replay detection.
- **Stored credentials are the library's normalized `CredentialRecord` JSON.** Rows written by 4.x have the same shape and deserialize unchanged (covered by `PasskeyCredentialRepositoryTest`).

### The passkey session-kill feature has a real time-boxed gap

`AdminPasskeyLoginTokenTracker` lets an admin force-logout a session if they delete the exact passkey that's currently authenticating it — but it's keyed by the access token's `jti`, with a 900s TTL matching the 10-minute access-token lifetime. Once that token silently refreshes (via the refresh token), a new `jti` is minted that the tracker never learns about, and the "kill this session" guarantee silently stops applying. This is a known, accepted scope limit — don't advertise this as "delete a passkey to instantly and permanently kill any session using it."

### One UI toggle is not wired to any logic

Before you build a feature "on top of" one of these, check that it's actually read anywhere:
- The **"Enable debug logging"** toggle in the plugin's config UI does nothing — actual log verbosity is controlled entirely by the `SW6OIDC_LOG_LEVEL` environment variable.
- `ClaimsNormalizer::extractEmail()` has no callers anywhere in the codebase.

### Client secrets are encrypted with a key derived from APP_SECRET

`sw6oidc_provider.client_secret` is a `Sw6OidcEncryptedField`: its serializer encrypts on write and decrypts on read, so entity code only ever sees plaintext. Rotating `APP_SECRET` makes the stored envelopes undecryptable — hydration still succeeds (the envelope is passed through), and `TokenExchangeService` then throws `ClientSecretUnavailableException` instead of sending ciphertext to the IdP. The field has no `ApiAware` flag, so Admin API reads never return it.

### No back-channel logout, no admin-side RP-initiated logout

If a user signs out at the IdP directly (not through Shopware), nothing tells Shopware to end that session — there's no OIDC Back-Channel Logout endpoint implemented. And logging an admin out of the Administration panel does not redirect to the IdP to end that session there either — only the Storefront/customer logout flow does the full IdP round-trip.

### The atomic cache is only atomic with Redis

Without `SW6OIDC_REDIS_DSN`, `RedisAtomicCache` delegates to a sequential get-then-delete against Shopware's app cache — fine for a single node, but not safe against a genuine race on the same key across concurrent requests on multiple nodes. Multi-node deployments must set `SW6OIDC_REDIS_DSN`; the backend is selected at runtime, so no cache clear is needed after changing it. If you're debugging an intermittent "state token already used" error under load on a multi-node deployment, check that variable first — and the `sw6oidc` log for "Redis ... failed" warnings.

### Passkeys are locked to one domain

A passkey is cryptographically bound to a single Relying Party ID (essentially, the domain). Changing the RP ID override, or serving the shop under a new hostname, invalidates every previously registered passkey — there is no migration path other than re-registration.

### Unit tests only — no end-to-end safety net yet

The unit suite (`tests/Unit/`) covers the OIDC core (state/PKCE, JWT verification, claims normalization), both provisioning services, group mapping and bindings, the WebAuthn ceremonies against the real webauthn-lib validators (via an in-process software authenticator), and every security/config component — all without a Shopware kernel. What it can't catch: wiring and DAL behaviour inside a real Shopware instance, admin/storefront JS, and real IdP quirks. After changing controllers, `services.xml`, templates or Vue code, do a manual end-to-end login (Storefront + Admin, OIDC + Passkey) against a real IdP.

---

## 6. Future Improvements

Roughly in order of "would most reduce risk right now":

1. **Wire up or remove the debug-logging toggle.** The "Enable debug logging" config toggle still does nothing (verbosity comes from `SW6OIDC_LOG_LEVEL`) and creates a false impression of functionality.
2. **Add integration tests against a real (or containerized) Shopware instance.** Right now correctness of the actual login flows rests entirely on manual testing. Even a small integration suite covering the happy path for customer OIDC login, admin OIDC login, and one passkey round-trip would catch the regressions unit tests structurally can't.
3. **Implement OIDC Back-Channel Logout and admin-side RP-initiated logout**, bringing session termination guarantees in line with the Storefront/customer flow and closing the gap where an IdP-side logout or admin-side logout doesn't propagate.
4. **Consider a real dev/test Shopware environment** (a `docker-compose.yml` or similar, matching the Magento sibling's `Test/docker-compose.test.yml`) so new contributors — and CI, eventually — can spin up a disposable Shopware instance to exercise the flows end to end rather than relying on a personal staging install.

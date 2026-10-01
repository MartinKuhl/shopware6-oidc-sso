# Code Review: `martinkuhl/shopware6-oidc-sso`

| | |
|---|---|
| **Revision** | 2 (re-review) |
| **Review date** | 2026-09-30 (revision 1: 2026-09-29) |
| **Reviewed commit** | `6986e4f` (revision 1 reviewed `c2d68a6`; 33 commits and roughly +8.4k lines since) |
| **Scope** | Everything under `src/`: PHP, DI config, migrations, admin and storefront JS and Twig. Also `composer.json` and the CI/tooling config. |
| **Verified against** | Shopware `6.7.14.2`, Symfony 7.4, DBAL 4.4 and webauthn-lib 5.3.9, as installed in `/var/www/html/vendor` |
| **Reviewer stance** | Harsh on purpose. Assume every finding reaches production unless it is fixed. |

> **Note on "Magento best practices":** this is a Shopware 6 plugin, so it is reviewed against **Shopware 6.7 / Symfony 7 conventions**: DAL, ACL, route scopes, decoration, flows, `services.xml`. Where the code copies a pattern from the sibling Magento module and that pattern doesn't fit Shopware, the finding says so.

Backend paths are relative to `src/`. Frontend paths are relative to `src/Resources/`. Admin JS paths start at `app/administration/src/`. Line numbers point to commit `6986e4f`.

**How to read this revision**
- Every finding from revision 1 keeps its ID and has a status: **OPEN**, **PARTIAL**, **FIXED** or **OBSOLETE**.
- Findings new in revision 2 are marked **NEW**. Backend ones are numbered `N-C*`, `N-H*`, `N-M*`, `N-L*`. Frontend ones are numbered `F-N*`.
- Fixed findings are collected in [Resolved since revision 1](#resolved-since-revision-1) and are not repeated in the severity sections.

**Tooling:** PHPStan, Psalm and PHPUnit could **not** be run for this revision, because the checkout has no `vendor/` directory. No test results are claimed.

---

## Status on branch `fix/code-review-rev2`

Added after the fixes, for tracking. Line numbers in the findings below still refer to `6986e4f`. Future Improvements 1–4 (TECHNICAL_DOCUMENTATION.md): 1 in `53bb4a5`, 2 in `53bb4a5`, 3 in `e2555ee`, 4 in `0d8f457`.

| Finding | Status | Commit | Note |
|---|---|---|---|
| C1 | Fixed | `639f1e2` | Providers resolved per login type; callback checks the flow type. |
| C2 | Fixed | `639f1e2` | Binding by (provider, iss, sub); verified email + opt-in for linking; never superadmins. |
| F-C1 | Fixed | `f19a344` | isSso() decorator and template copy removed; step-up in core's verify modal. |
| H1 | Fixed | `639f1e2` | Grant rejects missing/inactive users. |
| H2 | Fixed | `f19a344` | `verify-session` removed; StepUpService. |
| H3 | Fixed | `53bb4a5` | Role sync replaces roles; opt-in superadmin revocation. |
| H4 | Fixed | `53bb4a5` | RelayStateValidator. |
| H6 | Fixed | `f19a344` | UV required; ceremonies 404 when disabled. |
| H7 | Fixed | `f19a344` | Registration needs step-up / recent login; event + audit. |
| H8 | Fixed | `d718a39` | Residues 3–6 done. |
| H9 | Fixed | `53bb4a5` | Level handler, rotation, masking. |
| H10 | Fixed | `53bb4a5` | TTLs from shopware.api.*. |
| H11 | Fixed | `c66c944` | Residual: Store API logout returns the IdP URL. |
| N-H1 | Fixed | `d718a39` | UserRepository decorator. |
| N-H2 | Fixed | `c66c944` | kid-based selection, per-kid cooldown. |
| N-H3 | Fixed | `0d8f457` | DB-backed registry. |
| F-H1 | Fixed | `73d4202`, `b4c4c9a` | CI `assets` job added; storefront `dist/` rebuilt. |
| F-H2 | Fixed | `53bb4a5` |  |
| F-H3 | Fixed | `73d4202`, `b4c4c9a` | |
| F-H4 | Fixed | `73d4202` | Sw6oidcApiService everywhere. |
| F-H5 | Fixed | `73d4202` | acl/index.js. |
| F-H6 | Fixed | `f19a344` |  |
| M1 | Fixed | `127de8d` | BrowserBinding cookie. |
| M2 | Fixed | `639f1e2` |  |
| M3 | Fixed | `53bb4a5` |  |
| M4 | Fixed | `0d8f457` |  |
| M5 | Fixed | `ff4b695` |  |
| M6 | Fixed | `f19a344` |  |
| M7 | Fixed | `c66c944` | PublicError (fixed codes + correlation id). |
| M8 | Fixed | `639f1e2` |  |
| M9 | Fixed | `127de8d` |  |
| M10 | Fixed | `ef23a44` | base64_claims list. |
| M11 | Fixed | `c66c944` |  |
| M12 | Fixed | `53bb4a5` | Residual: max_redirects 0, avatar via guarded client. |
| M13 | Fixed | `ef23a44` |  |
| M14 | Fixed | `c66c944` |  |
| M15 | Fixed | `127de8d` |  |
| M16 | Fixed | `127de8d` | 6.7 still requires default addresses: placeholders kept but flagged. |
| M17 | Fixed | `f19a344` |  |
| M18 | Fixed | `127de8d` | Constrained to ^9.3 (used directly by AdminOidcGrant). |
| M19 | Fixed | `53bb4a5` |  |
| M20 | Fixed | `0d8f457`, `127de8d` |  |
| M21 | Fixed | `639f1e2` |  |
| M22 | Fixed | `53bb4a5` |  |
| N-M1 | Fixed | `f19a344` |  |
| N-M2 | Fixed | `f19a344` |  |
| N-M3 | Fixed | `ff4b695` |  |
| N-M4 | Fixed | `0d8f457` |  |
| N-M5 | Fixed | `c66c944` |  |
| N-M6 | Fixed | `c66c944` |  |
| N-M7 | Fixed | `c66c944` |  |
| N-M8 | Fixed | `127de8d` |  |
| N-M9 | Fixed | `d718a39` |  |
| N-M10 | Fixed | `ef23a44` |  |
| N-M11 | Fixed | `ef23a44` |  |
| N-M12 | Fixed | `53bb4a5` |  |
| N-M13 | Fixed | `0d8f457` |  |
| N-M14 | Fixed | `0d8f457` |  |
| N-M15 | Fixed | `ff4b695` |  |
| N-M16 | Fixed | `639f1e2` |  |
| N-M17 | Fixed | `f19a344` |  |
| F-M1 | Fixed | `f19a344` |  |
| F-M2 | Partial | `73d4202` | Core has no narrower block; full copy kept, with a re-sync checklist. |
| F-M3 | Fixed | `73d4202` | Native WebAuthn JSON parsing with fallback; the two codec copies remain (separate builds). |
| F-M4 | Fixed | `73d4202` |  |
| F-M5 | Fixed | `73d4202` |  |
| F-M6 | Fixed | `73d4202` |  |
| F-M7 | Fixed | `73d4202` |  |
| F-M8 | Fixed | `73d4202` |  |
| F-M9 | Fixed | `73d4202` |  |
| F-M10 | Fixed | `73d4202` |  |
| F-M11 | Fixed | `73d4202` |  |
| F-M12 | Fixed | `73d4202` |  |
| F-M13 | Fixed | `f19a344` |  |
| F-M14 | Partial | `73d4202` | Pinia store used; the separate pre-auth bundle is not done. |
| F-M15 | Fixed | `53bb4a5` |  |
| F-N1 | Fixed | `f19a344` |  |
| F-N2 | Fixed | `73d4202` |  |
| F-N3 | Fixed | `73d4202` |  |
| F-N4 | Fixed | `d718a39` |  |
| F-N5 | Fixed | `f19a344` |  |
| F-N6 | Fixed | `73d4202` |  |
| F-N7 | Fixed | `73d4202` |  |
| F-N8 | Fixed | `73d4202` |  |
| F-N9 | Fixed | `f19a344` |  |
| F-N10 | Fixed | `f19a344` |  |
| F-N11 | Fixed | `73d4202` |  |
| F-N12 | Fixed | `73d4202` |  |
| F-N13 | Fixed | `f19a344` |  |
| F-N14 | Fixed | `73d4202` |  |
| F-N15 | Fixed | `73d4202` |  |
| F-N16 | Fixed | `73d4202` |  |
| F-N17 | Fixed | `73d4202` |  |
| N-L1 | Fixed | `c66c944` |  |
| N-L2 | Fixed | `0d8f457`, `c66c944` |  |
| N-L3 | Fixed | `0d8f457` |  |
| N-L4 | Fixed | `53bb4a5` |  |
| N-L5 | Fixed | `53bb4a5` |  |
| N-L6 | Fixed | `53bb4a5` |  |
| N-L7 | Fixed | `639f1e2` |  |
| N-L8 | Fixed | `53bb4a5` |  |
| N-L9 | Fixed | `53bb4a5` |  |
| N-L10 | Fixed | `0d8f457` |  |
| N-L11 | Fixed | `ef23a44` |  |
| N-L12 | Fixed | `127de8d` |  |
| N-L13 | Fixed | `ef23a44` |  |
| N-L14 | Fixed | `0d8f457` |  |
| N-L15 | Fixed | `127de8d` |  |
| N-L16 | Fixed | `f19a344` |  |
| N-L17 | Fixed | `639f1e2` |  |
| N-L18 | Fixed | `d718a39` |  |
| N-L19 | Fixed | `0d8f457` |  |
| N-L20 | Fixed | `0d8f457` |  |
| N-L21 | Fixed | `0d8f457` |  |
| L1 | Fixed | `127de8d` |  |
| L2 | Fixed | `639f1e2` |  |
| L3 | Fixed | `f19a344`, `53bb4a5`, `127de8d` | Includes Future Improvement 2 (extractEmail). |
| L4 | Fixed | `53bb4a5` |  |
| L5 | Fixed | `127de8d` |  |
| L7 | Fixed | `f19a344` | credential_id_hash + user_handle index. |
| L8 | Fixed | `127de8d` |  |
| L9 | Fixed | `127de8d` |  |
| L10 | Fixed | `639f1e2` |  |
| L11 | Fixed | `127de8d` |  |
| L12 | Fixed | `127de8d` |  |
| L13 | Deferred | — | Autowiring the whole services.xml needs a container compile in a real shop; not done blind. |
| L14 | Fixed | `127de8d` |  |
| L15 | Fixed | — | PHPCS, PHPStan, Psalm, Rector and PHPUnit run green on every phase commit. |
| L16 | Fixed | `0d8f457` |  |
| L17 | Fixed | `639f1e2` |  |
| L18 | Fixed | `ef23a44` |  |
| Rev 1 frontend Lows | Fixed (mostly) | `73d4202` | Untranslated diagnostics detail text from the backend remains English. |

H5, M12 (base), L6 and H11 (base) were already fixed in revision 2 (see [Resolved since revision 1](#resolved-since-revision-1)).

---

## Summary

### What changed since revision 1

The 33 commits since revision 1 mostly **added features**: back-channel and front-channel logout, a session registry, a session activity log, health checks and alerting, claims-based access control, attribute transforms, config export/import, CSP, and the webauthn-lib 5.3 upgrade. Few of them **fixed findings**. Where fixes landed, they are good work:
- `client_secret` is encrypted at rest and write-only (H5).
- There is a runtime SSRF guard on every outbound client (M12).
- Password-login enforcement exists (H8, partially).
- Storefront logout was redesigned (H11).
- The JWKS rotation refetch (M11, partially) and the Redis atomic cache wiring (M4, partially) are in.

**Every Critical finding from revision 1 is still open, unchanged.** That covers C1, C2 and F-C1. Eight of the eleven backend High findings are also still open, and H8 is only partly fixed. Each new subsystem arrived with its own problems, and three of them are High. The worst:
- Admin password login still works when it is "disabled", by sending a different `Content-Type` (N-H1).
- Anyone can stop the JWKS key-rotation recovery by sending one bad token a minute (N-H2).
- Back-channel logout silently fails to end sessions after a `cache:clear` or after 24 h (N-H3).

### Status of the revision 1 findings

| Severity (rev 1) | Total | Fixed | Partial | Open | Open IDs |
|---|---|---|---|---|---|
| Critical | 3 | 0 | 0 | **3** | C1, C2, F-C1 |
| High | 17 | 2 | 2 | **13** | H1, H2, H3, H4, H6, H7, H9, H10, F-H2, F-H3, F-H4, F-H5, F-H6 |
| Medium | 37 | 1 | 5 | **31** | everything except M12 (fixed) and M4, M5, M11, M14, M20 (partial) |
| Low | 31 | 1 + 6 table rows | 3 | **26** | see the Low section |

### New in revision 2

| Severity | Backend | Frontend | Theme |
|---|---|---|---|
| Critical | 0 | 0 | — |
| High | 3 | 0 | Password-login guard bypass, anyone can stop JWKS key-rotation recovery, back-channel logout misses live sessions |
| Medium | 17 | 8 | Passkey origin/registration binding, DoS through rate limiters, fail-open access rules, secret exfiltration via endpoint edit, wrong-session logout, audit log editable, session hijack via SSO re-login, UI lockout |
| Low | 21 | 9 | Info leaks, weak crypto hygiene, stale state, data retention, UX gaps |

**Verdict: still not production-ready.** Do not deploy this on a shop with real admin accounts until C1, C2, F-C1, H1–H4, N-H1 and N-H3 are fixed. The core design flaw from revision 1 is unchanged: **any active provider's email claim is accepted as proof of identity for any existing account, superadmins included**. There is still no `email_verified` check, no login-type check, no `active` check, and no binding by `sub`. Until that changes, the new logout, session and health features protect the edges of a door that is still open.

---

## Critical

### C1. Any active provider can log into the Administration, even a customer-only IdP — **OPEN (unchanged)**
- **Where:**
  - `Service/Provider/ProviderResolver.php:27-36` (`getActiveById`)
  - `Controller/Api/OidcAdminAuthController.php:148-150`
  - `Storefront/Controller/SendAuthorizationRequestController.php:42-44`
  - `Service/Oidc/OidcCallbackProcessor.php:57-68`
- **What's wrong:** `getActiveById()` only checks `isActive`. Neither the storefront callback nor the admin callback compares `$flow->loginType` with its own endpoint. The only `loginType` check anywhere is for the live test (`OidcProviderAdminController.php:197`). `findOrCreateAdmin()` never looks at the provider's login type either. So a customer-scoped provider (social login, self-registration realm, B2C tenant) is a working admin login.
- **Attack:**
  1. The attacker self-registers `admin@shop.tld` at the customer IdP.
  2. They open `/api/sw6oidc/admin/login?providerId=<customer-provider-id>`.
  3. `findOrCreateAdmin()` finds the existing admin by email, binds it ("first login wins") and issues a full admin token pair.

  `auto_create_admin = false` doesn't help, because it only applies to new accounts.
- **Fix:**
  ```php
  public function getActiveById(string $providerId, string $loginType, Context $context): Sw6OidcProviderEntity
  {
      $criteria = (new Criteria([$providerId]))
          ->addFilter(new EqualsFilter('isActive', true))
          ->addFilter(new EqualsAnyFilter('loginType', [$loginType, 'both']));
      // ...
  }
  ```
  Use it at all three call sites. Pass the expected login type into `OidcCallbackProcessor::process()` and assert `$flow->loginType` there, so the check lives in the shared pipeline.

### C2. Unverified email claims auto-link pre-existing accounts, admins and superadmins included — **OPEN (unchanged)**
- **Where:**
  - `Service/Provisioning/AdminProvisioningService.php:50-75`
  - `Service/Provisioning/CustomerProvisioningService.php:54-72`
  - `Service/Provisioning/UserProviderBindingService.php:56-68`
- **What's wrong:** `grep -rn email_verified src/` still returns nothing. Accounts are still looked up by email only. `bindIfUnbound()` still binds permanently on "first login wins", so the real owner is locked out of SSO afterwards (`ProviderMismatchException`). `sw6oidc_user_provider` still has no `sub` or `iss` column. `sub` is now stored, but only in the logout session registry, never for identity. The new claims-based access-control rules don't mitigate this unless an admin happens to write an `email_verified eq true` rule. That rule also depends on N-M10/N-M11 being fixed.
- **Attack:** as in C1. Even with C1 fixed, any admin-scoped IdP that lets users set their own email (Keycloak with "edit email" enabled, some Authentik or Zitadel setups, any IdP federating social logins) gives a direct takeover of every unbound Shopware account. The unlink action in `OidcUserProviderAdminController` makes this worse, because unlinking reopens the account to "first login wins".
- **Fix:**
  - Reject logins unless `email_verified === true` (configurable per provider, default **on**). Treat a missing claim as unverified.
  - Only auto-link existing accounts after an explicit per-provider opt-in (`link_existing_accounts`, default off). Never auto-link `admin = 1` users. Better: link through a "connect SSO" step after a password login.
  - Bind and look up by `(provider_id, iss, sub)`. Use email only for the explicit linking step.

### F-C1. The `isSso()` decorator switches the whole Users & Permissions module into "native SSO mode" without asking for a password — **OPEN (unchanged)**
- **Where:** `extension/sw-profile/index.js:108-118` (helper at `131-162`)
- **What's wrong:** `ssoSettingsService.isSso()` is still decorated, and every call still hits `/api/sw6oidc/admin/verify-session` (H2). That swaps the session token for a `user-verified` token pair with no re-authentication. Core relies on `isSso()` in the user listing (user deletion skips `verifyUserToken`), in role detail (roles are saved without the password modal), in role listing, and in the profile password card. So any open SSO admin session can edit roles and delete users without proving who is at the keyboard. Parallel calls race on `setBearerAuthentication()`.
- **New consequence:** OIDC admins are routed to the core SSO user detail page. To show the provider row there, the plugin added a 314-line copy of a core template (F-N5).
- **Fix:** do not decorate `isSso`. Override only the `sw-profile-index` save handler. Mint `user-verified` only after a real step-up: a WebAuthn assertion with `userVerification: required`, or an OIDC round trip with `prompt=login&max_age=0` and an `auth_time` check. This is the same fix as H2.

---

## High

### N-H1. **NEW** — Admin password-login block bypassed with `Content-Type: application/x-json`
- **Where:** `Subscriber/AdminPasswordLoginGuardSubscriber.php:42`
- **What's wrong:** the guard reads `$request->request->get('grant_type')`.
  - Core's `JsonRequestTransformerListener` only fills that bag when the header **starts with `application/json`**.
  - `/api/oauth/token` doesn't read that bag. `AuthController::token` builds a PSR-7 request via `PsrHttpFactory::createRequest()`, which JSON-decodes the body whenever `getContentTypeFormat() === 'json'`. Symfony maps `application/x-json` and any `application/*+json` to `json`.
- **Attack:** `POST /api/oauth/token` with `Content-Type: application/x-json` and body `{"grant_type":"password","client_id":"administration","username":"admin","password":"…","scopes":"write"}`. The guard sees `grant_type = null` and lets it through. League issues a token, so password login works although `disable_non_oidc_admin_login` is on. The same request also sidesteps core's per-username OAuth rate limiter, which reads `request->getString('username')` and gets `''`, so password brute force is unthrottled too.
- **Fix:**
  - Enforce at the grant, not by re-parsing the HTTP request: decorate core's password grant, or wrap `UserRepository::getUserEntityByUserCredentials()`.
  - If it has to stay a listener, parse exactly as League does (`getContentTypeFormat() === 'json'` then `json_decode(getContent())`), and **reject** the request when `grant_type` can't be determined.
  - Add an integration test for `application/json`, `application/x-json`, `application/vnd.api+json` and form bodies.

### N-H2. **NEW** — Anyone can stop JWKS key-rotation recovery with one bad token a minute
- **Where:** `Service/Jwt/JwtVerifier.php:127-139` (`verifySignedPayload`) and `:220-237` (`getJwks`, forced refresh), reachable anonymously via `Controller/Oidc/BackChannelLogoutController.php`
- **What's wrong:**
  - Any token whose signature fails against the cached JWKS triggers one forced refetch.
  - That sets `sw6oidc_jwks_refreshed_<sha256(endpoint)>` for 60 s **before** fetching.
  - The flag is per JWKS endpoint and is shared by id_token verification (logins) and logout-token verification.
  - Key selection by `kid` is still missing (M11), so there is no way to tell "unknown key" from "forged signature".
- **Attack:**
  1. An anonymous attacker POSTs a logout token with a bad signature to `/sw6oidc/backchannel-logout` once a minute. It only needs the right `iss` and `aud`, and the `client_id` is public in every authorize redirect.
  2. That is far below the 10-failures-per-minute rate limit, and it keeps the cooldown flag set permanently.
  3. When the IdP rotates its signing key, the refetch that would pick up the new key has already been used. **Every SSO login fails** until `jwks_cache_ttl` expires (default 86 400 s).
- **Fix:** select the verification key by `kid` (and `alg`, `use=sig`). Force a refetch **only** when the token's `kid` is not in the cached set, rate-limited per `kid`. Never let an unauthenticated endpoint spend the rotation refetch, or give back-channel verification its own cooldown key.

### N-H3. **NEW** — Back-channel and front-channel logout silently miss live sessions
- **Where:**
  - `Service/Session/Sw6OidcSessionRegistry.php:24` (`DEFAULT_TTL_SECONDS = 86400`)
  - `Service/Session/Sw6OidcSessionRegistry.php:28, 203` (50-entry index cap)
  - `Resources/config/services.xml` (registry wired to `cache.app`)
- **What's wrong:** the registry is **security state**, but it lives in a cache that is wiped routinely and expires sooner than the sessions it tracks:
  - Entries live a fixed 24 h and are never extended. Admin refresh tokens from `AdminOidcGrant` last `P1M` (`AdminOidcGrant.php:48`, H10). Customer contexts have a sliding `P1D` lifetime.
  - `cache.app` is filesystem-backed and namespaced by `%kernel.cache.hash%` by default. `cache:clear`, the Administration's "clear cache", every plugin install/update and every deploy empty it.
  - Past 50 logins per subject, sid or user, the oldest ids fall out of the index while their session entries stay alive.
- **Attack / failure:** the IdP disables an employee and sends a valid logout token. If the login is older than 24 h, or any deploy or cache clear happened since, `resolveBySid()` finds nothing. The endpoint answers **200** and logs `sessionsEnded: 0` at info level, so nothing looks wrong. The admin keeps working for up to a month. The feature fails silently in exactly the situation it exists for.
- **Fix:** move the registry to a DB table (`sw6oidc_session`), indexed by `(provider_id, sid)`, `(provider_id, sub)` and `(user_type, user_id)`, and prune it by the real session lifetime. The session activity table already holds almost all of this data. At minimum: a dedicated non-clearable pool, TTL ≥ refresh-token TTL, and evicting the session entry itself when it falls out of an index.

### H1. Deactivated or deleted admins still get valid tokens — **OPEN (unchanged)**
- **Where:**
  - `Service/AdminAuth/AdminOidcGrant.php:91-100` (`validateUser`)
  - `Controller/Api/OidcAdminAuthController.php:199` (`findOrCreateAdmin` returns inactive users)
  - `Controller/Api/PasskeyAdminController.php:165-191`
- **What's wrong:** `validateUser()` still wraps any non-empty string in `new ShopwareOAuthUser($userId)`. The OIDC, passkey and verify-session paths never check that the user exists or that `active = 1`. The storefront does check (`OidcCustomerLoginRoute.php:43`), but the admin side doesn't. Core's own password grant refuses inactive users (`UserRepository.php:65`). Passkeys still survive user deletion (M15).
- **Fix:** in `validateUser()`, run `SELECT active FROM user WHERE id = UNHEX(:id)` and throw `OAuthServerException::invalidGrant()` if the user is missing or inactive. Doing it in the grant covers every caller.

### H2. `verify-session` turns any bearer token into a `user-verified` token — **OPEN (unchanged)**
- **Where:** `Controller/Api/OidcAdminAuthController.php:369-411` (`isSsoProvisioned` at `:551-558`)
- **What's wrong:** any valid access token for a user with a binding **or any passkey** gets a fresh `user-verified` access token plus a refresh token. There is no step-up. A stolen 10-minute token becomes a re-authenticated session. A password admin who registered a passkey once can skip password reconfirmation forever. The refresh token doesn't carry `user-verified` itself (core `ScopeRepository` strips it for non-password grants), but it still extends the session.
- **Related (NEW):** both login endpoints let the client request `user-verified` directly. See N-M17.
- **Fix:** require fresh proof right before minting: an OIDC round trip with `prompt=login&max_age=0` and an `auth_time` check, or a passkey assertion with `userVerification: required`. Mint only an access token, with a short TTL.

### H3. "Role sync" only ever adds ACL roles, so revoking a role at the IdP does nothing — **OPEN (unchanged)**
- **Where:** `Service/Provisioning/AdminProvisioningService.php:173-194`
- **What's wrong:** `update(['aclRoles' => [['id' => $aclRoleId]]])` is still a many-to-many upsert, so demoted admins keep their old roles. Superadmin is still grant-only (`:175-181`). CLAUDE.md still claims the sync "overwrites" the roles.
- **Fix:** delete the user's `acl_user_role` rows, then insert the resolved role, in one transaction. Offer an opt-in to revoke superadmin that refuses to remove the **last** superadmin.

### H4. Open redirect after login through `redirectTo` — **OPEN (one new bypass)**
- **Where:**
  - `Storefront/Controller/OidcCallbackController.php:163-180` (`resolveSafeRelayState`)
  - `Storefront/Controller/SendAuthorizationRequestController.php:39` (no validation when the request is built)
- **What's wrong:** the sanitizer is unchanged. These values pass through and send the browser off-site:
  - `/\evil.tld`
  - `\\evil.tld`
  - `http:evil.tld`
  - `%09//evil.tld`
  - `/%09/evil.tld`
  - ` //evil.tld` (leading space)
- **NEW bypass in the "absolute URL" branch (`:171-176`):** `https://x//evil.tld/p` parses to path `//evil.tld/p`, and that is returned unchecked. The result is a protocol-relative redirect, which slips past even the one `//` guard the function has.
- **Fix:** accept only `^/(?![/\\])[^\x00-\x20\\]*$`, and apply the same check to the output of the absolute branch. Better, drop that branch and redirect only to known route names (F-H2). Validate in `SendAuthorizationRequestController` too.

### H6. Passkey logins don't require user verification, and the enable toggles are not enforced — **OPEN (unchanged)**
- **Where:**
  - `Service/Passkey/PasskeyAuthenticationService.php:133-141` (`buildOptions`, no `userVerification`)
  - `Service/Passkey/WebauthnCeremonyFactory.php:55-61` (only `residentKey` is set)
  - `Controller/Api/PasskeyAdminController.php:61, 78, 150, 165`
  - `Storefront/Controller/PasskeyController.php:44-107`
- **What's wrong:** `userVerification` is still left at `preferred`, so a stolen security key without a PIN is enough for a passwordless **admin** login. `isEnabledForAdmin()` is only read to set a UI flag (`OidcAdminAuthController.php:131`), and `isEnabledForCustomer()` only in Twig and `AccountPasskeyController`. None of the six ceremony endpoints checks either toggle.
- **Fix:** set `userVerification: required` in both the request options and `AuthenticatorSelectionCriteria`. Return 404 at the top of all six actions when the relevant toggle is off.

### H7. Registering a passkey needs no re-authentication, so a hijacked session can plant a permanent backdoor — **OPEN (plus a new variant)**
- **Where:** `Controller/Api/PasskeyAdminController.php:61-95`, `Storefront/Controller/PasskeyController.php:44-86`
- **What's wrong:** unchanged. A bearer token or customer session is enough to register a new discoverable credential. There is no notification, and no info-level audit entry.
- **Related (NEW):** registration-verify is not bound to the caller at all. See N-M2.
- **Fix:** require `user-verified`, fresh re-authentication, or an existing passkey assertion before `registration-options`. Email the account owner and write an audit log entry.

### H8. The "Disable non-OIDC login" toggles — **PARTIAL**
- **Where:**
  - `Service/Security/PasswordLoginPolicy.php:17-27`
  - `Subscriber/AdminPasswordLoginGuardSubscriber.php:34-63`
  - `Storefront/Service/PasswordLoginGuardLoginRoute.php:25-33`
- **Fixed:** the toggles are now enforced. The `password` grant on `api.oauth.token` gets a 403. The core `LoginRoute` (Storefront and `/store-api/account/login`) is blocked. A lockout guard and a break-glass `SW6OIDC_ALLOW_PASSWORD_LOGIN` exist.
- **Still open:**
  1. The admin guard can be bypassed with a different `Content-Type`. That is **N-H1**, rated High.
  2. Registration still creates logged-in password accounts. That is **N-M9**.
  3. `refresh_token` grants are untouched, so sessions from an earlier password login keep renewing when the flag is switched on. Revoke all refresh tokens of affected users when the flag flips.
  4. `client_credentials` with `user_access_key` is still a non-OIDC admin login. Document it or gate it.
  5. The block is shop-wide ("any active provider has the flag"), but the lockout guard only requires one binding **for this provider**. Every unbound local admin is locked out.
  6. The lockout guard only runs when the flag is switched on. Deleting or deactivating the last bound user later still locks everyone out. Break-glass mitigates this.

### H9. Admin login nonce and OIDC `state`/`nonce` are logged at the default level — **OPEN (unchanged)**
- **Where:**
  - `Resources/config/services.xml:8` (`default_log_level = debug`)
  - `Controller/Api/OidcAdminAuthController.php:221-224` (`redirectUrl` with `sw6oidc_nonce`)
  - `Service/Oidc/AuthorizationRequestBuilder.php:54-59`
  - `Service/Oidc/OidcCallbackProcessor.php:125-130` (email, groups)
  - `Service/Http/OidcHttpClient.php:90, 100` (full URLs)
- **What's wrong:** unchanged. `SensitiveDataProcessor` still masks only by key name, never inside URL values. The admin hand-off nonce, a 120-second bearer credential, is written to `var/log/sw6oidc-prod.log`.
- **New leak:** the webhook URL (with its token) also ends up in the log via transport exception messages. See N-L8.
- **Fix:** default to `info` or `warning`. Never log URLs that carry credentials. Strip `state`, `nonce`, `sw6oidc_nonce`, `code` and `id_token_hint` inside string values. Treat email and groups as personal data.

### H10. Token lifetimes are hard-coded and ignore the shop's own configuration — **OPEN (unchanged)**
- **Where:** `Service/AdminAuth/AdminOidcGrant.php:48` (`P1M`), `Service/AdminAuth/AdminAuthorizationServerFactory.php:23` (`PT10M`)
- **What's wrong:** `shopware.api.access_token_ttl` and `refresh_token_ttl` are still ignored, so SSO sessions last 4× longer than password sessions (the core default is `P1W`). Back-channel logout now exists, but N-H3 means it can't be relied on to end these sessions.
- **Fix:** inject `%shopware.api.access_token_ttl%` and `%shopware.api.refresh_token_ttl%`.

### F-H2. `redirectTo` is read the wrong way, so checkout redirects are lost or 404 — **OPEN**
- **Where:** `views/storefront/component/account/login.html.twig:55`
- **What's wrong:** still `app.request.get('redirectTo')`. It is still the way into H4.
- **Fix:** pass the Twig `redirectTo` and `redirectParameters` variables, and resolve the route name on the server.

### F-H3. Hard-coded storefront fetch URLs break sales channels with a path prefix — **OPEN**
- **Where:** `app/storefront/src/passkey/passkey-login.plugin.js:27, 37`, `passkey-registration.plugin.js:30, 48`
- **Fix:** render `{{ path(...) }}` into the plugin options.

### F-H4. Admin code uses raw `fetch('/api/...')` instead of Shopware's HTTP client — **OPEN (worse)**
- **Where:** every location from revision 1, plus these new ones:
  - `extension/sw-admin-menu/index.js:26, 53`
  - `module/sw6oidc-sessions/page/sw6oidc-sessions-list/index.js:157`
  - `extension/sw-login/index.js:345` (login-error)
  - `module/sw6oidc-provider/page/sw6oidc-provider-detail/index.js:555` (diagnostics)
  - `window.location.href = '/api/...'` in `sw-inactivity-login/index.js:94` and `sw-login/index.js:153`
- **What's wrong:** this breaks sub-folder installs and skips core's token refresh. It now also breaks IdP logout (F-N2).
- **Fix:** add one `Sw6OidcApiService extends ApiService` and use `this.httpClient`. Use `Shopware.Context.api.apiPath` for full-page redirects.

### F-H5. No ACL privilege mapping, so the modules can't be granted to non-admin roles — **OPEN (worse)**
- **Where:**
  - `module/sw6oidc-provider/index.js:5-10` (still has the wrong comment)
  - `module/sw6oidc-passkey/index.js`
  - **new:** `module/sw6oidc-sessions/index.js:5-8`
- **What's wrong:** `grep addPrivilegeMappingEntry` finds nothing, and there is no `acl/` directory. Declaring `entity` does not register privileges. None of the three modules can be granted to a normal role, including force logout.
- **Fix:** add `acl/index.js` with `addPrivilegeMappingEntry` for `sw6oidc_provider`, `sw6oidc_passkey_credential` and `sw6oidc_session_activity`. Include the dependent `user.viewer` and `customer.viewer` roles, which are needed to resolve owner names.

### F-H6. The inactivity re-login accepts **any** admin's passkey — **OPEN**
- **Where:** `extension/sw-inactivity-login/index.js:110`
- **What's wrong:** it still sends `email: this.lastKnownUser`, but core stores the **username** there (`login.service.ts:706`). The backend finds nothing and falls back to discoverable credentials. Nothing checks that the resolved user is `lastKnownUser`. The new SSO re-login path has the same flaw (F-N1).
- **Fix:** send the username, and have the backend reject an assertion whose user doesn't match the expected one.

---

## Medium

### New backend findings

#### N-M1. **NEW** — Passkey origin check accepts any subdomain of the RP ID
- **Where:** `Service/Passkey/WebauthnCeremonyFactory.php:106-113` (`ceremonyStepManagerFactory`)
- **What's wrong:** `setAllowedOrigins()` is never called. webauthn-lib 5.3.9 therefore uses the default `CheckOrigin` step, which accepts any `clientData.origin` whose host ends in `.<rpId>` and ignores the `$host` argument once `rpId` is set. With rpId `shop.tld`, an assertion made on `https://anything.shop.tld` is accepted. A subdomain takeover, or any host serving user content (CDN, staging, blog, a helpdesk tool), can relay a live ceremony and obtain an admin token. This is worse together with M17, because the admin RP ID comes from the `Host` header.
- **Fix:** `$factory->setAllowedOrigins([<exact admin origin>, <sales-channel origins>], allowSubdomains: false)`, with origins built from `APP_URL` and `sales_channel_domain`.

#### N-M2. **NEW** — Registration-verify is not bound to the authenticated caller
- **Where:**
  - `Service/Passkey/PasskeyRegistrationService.php:78-83`
  - `Controller/Api/PasskeyAdminController.php:78-95`
  - `Storefront/Controller/PasskeyController.php:64-86`
- **What's wrong:** `verifyAndPersist()` saves the credential for the `userId` stored with the ceremony and never compares it with the admin or customer making the verify call. Whoever gets hold of a victim's registration `sessionId` (from XSS, a leaked log or a shared machine) can finish the ceremony with their own authenticator and plant a passkey on the victim's account.
- **Fix:** pass the current `userType` and `userId` into `verifyAndPersist()`, and reject the request unless both match the stored values.

#### N-M3. **NEW** — The callback rate limiter can be used to block all SSO logins
- **Where:**
  - `Service/Security/Sw6OidcRateLimiter.php:49-54`
  - `Storefront/Controller/OidcCallbackController.php:60, 145`
  - `Controller/Api/OidcAdminAuthController.php:174, 250`
- **What's wrong:** the storefront and admin callbacks share one scope, keyed only by `getClientIp()`. Ten anonymous `GET /sw6oidc/callback?state=x` requests a minute block **both** callbacks for that address. Real users who have just finished the IdP login are then rejected too.
  - Behind a CDN or load balancer without `trusted_proxies`, every client has the proxy's IP, so one attacker blocks all SSO logins, admin included.
  - Behind one office NAT, ten expired-state errors lock everybody out.
  - IPv6 attackers escape the limit by rotating through their /64.
- **Fix:**
  - Use separate scopes for admin and storefront.
  - Answer requests with an unknown or garbage `state` with a plain 400 and don't count them. Count only failures after a state was successfully redeemed.
  - Key IPv6 by /64, and document that `trusted_proxies` is required.

#### N-M4. **NEW** — Admin logout ends the wrong device's session after the first token refresh
- **Where:**
  - `Controller/Api/OidcAdminAuthController.php:431-442, 513-535`
  - `Service/Session/Sw6OidcSessionActivityRecorder.php` (`fallbackToNewest`)
- **What's wrong:** the registry's `sessionKey` is the jti of the **first** access token. The SPA refreshes every 10 minutes, and each refresh has a new jti, so the exact match stops working after the first refresh. The code then removes the admin's **newest** registry entry and closes the newest activity row.
- **Scenario:** the admin is logged in on laptop A (older) and phone B, and logs out on A.
  - B's registry entry is removed, so B can no longer be ended by back-channel logout.
  - B's activity row is marked "logout", while A's stays open.
  - B's id_token is sent as `id_token_hint`, so the IdP may end B's session instead of A's.
- **Fix:** key the session by something that survives a refresh, such as the refresh-token family, or a login-session id minted at nonce exchange and sent back by the SPA. If there is no exact match, remove and close nothing, and fall back to `LogoutContextStore`.

#### N-M5. **NEW** — Read permission on session activity becomes the power to log anyone out
- **Where:**
  - `Core/Content/SessionActivity/Sw6OidcSessionActivityDefinition.php:63-64` (`sub`, `sid` are `ApiAware`)
  - `Controller/Oidc/FrontChannelLogoutController.php`
- **What's wrong:** any role or integration with `sw6oidc_session_activity:read` can list every live session's `sid`, including superadmins'. Front-channel logout accepts `iss` + `sid` with no authentication and no binding to the calling browser. For an admin, it ends **all** of that user's sessions: it bumps `last_updated_password_at` and revokes every refresh token, password sessions included.
- **Attack:** a read-only admin reads the superadmin's sid and embeds `<img src="https://shop/sw6oidc/frontchannel-logout?iss=…&sid=…">` anywhere, or loops over it, and keeps the superadmin logged out. The `sid` also leaks through `id_token_hint` in logout URLs and through the id_tokens copied into cache (N-L10).
- **Fix:** remove `ApiAware` from `sid` and `sub`, or store only hashes. Do not let front-channel logout trigger all-sessions destruction for admins, or make that opt-in per provider. Document that `sid` is a bearer capability.

#### N-M6. **NEW** — Front-channel logout counts normal logouts as failures and blocks the IP
- **Where:** `Controller/Oidc/FrontChannelLogoutController.php:66, 79`
- **What's wrong:** a request that ends 0 sessions is counted as a failure (`recordFailure`). That is the **normal** case when:
  - RP-initiated logout already removed the entry and the IdP's logout page then loads the front-channel iframes;
  - back-channel logout already handled it;
  - the session simply expired (N-H3).

  An office NAT with about 10 SSO logouts a minute gets blocked. After that, front-channel logouts are silently dropped (still a 200 GIF) and sessions stay alive.
- **Fix:** count only malformed requests. Rely on sid entropy for unknown sids, or use a much higher limit keyed by `hash(sid)` plus IP.

#### N-M7. **NEW** — Invalid tokens can get the IdP's real back-channel logout tokens refused
- **Where:** `Controller/Oidc/BackChannelLogoutController.php:57, 73`
- **What's wrong:** for back-channel logout, the rate-limit key is the **IdP's** egress IP. SaaS IdPs (Okta, Auth0, Entra and others) share those IPs across tenants. An attacker registers their own tenant with the shop's back-channel URL and sends more than 10 foreign-signed logout tokens a minute. The shop then answers 429 to the real IdP's valid logout tokens, and the spec does not require the OP to retry.
- **Fix:** check the block only after the cheap `iss`/`aud` checks, key failures by `(provider, IP)`, and never block a request whose signature verifies.

#### N-M8. **NEW** — The session audit log can be rewritten through the generic Admin API
- **Where:** `Core/Content/SessionActivity/Sw6OidcSessionActivityDefinition.php:58-78`
- **What's wrong:** no field is `WriteProtected`, so `/api/sw6oidc-session-activity` accepts create, update and delete requests. Anyone with `:update`, the privilege the force-logout endpoint needs, can rewrite `loggedOutAt`, `ipAddress` or `logoutReason`, or delete rows. An audit log that the audited people can edit isn't an audit log.
- **Fix:** add `WriteProtected(Context::SYSTEM_SCOPE)` to every field and write only through the recorder in system scope. Gate force logout with a dedicated privilege.

#### N-M9. **NEW** — Customer SSO-only mode can be bypassed through registration
- **Where:** `Storefront/Service/PasswordLoginGuardLoginRoute.php:25-33`
- **What's wrong:** only `LoginRoute` is guarded. Core's `RegisterRoute` (`:226-231`) and `RegisterConfirmRoute` (`:90-95`) create a password account and put `customerId` into the context token without calling `LoginRoute`. With `disable_non_oidc_customer_login` on, anyone can still get a logged-in password account through `/store-api/account/register` or the storefront register form.
- **Fix:** decorate `AbstractRegisterRoute` and `AbstractRegisterConfirmRoute` so they refuse registration, or don't issue a session, when the policy is on. Hide the registration form, and decide explicitly how guest checkout is treated.

#### N-M10. **NEW** — Access-control `neq` fails open on lists and missing claims
- **Where:** `Service/Security/Sw6OidcAccessControlEvaluator.php:85-86, 96-105`
- **What's wrong:** `equalsClaim()` returns false when the claim has more than one member (`$actual` becomes null), and also when the claim is missing. `neq` negates that, so it returns **true** in both cases. The rule `groups neq "blocked"` therefore lets `groups: ["blocked","staff"]` through, and also every user with no `groups` claim. `eq` on a multi-value list always fails, which is closed but surprising.
- **Fix:** `neq` = "claim present and no member equals the value". Make `eq` mean "any member" for lists, or reject `eq`/`neq` on list claims. Negative operators must **deny** when the claim is missing.

#### N-M11. **NEW** — `not_contains` fails open on omitted claims, and `contains` does substring matching on scalars
- **Where:** `Service/Security/Sw6OidcAccessControlEvaluator.php:28-29, 87-88, 110-126`
- **What's wrong:**
  - **Omitted claims:** `not_contains` returns true when the claim is absent. Deny-list rules are then skipped. Claims go missing in ordinary cases:
    - Entra ID's group overage (`_claim_names` instead of `groups`);
    - a scope that wasn't granted;
    - `ClaimsNormalizer::MAX_RECURSION_DEPTH = 5` silently dropping deeper claims.
  - **Substring matching:** the docblock's own example, `email contains "@example.com"`, also matches `eve@example.company` and `x@example.com.attacker.io`. That is a textbook domain-allow-list bypass.
- **Fix:** negative operators deny when the claim is missing. Add `ends_with` / `email_domain` operators. Remove the substring example from the docblock and the admin help text.

#### N-M12. **NEW** — The "write-only" client secret can be exfiltrated by changing an endpoint URL
- **Where:** `Subscriber/Sw6OidcProviderWriteGuardSubscriber.php:57-61` (URL map), `Core/Content/Provider/Sw6OidcProviderDefinition.php:57-59`
- **What's wrong:** anyone with `sw6oidc_provider:update` can point `accessTokenEndpoint` (or `revocationEndpoint`, or the discovery URL) at their own public HTTPS host. That passes the SSRF check. On the next login, `TokenExchangeService` sends `Authorization: Basic base64(client_id:secret)` to that host. Hiding the secret from API reads (H5) doesn't help when the plugin delivers it anyway.
- **Fix:** in the write guard, reject any update that changes a URL that receives credentials unless `client_secret` is in the same payload, the same re-entry rule browsers use for saved passwords. Log the change at warning level with the admin user id.

#### N-M13. **NEW** — On Redis older than 6.2, every OIDC and passkey login fails
- **Where:** `Service/Cache/RedisAtomicCache.php:76-82`
- **What's wrong:** `method_exists($redis, 'getdel')` tests the phpredis **client** version (≥5.3), not the Redis **server**. On a Redis 5.x or 6.0 server, GETDEL gets an error reply. phpredis returns `false` instead of throwing, so the code treats it as a miss and falls back to the cache pool, which returns null. The Lua fallback never runs. Every state and nonce lookup fails, so every login fails, and the keys are never deleted.
- **Fix:** always use the Lua `GET`+`DEL` script, which is atomic on every version. Otherwise detect the server version once (`INFO server`) and check `getLastError()` after the call.

#### N-M14. **NEW** — `/sw6oidc/health` can stay 503 forever
- **Where:** `Controller/HealthCheckController.php:23-24, 76`, `Service/Health/ProviderHealthMonitor.php:20`
- **What's wrong:** the endpoint counts `health_alert_last_status = fail` for **every** active provider, but the monitor only updates providers that have a threshold above 0 **and** a webhook. If an admin turns alerting off while the status is `fail`, the state freezes, and it can't be reset through the API (`WriteProtected`). The endpoint then returns 503 permanently, so any uptime monitor or load balancer using it reports the shop as down.
- **Fix:** ignore alert state for unmonitored providers, or reset it when monitoring is turned off. Treat a stale `last_checked_at` as "unknown". Return `200 {status: degraded}` and reserve 503 for "SSO completely unusable".

#### N-M15. **NEW** — The rate limiter doesn't cover most anonymous endpoints (M5 follow-up)
- **Where:** `SendAuthorizationRequestController.php:30`, `OidcAdminAuthController.php:93, 142, 277`, `PasskeyController.php:103` plus login-verify, `PasskeyAdminController.php:151` plus login-verify
- **What's wrong:** `Sw6OidcRateLimiter` is only used by the two callbacks and the two channel-logout endpoints. None of these anonymous endpoints is limited:
  - flow start (`/sw6oidc/login`, `/api/sw6oidc/admin/login`), which writes a cache entry per request;
  - both passkey `login-options` endpoints (cache entry per request) and both `login-verify` endpoints;
  - the admin nonce exchange (`POST /api/sw6oidc/admin/token`);
  - `login-error/{ticket}`.

  The penalty model (peek, then only count failures) **can't** stop the cache from being filled: the requests that fill it always succeed, so they never count.
- **Fix:** a consuming limit (for example 30 per minute per IP) on flow start and options, and failure counting on token, verify and ticket redemption.

#### N-M16. **NEW** — Passkey login re-resolves the customer by email (M8 applies to passkeys too)
- **Where:** `Storefront/Controller/PasskeyController.php:120-140`, `Storefront/Service/OidcCustomerLoginRoute.php:60-86`
- **What's wrong:** the passkey resolves customer A **by id**, then logs in with `['email' => A.email]`. That can log in a different customer who has the same email (a sales-channel-bound duplicate). The OIDC callback does the same (`OidcCallbackController.php:90`).
- **Fix:** add `OidcCustomerLoginRoute::loginByCustomerId()`, check `boundSalesChannelId` on that entity, and use it on both paths.

#### N-M17. **NEW** — Clients can request the `user-verified` scope at login
- **Where:** `Service/AdminAuth/AdminOidcGrant.php:60-70`, `Controller/Api/OidcAdminAuthController.php:277-308`, `Controller/Api/PasskeyAdminController.php:165-191`
- **What's wrong:** scopes are finalized as `PASSWORD_GRANT`, so core's `ScopeRepository` keeps `user-verified` whenever it is requested, and both endpoints forward the client's `scope` parameter unchanged. A passkey login-verify with `scope=write user-verified` mints a re-auth-grade token straight away. Because passkeys don't require user verification (H6), a stolen security key without a PIN also gets past core's password re-confirmation gate.
- **Fix:** remove `user-verified` from the requested scopes in both endpoints. Add it only in `verifySession()`, after a fresh, user-verified proof (H2 and H6).

### Revision 1 backend findings

#### M1. Login CSRF and session fixation: `state` and the admin nonce aren't bound to the browser — **OPEN**
- **Where:** `Service/Security/OidcSecurityHelper.php:29-52, 60-79`, `Service/AdminAuth/AdminLoginNonceService.php:26-74`, `Controller/Api/OidcAdminAuthController.php:277-293`
- **What's wrong:** unchanged. No cookie is set or checked anywhere in `src/`.
- **Fix:** set an `HttpOnly; Secure; SameSite=Lax` cookie holding `hash(state)` and `hash(nonce)` when the flow starts, and compare it in the callback and in `exchangeNonce`.

#### M2. Userinfo claims override ID token claims without a `sub` check, and `id_token` is optional — **OPEN**
- **Where:** `Service/Oidc/OidcCallbackProcessor.php:83-105`
- **What's wrong:** a missing `id_token` still only produces a warning (`:94`). `array_merge($idTokenClaims, $userInfoClaims)` (`:105`) still lets userinfo overwrite `email` and `sub` without comparing `sub`.
- **Fix:** require `id_token` whenever `openid` is in scope. Assert `userinfo.sub === idToken.sub`, and let the ID token win for `sub`, `email` and `email_verified`.

#### M3. A retried POST replays the single-use authorization code — **OPEN**
- **Where:** `Service/Http/OidcHttpClient.php:94-105, 132-143`
- **What's wrong:** `retryOnce()` still resends every method, including the token POST, after `usleep(500_000)`. **New:** when `NoPrivateNetworkHttpClient` blocks an address it throws a `TransportException`, so a blocked SSRF attempt is retried once as well.
- **Fix:** retry only idempotent GETs, and never retry a blocked-address exception.

#### M4. The default "atomic" cache isn't atomic, and flows disappear on `cache:clear` — **PARTIAL**
- **Where:** `Resources/config/services.xml:46-66`, `Service/Cache/RedisAtomicCache.php:44-83`, `Service/Cache/CachePoolAtomicCache.php:28-40`, `Service/Cache/RedisConnectionFactory.php:29-53`
- **Fixed:** `RedisAtomicCache` is always wired and picks its backend at runtime, and the fallback now reads the store it writes. With Redis available, `GETDEL` is atomic.
- **Still open:**
  - Without `SW6OIDC_REDIS_DSN`, which is the default, it is still a racy get-then-delete on `cache.app`. `cache:clear` still wipes in-flight flows and the 24 h `LogoutContext`s.
  - With Redis, see N-M13 (server-version bug) and N-L14 (silent degradation, 1.5 s connect stall per request while Redis is down).
- **Fix:** use a dedicated pool, or a DB table with `DELETE … WHERE key = ? AND expires > NOW()` that checks for exactly one affected row.

#### M5. Unauthenticated endpoints write cache entries with no rate limiting — **PARTIAL**
- **What changed:** a limiter exists now, but it covers only the callbacks and channel logout, and its penalty model can't stop cache filling. See N-M15 for what's missing and N-M3, N-M6 and N-M7 for how the limiter itself can be abused.

#### M6. Account and feature enumeration on the anonymous admin endpoints — **OPEN**
- **Where:** `Controller/Api/PasskeyAdminController.php:150-163, 266-288`, `Controller/Api/OidcAdminAuthController.php:110-135`
- **Fix:** always use `allowCredentials = []` on the admin side too. Registration already enforces `residentKey=required`.

#### M7. Raw exception messages go back to anonymous clients — **OPEN (more places)**
- **Where:**
  - `Storefront/Controller/PasskeyController.php:93, 172`
  - `Controller/Api/PasskeyAdminController.php:93, 199, 203`
  - `Controller/Api/OidcAdminAuthController.php:317, 405`
  - `Controller/Api/OidcProviderAdminController.php:87, 193`
  - **new:** `Controller/Oidc/BackChannelLogoutController.php:76` (see N-L1)
- **Fix:** return a fixed error code, and log the detail server-side with a correlation id.

#### M8. Customer login looks up by email and may log in a different customer — **OPEN**
- **Where:** `Storefront/Controller/OidcCallbackController.php:90`, `Storefront/Controller/PasskeyController.php:140`, `Storefront/Service/OidcCustomerLoginRoute.php:44-86`
- **Fix:** see N-M16.

#### M9. The birthday claim can break login or be accepted as nonsense — **OPEN**
- **Where:** `Service/Provisioning/CustomerProvisioningService.php:168, 248`
- **Fix:** `DateTimeImmutable::createFromFormat('!Y-m-d', $v)`. Reject years before 1900 and dates in the future. On failure, log and skip the field.

#### M10. `claim_encoding = base64` corrupts plain claims — **OPEN (worse)**
- **Where:** `Service/Oidc/ClaimsNormalizer.php:60-73`
- **What's wrong:** it still decodes every string claim. **New:** groups are taken from the raw, undecoded claim (`OidcCallbackProcessor.php:113-114`), so with base64 enabled, groups and the other claims disagree. Access-control rules then compare against decoded values.
- **Fix:** decode only an explicit per-provider list of claims.

#### M11. JWKS handling can cause outages and ignores `kid` — **PARTIAL**
- **Where:** `Service/Jwt/JwtVerifier.php:120-148, 189-211, 220-279`
- **Fixed:** a signature failure triggers one forced refetch (with a 60 s cooldown and a circuit breaker). The fetch uses the SSRF-guarded client, and fetch errors are handled.
- **Still open:**
  - The key is not selected by `kid`, `alg` or `use`.
  - There is no leeway on `exp` or `nbf` (`:193-199`).
  - There is no `iat` check on id_tokens.
  - `azp` is not checked when `aud` has several values (`:205-206`).
  - The new cooldown can be abused. That is **N-H2**.
- **Fix:** select the key by `kid` and refetch only when the `kid` is unknown. Allow 60 s leeway, and check `azp` and `iat`.

#### M13. Live-test claims (personal data) are stored and shown to viewers — **OPEN**
- **Where:** `Controller/Api/OidcProviderAdminController.php:129-133` (`startLiveTest` only needs `viewer`), `:262-287` (stores the full claims), `Core/Content/Provider/Sw6OidcProviderDefinition.php:95` (`ApiAware`)
- **Fix:** store claim keys only, and require `editor` to start a test.

#### M14. RP-initiated logout is half-implemented — **PARTIAL**
- **Fixed:**
  - The redirect is built with `router->generate(…, ABSOLUTE_URL)`.
  - `state` is now an HMAC `PostLogoutState` that `PostLogoutController` checks.
  - A shared post-logout landing page exists.
- **Still open:**
  - `revokeToken($provider, null)` is still called with `null` (`Storefront/Service/OidcLogoutRoute.php:105`, `OidcAdminAuthController.php:464`), so RFC 7009 revocation never runs.
  - The id_token is still cached in plaintext for 24 h, and is now also copied into the session registry (N-L10).
- **Fix:** store the access and refresh tokens (encrypted) in the logout context, or remove the revocation feature.

#### M15. Passkeys and caches are not cleaned up when a user or customer is deleted — **OPEN**
- **Where:** `Subscriber/UserProviderCleanupSubscriber.php:25-51`
- **What's wrong:** it still removes only the binding. Passkey credentials and session-activity rows stay behind (see N-L12), and nothing revokes tokens when a user is deactivated.
- **Fix:** delete passkeys and activity rows in the same subscriber. On `user.written` with `active = false`, call `Sw6OidcSessionDestructionService::destroyAllForUser()`, which now exists.

#### M16. JIT customer creation bypasses Shopware's registration pipeline — **OPEN**
- **Where:** `Service/Provisioning/CustomerProvisioningService.php:114-208`
- **What's wrong:**
  - A raw `create()` still skips `CustomerRegisterEvent` and the Flow Builder flows that hang off it.
  - `'defaultPaymentMethodId'` (`:159`) still doesn't exist in 6.7.
  - The `'-'` address placeholders are still written (`:146-148, 186-188`).
  - The plugin's own `CustomerAfterCreateEvent` doesn't replace core's event: Flow Builder can't use it.
- **Fix:** dispatch `CustomerRegisterEvent`, remove `defaultPaymentMethodId`, and ask for the address instead of writing `-`.

#### M17. Passkey RP ID is wrong on multi-domain shops and trusts the Host header in admin — **OPEN**
- **Where:** `Storefront/Controller/PasskeyController.php:85, 124, 178`, `Controller/Api/PasskeyAdminController.php:71, 85, 160, 172`, `Service/Passkey/PasskeyConfig.php:37-42`
- **Fix:** use the current domain id and allow a configured list of related origins. In the admin, derive the RP ID from `APP_URL`. This combines with N-M1.

#### M18. `league/oauth2-server: "*"` is unconstrained — **OPEN**
- **Where:** `composer.json`
- **Fix:** remove the requirement and rely on `shopware/core`'s constraint, or pin it to the range core ships.

#### M19. The avatar is re-downloaded and re-imported on every login — **OPEN**
- **Where:** `Service/Provisioning/AdminProvisioningService.php:225-234, 263-310`
- **What's wrong:** unchanged. It is now also the only outbound fetch that does **not** go through the SSRF-guarded client: it uses core's `FileFetcher`, which depends on `core.media.enableUrlValidation`.
- **Fix:** skip the import when `sha256(url)` or the ETag is unchanged, and fetch through `sw6oidc.http_client`.

#### M20. Shared services hold mutable per-request state — **PARTIAL**
- **Fixed:** `PendingLogoutRedirect` implements `ResetInterface`, `CustomerLogoutSubscriber` is stateless, and `setContext()` is gone.
- **Still open:**
  - `PasskeyCredentialRepository.php:31-39` still calls `Context::createDefaultContext()` in its constructor.
  - `CountryResolver` has memo arrays but no `ResetInterface`.
  - **New:** `OidcAdminAuthController::$endedRegistrySessionId` (`:58, 440, 533`) is set per request, never reset, and in long-running workers is passed stale to `recordLogout()`.
- **Fix:** pass `Context` as a method argument, add `ResetInterface`, and return the registry id from `consumeAdminLogoutContext()` instead of storing it on the controller.

#### M21. Races on first login — **OPEN**
- **Where:** `Service/Provisioning/UserProviderBindingService.php:56-68`, `Service/Provisioning/AdminProvisioningService.php:312-334`
- **Fix:** `INSERT … ON DUPLICATE KEY UPDATE`, or catch `UniqueConstraintViolationException` and re-read.

#### M22. The log file grows without limit, and the log toggle does nothing — **OPEN**
- **Where:** `Resources/config/services.xml:8, 105-108`, `Resources/config/config.xml:57`
- **Fix:** register a `sw6oidc` Monolog channel with a `rotating_file` handler. Wire `debugLoggingEnabled`, or delete it.

### Frontend (new and revision 1)

#### New in revision 2

| # | Where | Issue | Fix |
|---|---|---|---|
| **F-N1** NEW | `extension/sw-inactivity-login/index.js:86-95` + `extension/sw-login/index.js:125-150` | **SSO re-login from the inactivity modal can resume the session as a different admin.** Core's password re-login is tied to `lastKnownUser`; the SSO path accepts whoever the IdP returns. `sw6oidcFinishLogin()` then restores the previous admin's route, drops `lastKnownUser` and broadcasts `{inactive:false}` on `session_channel`, so **every other tab on the modal carries on under the new identity**. | Save the expected username with the return route. After the nonce exchange, compare it with `/api/_info/me`. On a mismatch, skip the return route and the broadcast, and do a normal fresh login. |
| **F-N2** NEW | `extension/sw-admin-menu/index.js:15-21, 47-69` | **IdP logout is silently skipped once the access token has expired.** The raw `fetch` sends `getToken()` without refreshing it (F-H4). After 10 minutes that gets a 401, the code falls back to a local logout, and the IdP session survives, which is the very thing the feature exists to prevent. | Call it through `ApiService.httpClient`, or run `loginService.refreshToken()` first. |
| **F-N3** NEW | `module/sw6oidc-sessions/page/sw6oidc-sessions-list/sw6oidc-sessions-list.html.twig:36-46`, `index.js:89-91` | **Expired sessions show as "Active".** "Active" just means `loggedOutAt IS NULL`. Sessions that ended by refresh-token expiry, inactivity or a closed browser stay "Active" for up to 90 days, and the "only active" filter shows them too. Admins will force-logout ghosts and trust the list for incident response. | Treat rows older than the refresh-token lifetime as expired, or label the badge "No logout recorded". |
| **F-N4** NEW | `views/storefront/component/account/login.html.twig:25-37`, `extension/sw-login/sw-login.html.twig:19-43` | **The UI can hide every login method.** The password form is hidden based on `PasswordLoginPolicy` (all active providers), but SSO buttons use `getVisibleProviders()`, which also requires `show_*_link`. If the flag is set on a provider whose link is hidden and there are no passkeys, the page offers no way to log in. | Hide the form only when at least one SSO or passkey button is shown, or reject that combination in the write guard. |
| **F-N5** NEW | `extension/sw-sso-users-permission-user-detail/sw-sso-users-permission-user-detail.html.twig:1-314` | **Byte-for-byte copy of core's `sw_settings_sso_user_detail_content`**, apart from 18 inserted lines (`74-91`). About 25 inner core blocks, and every future core change to them, are frozen. It only exists because of F-C1. | Override a narrow block with `{% parent %}`, as `sw-users-permissions-user-detail` already does. Better: fix F-C1 and remove the override. |
| **F-N6** NEW | `module/sw6oidc-provider/page/sw6oidc-provider-detail/sw6oidc-provider-detail.html.twig` (health card) + `index.js:370-373` | **The webhook URL can't be cleared, and whether one is stored isn't visible.** A blank field means "keep". The "stored" placeholder only appears after diagnostics have run. | Add an explicit "Remove" action that sends `null`. Expose `webhookConfigured` when the page loads. |
| **F-N7** NEW | `module/sw6oidc-sessions/index.js:1-39`, `page/…/index.js:53-55` | **The sessions module is superadmin-only in practice** (F-H5). Owner names also need `user:read` and `customer:read`, and nothing declares that dependency. | Add a privilege mapping with the dependencies. |
| **F-N8** NEW | `module/sw6oidc-provider/page/sw6oidc-provider-detail/*` | **Access-control rules, the superadmin grant, the webhook and the regex transforms are all editable in the UI by viewers.** The page doesn't inject `acl`. | Gate every edit control with `acl.can('sw6oidc_provider.editor')`. |

#### Revision 1

| # | Status | Where (current) | Remaining issue / fix |
|---|---|---|---|
| F-M1 | OPEN | `extension/sw-inactivity-login/index.js:136-150` | The passkey path never calls `updateLastUserActivity()`, and `rememberMe` is ignored everywhere. Share one login-completion helper. |
| F-M2 | OPEN (worse) | `extension/sw-inactivity-login/sw-inactivity-login.html.twig:11-92` | Still a full copy of core's only block, and it diverges further now. At minimum, document a re-sync step for every core upgrade. |
| F-M3 | OPEN | `service/webauthn-codec.js` ≡ storefront codec; the ceremonies are duplicated | Use one ceremony service and a shared codec, or native `PublicKeyCredential.parseCreationOptionsFromJSON()`. |
| F-M4 | OPEN | `provider-list/index.js:49` + twig `:23`; `passkey-list/index.js:84` + twig `:24` | Pagination is dead. Use the `listing` mixin. |
| F-M5 | OPEN | `provider-list/index.js:52-55`, `passkey-list/index.js:87-92`, `provider-detail/index.js:331-341` | No `catch`/`finally`. Use `try/finally`. |
| F-M6 | OPEN (worse) | `passkey-list.html.twig:5-12`, `provider-detail.html.twig:8-15` and all new controls | See F-N8. |
| F-M7 | OPEN | `provider-detail/index.js:348-362, 446-452` | Re-discovery runs on every save, and it mass-assigns the response. Allow-list the endpoint keys. |
| F-M8 | OPEN | `provider-detail/index.js:494-503` | The popup opens after an `await`, so browsers block it. Open it synchronously. |
| F-M9 | OPEN | `provider-detail/index.js:521-531` | No `event.source` check, and `loadEntity()` discards unsaved edits. |
| F-M10 | OPEN (unverified) | `provider-detail/index.js:379-385` | A stale `isNew()` entity can survive after the router push. Watch `$route.params.id`. |
| F-M11 | OPEN | `provider-detail/index.js:440, 478, 497`, `passkey-list/index.js:151`, `profile-passkey/index.js:102, 129`, storefront plugins | `response.json()` is called before `ok` is checked. |
| F-M12 | OPEN | `app/storefront/src/passkey/passkey-login.plugin.js:63-66` | `showError()` is empty. |
| F-M13 | OPEN (worse) | `extension/sw-login/sw-login.html.twig:42-44` vs `80-85` | The error sits inside the wrapper's `v-if`, which now also hides the access-control denial message. |
| F-M14 | OPEN | `views/administration/index.html.twig:43-51`, `service/defer-module-register.js:15` | One bundle for every page, plus a deprecated `Shopware.State` polling loop. |
| F-M15 | OPEN | `views/storefront/component/account/login.html.twig:57` | `\|sw_sanitize` on an admin-controlled label. Use plain autoescaping. |

---

## Low

### New backend findings

| # | Where | Issue | Fix |
|---|---|---|---|
| **N-L1** NEW | `Controller/Oidc/BackChannelLogoutController.php:74-76` | Error messages go back to an **anonymous** caller verbatim. "No active provider matches…" vs "signature verification failed" reveals which issuer and client_id the shop uses, and JWKS transport errors leak internal URLs. | Return a fixed `invalid_request`, and log the detail. |
| **N-L2** NEW | `Controller/Oidc/BackChannelLogoutController.php:138-155` | Replay protection is weak. A token **without `jti`** is accepted, although BCL 1.0 §2.4 makes it REQUIRED. The replay check is get/isHit/save, which isn't atomic, lives in wipeable `cache.app`, and is capped at 1 h. There is no `iat` max-age check. | Reject a missing `jti`. Use `AtomicCacheInterface` (SET NX) with TTL = `exp` + skew. Reject `iat` older than 5 minutes. |
| **N-L3** NEW | `Service/Session/Sw6OidcIdpLogoutHandler.php:52-70` | After destroying **all** of an admin's sessions, only the sid-matched registry entries are removed. The others point at dead sessions and confuse later logouts (N-M4). | Remove all of `resolveByUser('admin', id)` after destruction. |
| **N-L4** NEW | `Service/Security/Sw6OidcEncryptor.php:35-42` | The key is an unkeyed BLAKE2b hash of `APP_SECRET‖context`, not a KDF. One key covers both columns and every row, and there is no associated data, so envelopes can be swapped between rows or fields and are accepted on write. | Use `sodium_crypto_kdf_derive_from_key` per field, and XChaCha20-Poly1305 with AD = entity/field/id. |
| **N-L5** NEW | `Service/Security/Sw6OidcEncryptor.php` + callers (`RpInitiatedLogoutService`, `OidcConfigTransfer`) | When decryption fails, the **envelope** is returned instead of failing. Revocation then sends ciphertext to the IdP, and `--plaintext` export writes an envelope while claiming plaintext. | Add `decryptOrNull()` and use it wherever the secret is about to be used. |
| **N-L6** NEW | `Core/Content/Provider/Field/Sw6OidcEncryptedFieldSerializer.php:61-69` | Core calls `decode()` when it builds `EntityWrittenEvent` payloads, so every `sw6oidc_provider.written` listener (third-party plugins, logging subscribers) receives the **plaintext** secret and webhook URL. | Blank these fields in a written-event subscriber, or document it. |
| **N-L7** NEW | `Service/Provisioning/AttributeTransformer.php:140-143` + the caller that falls back to the raw value | When a regex fails (for example an IdP-controlled value pushing an admin pattern over the backtrack limit), the **raw** IdP value is used. If the transform normalises the email, which is the linking key (C2), the raw email is used for linking instead. | For identity attributes (email, username), fail the login on a transform error. |
| **N-L8** NEW | `Service/Health/WebhookNotifier.php:43` | Symfony transport exception messages include the full URL, so the webhook token ends up in the plugin log. | Log the exception class and curl error code only. |
| **N-L9** NEW | `Console/ExportOidcConfigCommand.php:69` | `--plaintext` export uses `file_put_contents`, which creates the file with the umask (usually 0644, world-readable), follows symlinks and overwrites without asking. | Create the file with 0600 and `fopen(…, 'x')`. Require `--force` to overwrite. |
| **N-L10** NEW | `Service/Session/Sw6OidcSession.php`, `Service/Oidc/LogoutContextStore.php`, `Service/AdminAuth/AdminLoginNonceService.php` | The raw id_token (PII plus `sid`) is stored twice, in plaintext, for 24 h, in `cache.app` files under `var/cache`. | Store it once, encrypted with `Sw6OidcEncryptor`, or keep only the fields you need. |
| **N-L11** NEW | `Service/Config/OidcConfigTransfer.php:307-325` | `--overwrite` deletes all access-control rules and mappings, then inserts what the file contains. A file without `accessControlRules` (hand-edited, filtered with jq, or from an older export) **silently removes every login gate**. | When overwriting, require the key to be present, or replace only the children whose key is present. |
| **N-L12** NEW | `ScheduledTask/SessionActivityCleanupTaskHandler.php`, no subscriber | Activity rows (IP, user agent, `sub`) survive account deletion for up to 90 days, which is a GDPR erasure gap. The IP is stored in full. The cleanup `DELETE` is a single unbounded statement. | Delete rows on `user.deleted` and `customer.deleted`. Delete in batches. Offer IP truncation. |
| **N-L13** NEW | `Core/Content/AccessControlRule/Sw6OidcAccessControlRuleDefinition.php` | `operator` isn't validated when written. A typo makes the rule deny everyone (fail closed, but silently). | Add `Choice(self::OPERATORS)`, as `transform_function` already has. |
| **N-L14** NEW | `Service/Cache/RedisConnectionFactory.php:29-53` | A configured DSN without ext-redis returns `null` and logs nothing. The return values of `auth()`/`select()` are ignored. Each request opens a new connection with a 1.5 s connect timeout and no read timeout, and never retries. When Redis is down, every login stalls, and a multi-node setup silently degrades to non-atomic state kept per node. | Log (or fail) when a configured DSN can't be used. Check the `auth()`/`select()` results. Use `pconnect` with `OPT_READ_TIMEOUT`, and briefly cache "Redis is down". |
| **N-L15** NEW | `Subscriber/Sw6OidcCspSubscriber.php:55, 70` | `headers->get()` reads only the first CSP header and `set()` replaces all of them, so stricter additional policies are lost. `Content-Security-Policy-Report-Only` is ignored. | Iterate over `headers->all('content-security-policy')`. |
| **N-L16** NEW | `Service/Passkey/PasskeyAuthenticationService.php:121` | A backwards sign-count, the clone signal, is rejected by the library but turned into a generic exception. Nothing is logged as a possible clone, and the credential is not disabled. | Use a custom `CounterChecker` that logs at warning level and disables the credential. |
| **N-L17** NEW | `Event/AdminBeforeCreateEvent.php`, `Service/Provisioning/AdminProvisioningService.php` | A listener can replace the whole create payload, including `admin: true` and `aclRoles`, which skips the two-gate superadmin rule. This is documented as deliberate, but nothing is re-checked afterwards. | Re-check `admin` and `email` after dispatch, or rename the event and document it as fully trusted. |
| **N-L18** NEW | `Service/Security/Exception/PasswordLoginDisabledException.php:17` | It subclasses `CustomerOptinNotCompletedException` so the storefront renders a snippet. Any handler for opt-in errors (for example a "resend opt-in mail" flow) will fire on it. | Handle it in a decorated `AuthController` error path instead, or at least document it. |
| **N-L19** NEW | `Controller/HealthCheckController.php` | The unauthenticated endpoint runs an uncached DAL query plus a decrypt on every hit, and tells anyone when an IdP starts failing. | Cache the result for 30 s. Optionally require a shared token. |
| **N-L20** NEW | `Service/Session/Sw6OidcSessionRegistry.php` (`resolveIndex`) | Up to 50 separate cache reads per lookup (an N+1 on the cache). | Use a `getItems()` batch, or better, the DB table from N-H3. |
| **N-L21** NEW | `Service/Session/Sw6OidcSessionRegistry.php` (`revoke`, `revokeBySid`) | Public methods with no callers. | Delete them. |

### New frontend findings

| # | Where | Issue | Fix |
|---|---|---|---|
| **F-N9** NEW | `extension/sw-login/index.js:128-142`, `service/sso-return-route.js:27-41` | If an inactivity SSO attempt is abandoned, its return route stays for 10 minutes and is applied to the next unrelated login in that tab, possibly by a different admin, together with the `{inactive:false}` broadcast. | Clear it when `sw-login` boots without a nonce, or tie it to a one-time id carried through the OIDC round trip. |
| **F-N10** NEW | `service/sso-return-route.js:13, 37` | `/\evil` passes the path check. It is harmless today, because the value only reaches `$router.push()` in hash mode and comes from same-origin storage. | For defence in depth, match `^/[A-Za-z0-9/_\-.?=&%]*$` and reject `/login`. |
| **F-N11** NEW | `module/sw6oidc-sessions/page/…/sw6oidc-sessions-list.html.twig`, `index.js` | Columns show as sortable, but `disableDataFetching` makes header clicks do nothing. There is no request ordering when toggling or paging. | Set `sortable: false`, or handle `@column-sort`. Add a request counter. |
| **F-N12** NEW | `module/sw6oidc-sessions/page/…/index.js:97-99, 150` | Load failures show nothing. For passkey and admin rows, the confirm dialog says "End this session", but the server ends **all** of that account's sessions. | Show an error notification. Choose the confirm text by `loginMethod` and `userType`. |
| **F-N13** NEW | `extension/sw-inactivity-login/index.js:86-95` | The full-page SSO redirect skips core cleanup, so `inactivityBackground_<hash>` (a screenshot of the admin page as a data URL) and `sw-admin-previous-route_<hash>` stay in `sessionStorage`. | Remove both before navigating. |
| **F-N14** NEW | `extension/sw-admin-menu/index.js:44` | `window.location.href = logoutUrl` accepts any string from the server. | Check that `new URL(logoutUrl).protocol` is `https:` or `http:`. |
| **F-N15** NEW | `module/sw6oidc-provider/page/sw6oidc-provider-detail/index.js:175-178` | The post-logout URL placeholder uses the **admin** origin for a storefront-scoped route, so it 404s where the admin host isn't a sales-channel domain. | Get the URL from the server. |
| **F-N16** NEW | `provider-detail.html.twig` (`#column-transformParams`), `index.js:583-593` | Transform params are hidden when they are `null` (for example on imported rows). Regex patterns aren't validated, so they fail silently at login. | Fill in default params. Validate the pattern on save, on the server. |
| **F-N17** NEW | `component/sw6oidc-user-provider-info/index.js:57-80` | A fast `userId` change lets an older response overwrite a newer one. | Compare `userId` after the `await`. |

### Revision 1 backend findings

| # | Status | Where (current) | Remaining issue / fix |
|---|---|---|---|
| L1 | OPEN (worse) | 14 files, up from 8 | `Symfony\Component\Routing\Annotation\Route` → `Attribute\Route`. |
| L2 | OPEN | `Storefront/Service/OidcCustomerLoginRoute.php:85` | `BadCredentialsException` → `CustomerException::badCredentials()`. |
| L3 | PARTIAL | see the table below | — |
| L4 | OPEN | `Service/Oidc/OidcCallbackProcessor.php:45-54`, `AuthorizationRequestBuilder.php:50-60` | Remove the "temporary diagnostic aid" blocks before release. |
| L5 | PARTIAL | `Service/Oidc/OidcLiveLoginTestService.php:51, 94, 112` | It catches `\Throwable` now, so there is no more 500. It is still a separate copy of the pipeline with no group normalisation. |
| L7 | OPEN | `Migration/Migration1730000001CreateOidcSchema.php:133-142` | `credential_id VARCHAR(255)` and no `user_handle` index. |
| L8 | OPEN | `AdminProvisioningService.php:312-369`, `CustomerProvisioningService.php:346-363` | 8–15 queries per login. |
| L9 | OPEN | `AdminProvisioningService.php:125`, `CustomerProvisioningService.php:144, 162, 184` | The email is used as the first name. |
| L10 | OPEN | `Service/Security/AuthorizationFlowContext.php:45-55` | Blind casts. A missing key gives a PHP warning and an empty string. |
| L11 | OPEN | e.g. `PasskeyController.php:62, 127`, `PasskeyAdminController.php:67, 175`, `OidcAdminAuthController.php:129, 133, 557` | Magic `'admin'`/`'customer'` strings. Use a backed `enum LoginType`. |
| L12 | OPEN | `SendAuthorizationRequestController.php:33`, `OidcCallbackController.php:55` | Remove `XmlHttpRequest => true` from full-page routes. |
| L13 | OPEN | `Resources/config/services.xml` (≈700 lines, up from 400) | Switch to autowiring. |
| L14 | OPEN | e.g. `OidcAdminAuthController.php:50`, `RpInitiatedLogoutService.php:14` | Comments about "the plan", "Phase n" or "the Magento module". Move project history to ADRs or commit messages. |
| L15 | NOT VERIFIED | — | No `vendor/`, so the ~460 tests CLAUDE.md claims could not be run. Coverage wasn't audited. |
| L16 | OPEN | `Sw6Oidc.php:21-29` | Multi-statement `executeStatement`, and no cleanup of `system_config` or scheduled tasks. |
| L17 | OPEN | `CustomerProvisioningService.php:93-112`, `OidcCustomerLoginRoute.php:69-86` | Bound-sales-channel logic is duplicated. |
| L18 | OPEN | `Migration1758000001AddProviderTestStatus.php:19`, `Sw6OidcProviderDefinition.php:93` | No `Choice` constraint on `last_test_status`. |

**L3: unused and dead code**

| Symbol | Status | Where |
|---|---|---|
| `ClaimsNormalizer::extractEmail()` | OPEN | `Service/Oidc/ClaimsNormalizer.php:117`. No caller. Its "first string that looks like an email" fallback would be a security bug if it were ever wired. **Delete it.** |
| `TokenExchangeService::refreshAccessToken()` | OPEN | `Service/Oidc/TokenExchangeService.php:59` |
| `ProviderResolver::hasVisibleProvider()` | OPEN | `Service/Provider/ProviderResolver.php:99` |
| `button_label`, `button_color` | OPEN | Definition `:83-84` and entity. No reader in PHP, Twig or JS. |
| `debugLoggingEnabled` | OPEN | `config.xml:57` |
| COSE `ES512`/`RS512` | OPEN | `WebauthnCeremonyFactory.php:122`. Registered but never offered. EdDSA is still missing. |
| `revokeToken($provider, null)` calls | OPEN | `OidcLogoutRoute.php:105`, `OidcAdminAuthController.php:464`. Guaranteed no-op (M14). |
| `Sw6OidcSessionRegistry::revoke()`, `revokeBySid()` | NEW | No callers (N-L21). |
| `PasskeyCredentialRepository::setContext()`, `RedisAtomicCache` wiring, `sync_on_sso`, `transform_*`, `disable_non_oidc_*` | FIXED | Removed or wired. |

### Revision 1 frontend findings

- **Entrypoints JSON — OPEN.** `Twig/AdminEntrypointsExtension.php:53, 59`: a malformed `entrypoints.json` still makes the admin login page return 500. The path is still hard-coded.
- **Login-options queries — OPEN (worse).** `Twig/StorefrontLoginOptionsExtension.php:55-77`: three unmemoised lookups per render now, because the new `isPasswordLoginDisabled()` loads the providers again.
- **User-listing race — OPEN.** `extension/sw-users-permissions-user-listing/index.js:30-47`: no request counter.
- **`{...ssoSettingsService}` spread — OPEN.** `extension/sw-profile/index.js:108-109`. It becomes moot once F-C1 is fixed.
- **Raw dates and untranslated values — PARTIAL.** The provider detail and sessions list are fixed. The passkey list (`createdAt`, `userType`) and provider list (`loginType`, `isActive`) are still raw.
- **Snippet keys without fallback — OPEN.** `testConnection.check.*`, `liveTest.step.*`, `testStatus.*` and the new `configProblem.*`. All current codes have snippets, but none has a fallback.
- **Hard-coded strings — OPEN.** `component/sw6oidc-rp-id-field/index.js:70`, and the German dictionary in `extension/sw-login/index.js:32-44`. Diagnostics also show the backend's English `reachability.detail` unchanged.
- **Deprecated `sw-*` components and inline styles — OPEN (worse).** The new sessions list mixes `sw-switch-field`/`sw-button` with `mt-*` components. `provider-detail.html.twig` has 12 inline `style=` attributes, plus `sessions-list.html.twig:8, 23`.
- **Dead code — OPEN.** `provider-list/index.js:58-60` (`onChangeLanguage`); `acl` is injected but unused in `passkey-list/index.js:30`.
- **Sidebar passkey link — OPEN.** `views/storefront/page/account/sidebar.html.twig:16-21`: the link shows even when passkeys are disabled.
- **`customer-overview-personal-company` override — OPEN.** It changes the account overview for every customer, which is out of scope for an SSO plugin.
- **Synchronous `PluginManager.register` — OPEN.** `app/storefront/src/main.js:6-8`: use `() => import(...)`.
- **`window.prompt`/`confirm`/`alert` — OPEN (worse).** New uses: `sessions-list/index.js:150`, `component/sw6oidc-user-provider-info/index.js:86`, `app/storefront/src/passkey/passkey-delete-confirm.plugin.js:16`.

---

## Resolved since revision 1

| ID | Evidence |
|---|---|
| **H5** `client_secret` readable through the API | `Sw6OidcEncryptedField` without `ApiAware` (`Sw6OidcProviderDefinition.php:57-59`). Core's encoders drop fields that aren't ApiAware. Secrets are encrypted with libsodium secretbox and a fresh nonce each time, and an idempotent migration encrypts existing rows. The connection test never sends the secret anywhere. *Residual risks: N-M12, N-L4, N-L5, N-L6.* |
| **H11** Storefront logout overwrote Store API JSON; shared state | `Storefront/Service/OidcLogoutRoute.php` decorates `LogoutRoute`. `PendingLogoutRedirect` is request-scoped and implements `ResetInterface`. The response is only rewritten on `frontend.account.logout.page`. *Residual: a Store API logout consumes the logout context but never gets the IdP URL back; return it in the response.* |
| **M12** Partial SSRF protection | `Service/Http/Sw6OidcHttpClientFactory.php` wraps the client in `NoPrivateNetworkHttpClient`, and every outbound client uses it. It checks the resolved IP on each connection and redirect and covers CGNAT, IPv4-mapped IPv6, NAT64 and similar ranges. The write guard validates URLs on every DAL write, including sync and the CLI import. *Residual: avatar fetch (M19); `max_redirects` not set to 0; `SW6OIDC_ALLOW_INSECURE_IDP_URLS=1` disables all of it.* |
| **L6** Group `"0"` dropped | `ClaimsNormalizer.php:98-107` uses an explicit `!== null && !== ''` callback. |
| **F-H1** Storefront build not committed | `app/storefront/dist/` is committed and up to date. *Still open: the admin build `public/` is git-ignored, a missing build fails silently, and CI has no build or freshness check.* |
| L3 rows | `setContext()` removed; `RedisAtomicCache` wired; `sync_on_sso` dropped; `transform_*` and `disable_non_oidc_*` now have readers. |

**Also checked and found sound in revision 2** (credit where due):
- `verifyLogoutToken()` checks the RS\* allow-list, `events`, `iat`, the `nonce` ban and sub/sid. Only verified claims drive decisions.
- `PostLogoutState` uses HMAC-SHA256 with `hash_equals` and a strict target whitelist.
- Admin destruction bumps `last_updated_password_at`, and core's `SymfonyBearerTokenValidator` does reject older tokens.
- Customer destruction via `SalesChannelContextPersister::delete()` works.
- The superadmin two-gate rule holds in both the create and the sync paths.
- Access-control rules run before any lookup, creation or sync.
- OAuth state is single-use and bundles PKCE, nonce and provider.
- webauthn-lib 5.3 usage:
  - usernameless login requires a matching userHandle;
  - the ceremony nonce is consumed once;
  - the updated counter is saved;
  - delete is scoped to the owner.
- `OidcUserProviderAdminController` checks privileges per user type.
- The live-test popup has its own nonce'd CSP and a `postMessage` restricted to the origin.
- The error-ticket hand-off is single-use and keeps free text out of the URL.
- There is no `v-html` or `innerHTML` anywhere in the frontend. The `BroadcastChannel` and `message` listeners are cleaned up.
- Snippet files are complete in both locales.
- Dry-run import nests transactions correctly using DBAL 4 savepoints.
- The CSP origin collector only uses SSRF-validated hosts, so directive injection isn't possible.

---

## Edge cases that break the plugin today

| Input or situation | Result |
|---|---|
| IdP returns `email_verified: false` | Accepted, and pre-existing accounts, admins included, are linked (C2) |
| Customer-scoped provider id passed to the admin login | Admin login (C1) |
| `POST /api/oauth/token` with `Content-Type: application/x-json` and a password grant | Password login works although it is disabled, and core's rate limiter is bypassed (N-H1) |
| `redirectTo=https://x//evil.tld/` or `/\evil.tld` | Redirect off-site after login (H4) |
| A forged logout token once a minute, then the IdP rotates its key | All SSO logins fail for up to 24 h (N-H2) |
| `cache:clear` or a deploy, then the IdP sends a back-channel logout | 200 OK, session not ended (N-H3) |
| Admin session older than 24 h, IdP sends a back-channel logout | 200 OK, session not ended (N-H3) |
| Admin logged in on two devices, logs out on the older one after 10 min | The other device's registry entry is removed, and the wrong id_token_hint is sent (N-M4) |
| Rule `groups neq "blocked"`, user has groups `["blocked","staff"]` or no groups claim | Access granted (N-M10) |
| Rule `email contains "@example.com"`, user `eve@example.com.attacker.io` | Access granted (N-M11) |
| Redis 6.0 server with phpredis ≥ 5.3 | Every login fails (N-M13) |
| Alerting turned off while a provider's last health check failed | `/sw6oidc/health` returns 503 forever (N-M14) |
| 10 bogus `/sw6oidc/callback?state=x` requests behind a CDN with no `trusted_proxies` | All SSO logins blocked for a minute, repeatable (N-M3) |
| Customer registers with a password while SSO-only mode is on | Logged-in password account (N-M9) |
| Inactivity modal, a different person signs in at the IdP | All waiting tabs continue as the other admin (F-N1) |
| Admin deactivated in Shopware | Can still log in via SSO or passkey (H1) |
| Admin demoted at the IdP | Keeps the old role (H3) |
| Assertion made on `https://evil.shop.tld` for RP ID `shop.tld` | Accepted (N-M1) |
| `birthdate: "0000-05-01"` or `"tomorrow"` | Login crashes, or the birthday is set to tomorrow (M9) |
| Two tabs finishing the first login at once | 500 on a unique key (M21) |
| Transport timeout on the token exchange | The retry burns the code, and the IdP may revoke the session (M3) |
| Config import `--overwrite` from a file without `accessControlRules` | Every login gate is silently removed (N-L11) |

---

## Future improvements (optimisation and hardening roadmap)

Items from revision 1 that are now done are no longer listed: back-channel logout, SSRF client, secret encryption, webauthn-lib 5.x, CHANGELOG.

1. **Identity model (still the top priority):** bind on `(provider_id, iss, sub)`, and make account linking an explicit, user-confirmed action. This one change removes C2 and most of the email-related edge cases. The `sub` is already extracted for the session registry, so the plumbing exists.
2. **Treat session state as data, not cache.** Put the session registry, one-time tokens (state, nonces, ceremonies, jti replay) and logout contexts in DB tables with explicit expiry, or in a dedicated non-clearable Redis pool. This fixes N-H3, M4, N-L2 and N-L10 together.
3. **Build the protocol layer on a maintained library.** Use `facile-it/php-openid-client` or `web-token/jwt-library` with a proper JWKS key selector (`kid`, rotation, leeway, `azp`) instead of hand-rolling it. That fixes M11 and N-H2 at the root.
4. **One step-up authentication primitive.** Use a single "fresh auth" service (OIDC `max_age=0` with an `auth_time` check, or a UV-required passkey assertion). Use it for verify-session (H2, F-C1), passkey registration (H7), force logout, and the re-login from the inactivity modal (F-N1). Remove `user-verified` from every other path (N-M17).
5. **Enforce policy at the grant or route, not by re-parsing HTTP.** Decorate core's password grant, `RegisterRoute` and `RegisterConfirmRoute` (N-H1, N-M9).
6. **Evaluate Shopware 6.7's native Administration SSO** (`Shopware\Core\Framework\Sso`, `SsoService::revokeUserTokens()`). The admin half of this plugin should either build on it or document why it deliberately diverges.
7. **Rate limiting redesign:** use a consuming budget on flow-start and options endpoints, and failure-only budgets on redemption endpoints. Use separate scopes for admin and storefront. Key IPv6 by /64. Never count legitimate "nothing to do" logouts (N-M3, N-M6, N-M7, N-M15).
8. **Access-control rules DSL:** define fail-closed semantics for negative operators and missing claims, and add `ends_with`/`email_domain`. Add a "test this rule against the last live-test claims" button in the admin (N-M10, N-M11).
9. **Tests:**
   - Unit-test every security predicate: relay state (including the H4 corpus), the password-guard content types (N-H1), access-rule truth tables (N-M10, N-M11), flow `loginType`, grant user validation, and role replacement.
   - Integration tests against Dex that cover:
     - back-channel logout after `cache:clear`;
     - Redis 6.0;
     - two-device admin logout.
   - Mutation testing (Infection) on `Service/Security` and `Service/AdminAuth`.
   - **Make `composer install && composer ci` reproducible in this checkout.** For this revision, none of the tooling could be run.
10. **Static analysis:** raise PHPStan from level 5 to 8, and add strict rules and the Shopware PHPStan extension. Turn on Psalm `findUnusedCode`, so L3 and N-L21 can't come back.
11. **Performance:**
    - Cache provider plus mappings per request.
    - Memoise the Twig login-options lookups.
    - Skip unchanged avatars (M19).
    - Batch registry reads (N-L20).
    - Move provisioning side effects (avatar import, sync) to the message queue.
12. **Observability:**
    - Write security-relevant events to `log_entry`: passkey added, SSO unlinked, password-login flag changed, endpoint URL changed (N-M12), force logout.
    - Make the audit log append-only (N-M8).
    - Emit `Sw6OidcLoginFailedEvent` for Flow Builder.
13. **Passkeys:**
    - `userVerification=required`.
    - An exact origin allow-list (N-M1).
    - EdDSA.
    - Clone detection that disables credentials (N-L16).
    - Optionally `direct` attestation with an FIDO MDS allow-list for admin credentials.
14. **Multi-domain and headless:** Store API routes (`/store-api/sw6oidc/*`), per-domain RP IDs, and a Store API logout response that returns the IdP logout URL (H11 residual).
15. **Configuration hygiene:** move `SW6OIDC_*` env vars into a `config/packages/sw6oidc.yaml` bundle config with a real `Configuration` tree. Make the default log level `warning`.
16. **Frontend architecture:**
    - One `Sw6OidcApiService` (F-H4).
    - An ACL mapping file (F-H5).
    - A separate tiny pre-auth bundle for `sw-login` (F-M14).
    - No full copies of core templates (F-M2, F-N5).
    - A CI job that builds both bundles and fails if the committed output is stale.

---

## Suggested fix order

1. **C1, C2, H1**: identity, login type and account-state checks. One PR, with tests. *Nothing else matters much until this lands.*
2. **N-H1, N-M9, H8 residue**: make "password login disabled" actually true.
3. **H2 + F-C1, N-M17, H6, H7, N-M1, N-M2, F-H6, F-N1**: re-authentication and passkey policy.
4. **N-H2, N-H3, N-M4, N-M5**: make IdP-initiated logout reliable and not abusable (key selection by `kid`, DB-backed registry).
5. **H3, H4 + F-H2, H9, N-M12**: privilege revocation, redirects, logging, secret exfiltration.
6. **N-M10, N-M11, N-L11, N-L13**: fail-closed access-control rules.
7. **N-M3, N-M6, N-M7, N-M15 (M5)**: rate limiter redesign.
8. **F-H4, F-H5, F-N2, F-N7, F-N8**: frontend API client and ACL.
9. **H10, M1–M4, N-M13, N-M14**: TTLs, CSRF binding, `sub` check, retry, token store, Redis, health endpoint.
10. Everything else, then the roadmap.

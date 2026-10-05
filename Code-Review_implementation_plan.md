# Implementation plan for `Code-Review.md` (revision 4)

| | |
|---|---|
| **Source** | `Code-Review.md` revision 4 (commit `58b232a`, branch `fix/code-review-rev2`) |
| **Goal** | Fix every finding still open in revision 4: R3-H\*, R3-M\*, R3-L\*, R3-F\*, the frontend hygiene rows, the Unused-code table and R4-L\*. Roadmap items come last and are optional. |
| **Plugin version after this work** | `0.2.0` (behaviour and schema changes, see [Open questions](#open-questions)) |
| **Structure** | 12 phases (P0–P11) plus an optional roadmap phase (P12). Each phase is one or more commits, and every commit message names the finding IDs, as on the rev-2 branch. |

Paths are relative to `src/` unless they start with `tests/`, `.github/` or `src/Resources/`. Admin JS paths start at `src/Resources/app/administration/src/`. The full description of each finding is in `Code-Review.md`; this plan only says **how** to fix it and **how to prove** it is fixed.

---

## Ground rules (apply to every phase)

1. **Test first for every High and Medium.** Write a failing test that reproduces the finding, then fix. For Lows, a test wherever the behaviour is observable.
2. **Gates per phase:** `composer ci` stays green: PHPCS, PHPStan, Psalm, Rector, unit tests. From P0 on, the `integration`, `e2e` and `assets` CI jobs must be green too.
3. **Rebuild the committed bundles** whenever admin or storefront JS changes: `src/Resources/public/administration/**` and `src/Resources/app/storefront/dist/**`. The `assets` job (blocking after P0) enforces this.
4. **Migrations** continue at `Migration1790800013…`. Each one is idempotent (`SHOW COLUMNS` / `information_schema` guards). Destructive steps go in `updateDestructive()`.
5. **Finding IDs in code comments** only where the code would otherwise look arbitrary, the same style as today (`// R3-H1: …`).
6. **Update `CLAUDE.md` and `TECHNICAL_DOCUMENTATION.md` in the same commit** as any change to behaviour they describe (R3-L52 is then closed for good in P10).
7. When a phase is done, mark its findings `Fixed (<commit>)` in a revision 5 status table in `Code-Review.md`.

---

## Phase overview

| Phase | Theme | Findings | Rough size |
|---|---|---|---|
| **P0** | Make the test gate real | R4-L7, R3-L51 | S |
| **P1** | Make the broken features work | R3-H1, R3-F1, R3-F4, R3-F5, R3-F9 | M |
| **P2** | Write-protect trust data, secrets | R3-H2, R3-H3, R3-H4, R3-M4, R3-M23, R3-L42, R3-L43 | L |
| **P3** | Fresh-auth proof and passkey hardening | R3-H6, R3-M5, R3-M6, R3-M7, R3-M8, R3-F3, R3-L13–L19, R3-L41 | L |
| **P4** | One SSO-only invariant | R3-H7, R3-M20, R3-M21, R3-M22, R3-L37 | M |
| **P5** | Reliable IdP logout, bounded state | R3-H5, R3-M1, R3-M17, R3-M18, R3-M19, R3-L8, L9, L30–L36, L39, L40 | L |
| **P6** | Identity and provisioning | R3-M9–R3-M16, R3-L20–R3-L29 | XL |
| **P7** | OIDC protocol, HTTP, JWT | R3-M2, R3-M3, R3-L1–L4, L6, L7, L12, L44, L45, R4-L1–R4-L4 | M |
| **P8** | Frontend | R3-F2, R3-F6–R3-F8, R3-F10–R3-F17, 3 hygiene rows | L |
| **P9** | Shopware conventions, DAL, DI | R3-M24, R3-L5, L10, L38, L46–L50, R4-L5, R4-L6 | M |
| **P10** | Unused code, comments, docs | Unused-code table, R3-L11, R3-L52 | S |
| **P11** | Static analysis | PHPStan level 8 (52 errors), Psalm unused code | M |
| P12 (optional) | Roadmap | Future improvements 9–11, 14, 15, F-M2, F-M14, L13 (item 12, the `mt-*` migration, moved to P8 per Q9) | — |

The order follows the review's "Suggested fix order". P0 comes first, because without it no later fix can be proven. **P0–P5 are the release blockers**: together with P6's R3-M9–R3-M11 they cover every blocker listed in the review.

---

## P0 — Make the test gate real

**Findings:** R4-L7, R3-L51

1. **Real token-issuing test.** Add `tests/Integration/AdminAuth/AdminTokenIssuerTest.php`.
   - Use the plugin's real `AdminAuthorizationServerFactory` server against the test kernel.
   - Send `POST` requests with `Content-Type: application/json` bodies to `/api/sw6oidc/admin/token`, `/api/sw6oidc/admin/step-up/token`, `/api/sw6oidc/admin/step-up/passkey/verify` and `/api/sw6oidc/admin/passkey/login-verify`. Use seeded nonces or ceremonies; passkeys go through the existing `SoftwareAuthenticator` test helper.
   - It must **fail today** with `unsupported_grant_type`.
2. **Admin smoke spec.** Add `tests/E2E/specs/00-admin-smoke.spec.ts`.
   - Log in as a superadmin and open every plugin route: provider list and detail (existing and new), passkey list, sessions list, profile passkey tab, profile general.
   - Assert **zero console errors**. It must fail today on the provider detail page (R3-F1).
   - Second pass as a restricted "viewer" role. This becomes meaningful after P8 (R3-F2).
3. **CI** (`.github/workflows/ci.yml`):
   - Remove `continue-on-error` from `assets` (`:200`) and `e2e` (`:270`).
   - Pin every action to a commit SHA (keep the tag in a comment).
   - Add a top-level `permissions: contents: read`.
   - Run static analysis on the lowest and highest supported PHP versions (8.2 and 8.5).
4. **`composer.json`:** set `config.policy.advisories.block: true`.
5. Commit P0 with the two new tests **expected to fail**. Then go straight to P1 so the branch is red only briefly. Alternatively, mark the two tests `@group known-broken` and remove the group in P1.

**Done when:** CI runs every job blocking, and the two new tests reproduce R3-H1 and R3-F1.

---

## P1 — Make the broken features work

**Findings:** R3-H1, R3-F1, R3-F5, R3-F4, R3-F9

### R3-H1 — `unsupported_grant_type` on step-up and admin passkey login
- `Service/AdminAuth/AdminTokenIssuer.php`: stop converting the client's request. Inject `Psr\Http\Message\ServerRequestFactoryInterface` (Nyholm `Psr17Factory`) and build a fresh request:
  ```php
  $psrRequest = $this->serverRequestFactory->createServerRequest('POST', '/api/oauth/token')
      ->withParsedBody([
          'grant_type' => AdminOidcGrant::GRANT_IDENTIFIER,
          'client_id' => 'administration',
          'scope' => $stepUp ? 'write user-verified' : 'write',
      ])
      ->withAttribute(AdminOidcGrant::REQUEST_ATTRIBUTE_USER_ID, $userId)
      ->withAttribute(AdminOidcGrant::REQUEST_ATTRIBUTE_STEP_UP, $stepUp);
  ```
- Remove the `$request->request->set()` and `$request->attributes->set()` lines (Unused-code row). The `issue()` signature drops `Request`, and all four callers are updated.
- `/admin/token` (`OidcAdminAuthController::exchangeNonce`) uses the same path, so the server no longer trusts a client-sent `grant_type`, `client_id` or `scope`.
- `extension/sw-login/index.js:221`: stop sending `grant_type` and `client_id`.

### R3-F1 — provider detail page crash
- `sw6oidc-provider-detail.html.twig:792`: move the `<template v-if="diagnostics.infrastructure">` warning banners inside a `v-if="diagnostics?.infrastructure"` guard (or inside the `<ul>`'s parent `v-if`).
- The P0 smoke spec proves it.

### R3-F5 — failed passkey login hangs forever
- **Server:** anonymous and redeem endpoints never answer 401. Passkey login-verify, `/admin/token` and `login-error` answer **400** for invalid or expired material and **403** for "different admin" (F-H6). Step-up endpoints answer 403 for a failed verification, so the refresh interceptor never replays them and failures stop counting twice.
- **Client:** `sw6oidc-api.service.js` marks anonymous calls so a 401 can never reach `refreshTokenInterceptor`. Every catch path shows a notification and resets the busy state.

### R3-F4 — same-admin check never matches after inactivity re-login
- `extension/sw-login/index.js:116-118`: read `me?.data?.username`, or use core's `userService.getUser()`. Add an E2E assertion in spec `05`: after an inactivity SSO re-login, the admin returns to the previous route.

### R3-F9 — step-up popup closed leaves the buttons disabled
- **Client:** poll `popup.closed` every 500 ms and reset `sw6oidcStepUpBusy`. Close the popup and clear the timer in `beforeUnmount`.
- **Server:** for `step_up` flows, `OidcAdminAuthController` always renders the `postMessage` page, also with `{error}` on pipeline failures (`:240-241, 281-333`).

**Tests:** the P0 integration test is green; the E2E specs `00` and `05` are green; unit tests cover the error mapping (no 401 from anonymous endpoints).

---

## P2 — Write-protect trust data, keep secrets out of reach

**Findings:** R3-H2, R3-H3, R3-H4, R3-M4, R3-M23, R3-L42, R3-L43

### R3-H2 / R3-H3 — passkey credential and binding entities are writable
- `Sw6OidcPasskeyCredentialDefinition`: add `WriteProtected(Context::SYSTEM_SCOPE)` to every field except `nickname`. Add `user_type` with a `Choice` constraint (`admin`/`customer`). Drop `sign_count` from the definition (Unused-code row; the column is dropped in `updateDestructive`).
- `Sw6OidcUserProviderDefinition`: add `WriteProtected(SYSTEM_SCOPE)` to every field and a `user_type` `Choice`. Add the missing `UpdatedAtField` (R3-L43).
- New `Subscriber/TrustEntityWriteGuardSubscriber`: on `PreWriteValidationEvent`, refuse **insert, update and delete** of `sw6oidc_passkey_credential` and `sw6oidc_user_provider` outside system scope. The only exceptions are a `nickname` update and an owner delete of their own passkey through the plugin endpoints, which already run in system scope.
- Check that every in-plugin writer runs in system scope: `PasskeyCredentialRepository`, `UserProviderBindingService::bind/backfillSubject/unbind`, and the admin unlink.
- **Tests:** a unit test of the guard, plus an integration test that `PATCH /api/sw6oidc-passkey-credential/{id} {"userId": …}` as a non-system admin source → 400.

### R3-H4 — provider editor privilege is equivalent to superadmin
Default decision (see [Q1](#open-questions)): **trust-relevant provider changes need a superadmin** (`AdminApiSource::isAdmin()`), or the CLI or system scope.
- **Trust fields:** `well_known_config_url`, `authorize_endpoint`, `access_token_endpoint`, `user_info_endpoint`, `jwks_endpoint`, `revocation_endpoint`, `end_session_endpoint`, `issuer`, `client_id`, `scope`, `public_client`, `login_type`, `allow_superadmin_group_mapping`, `auto_create_admin`, `default_acl_role_id`, `link_existing_accounts`, `require_email_verified`, `base64_claims`, `group_attribute`.
- **Child rows:** `sw6oidc_role_mapping` rows with `mapping_type = superadmin` or `admin_role`, and admin-relevant `sw6oidc_attribute_mapping` / `sw6oidc_access_control_rule` rows of admin-serving providers.
- Enforce it in `Sw6OidcProviderWriteGuardSubscriber`, plus a small guard for the child entities. The violation code is `SW6OIDC_SUPERADMIN_REQUIRED`.
- **Audit trail:** log every trust-field change at `warning` level with the admin id and the changed field names (values only for non-secret fields).
- **Admin UI:**
  - The provider detail page disables trust fields for non-superadmins, with a hint.
  - In `acl/index.js`, the editor role's description says "cannot change security-relevant settings".

### R3-M4 — secret re-entry rule can be bypassed
- In `validateCredentialUrlChanges()`, use the **stored** `public_client`. Changing `public_client` from `true` to `false` requires the secret in the same save.
- **Clone/insert bypass:** on an `InsertCommand` that carries a `client_secret`, refuse the write when the envelope byte-equals a stored envelope of another provider. A clone copies the envelope verbatim; a freshly entered secret always has a new random nonce. The violation code is `SW6OIDC_SECRET_REQUIRED_FOR_ENDPOINT_CHANGE`.
- **Lazy decryption** (roadmap 3, also closes R3-L42):
  - The entity keeps the envelope.
  - A new `Service/Security/ProviderSecretProvider::clientSecret(Sw6OidcProviderEntity): ?string` decrypts at the point of use.
  - `getUsableClientSecret()` delegates to it and logs an undecryptable secret **once per request**.
  - The field serializer's `decode()` stops decrypting.
  - The entity's `jsonSerialize()` never contains the secret.
- **Tests:** both bypass scenarios from the review, as integration tests.

### R3-M23 — public client can't be created without a secret
- Remove `Required` from `client_secret`. The write guard enforces "secret required unless `public_client`" on insert, and on an update that sets `public_client = false`.
- `OidcConfigTransfer`: the public-client import round trip works. Add a test.
- The admin form stops forcing a dummy secret.

### R3-L43 — missing DAL constraints (rest)
- **`Choice`:** `pkce_flow` (`plain`, `S256`; normalise `s256` on write), `login_type`, `mapping_type`, `last_test_status` (already done).
- **`Range`:** `http_timeout` 1–60, `jwks_cache_ttl` 60–86400.
- **`Required`:** on every NOT NULL boolean.
- `claim_encoding`: remove from the definition, drop the column in `updateDestructive` (Unused-code row), and remove `provider.claimEncoding = 'none'` from `provider-detail/index.js:388`.
- **Provider delete:** add `OneToMany` associations to `sw6oidc_user_provider` with `RestrictDelete` **unless** the request confirms. Default decision: the Administration shows a confirm dialog listing the number of bound accounts and sends a `confirmBindingLoss` header; the API refuses without it (see [Q7](#open-questions)).

---

## P3 — Fresh-authentication proof and passkey hardening

**Findings:** R3-H6, R3-M5, R3-M6, R3-M7, R3-M8, R3-F3, R3-L13–R3-L19, R3-L41

### Per-session "authenticated at" (base for R3-H6 and R3-M5)
- **New `Service/Session/SessionAuthenticationClock`:**
  - Customers: stores `sw6oidcAuthenticatedAt` in the context payload via `SalesChannelContextPersister::save()` (no new table).
  - Admins: not needed, because they already use `user-verified` tokens.
- **Writers:** a `CustomerLoginEvent` subscriber covers password, OIDC and passkey logins, which all dispatch it. `/sw6oidc/reauth` sets it after a verified round trip.
- **Reader:** `isFresh(SalesChannelContext, int $windowSeconds = 600)`.

### R3-H6 — storefront "Connect SSO" needs no fresh login
- `SendAuthorizationRequestController::link()`: when `isFresh()` is false, redirect to `/sw6oidc/reauth?redirectTo=frontend.account.profile.page`.
- New `Event/AccountSsoLinkedEvent` (Flow Builder trigger `sw6oidc.account.sso_linked`, mail-aware), dispatched after a successful `linkExplicitly()` for customers and admins.
- Ship a default mail template and flow, **inactive by default** (see [Q5](#open-questions)).

### R3-M5 — passkey registration window is per account, not per session
- `PasskeyController` registration endpoints use `isFresh()` instead of `customer.lastLogin`.
- `/sw6oidc/reauth` callback: require `auth_time` ≥ round-trip start − 60 s, the same check as `StepUpService::completeOidc()`. Extract the shared check into `Service/Oidc/AuthTimeValidator`.

### R3-M6 — deleting a passkey does not end its sessions
- New column `sw6oidc_session_activity.passkey_credential_id` (migration). The recorder stores it on passkey logins.
- Admin passkey logins: register them in `sw6oidc_session` (user type admin, session key = first jti, no IdP tokens).
- **On delete** (admin endpoint, storefront endpoint, admin grid; the grid goes through the P2 guard, which routes it to the service):
  - Customers: destroy exactly the sessions whose activity rows carry that credential id.
  - Admins: `destroyAllForUser()`, because admin tokens can't be targeted (documented).
- Removes the need for `AdminPasskeyLoginTokenTracker` (delete it; this also closes its documented limitation).

### R3-M7 — login ceremonies are not bound to purpose or surface
- Store a `purpose` on every ceremony: `storefront-login:{salesChannelId}`, `admin-login`, `admin-stepup:{userId}`, `storefront-register:{customerId}`, `admin-register:{userId}`.
- `verifyAssertion()` and `verifyAndPersist()` take the expected purpose and refuse a mismatch.

### R3-M8 — one RP ID for the admin and every sales channel
- `config.xml`:
  - `passkeyRpId` becomes sales-channel-scoped; the system config already supports that, so read it with `$salesChannelId`.
  - New global `passkeyRpIdAdmin`.
- **Validation on save** (a `SystemConfigChangedEvent` / `PreWriteValidation` on `system_config`): the value must be a registrable suffix of every domain host it applies to.
- Migration: copy the existing value to `passkeyRpIdAdmin` when it is a suffix of the `APP_URL` host.

### R3-F3 — deleting one storefront passkey can delete a different one
- Render one `<dialog>` per row (`id="sw6oidc-passkey-delete-dialog-{{ credential.id }}"`), or remember the opener in the plugin and submit only that form. Default: one dialog per row.
- E2E assertion in spec `04`: with three keys, deleting the middle one leaves the other two.

### Lows
| ID | Change |
|---|---|
| R3-L13 | `registration-options`: a `consume()` budget per customer. Cap at **20** credentials per account (configurable constant). |
| R3-L14 | All four storefront ceremony POSTs: require `Sec-Fetch-Site: same-origin`, or an `Origin` header equal to the current domain; refuse otherwise (403). |
| R3-L15 | `updateAfterAssertion()`: a single `UPDATE … SET sign_count = :new, public_key = :json WHERE id = :id AND (sign_count < :new OR :new = 0)`. Pass the entity through instead of reloading it 3 times. |
| R3-L16 | The admin passkey endpoints answer 403 for non-user sources (integrations). Check `Uuid::isValid()` on path ids in all passkey controllers (→ 404). |
| R3-L17 | `webauthn-codec.js` (both): send `response.getTransports()` and `getClientExtensionResults()`. Store the transports and return them in `allowCredentials`. |
| R3-L18 | Storefront passkey page: a "disabled" badge for clone-detected keys. Split the big block into sub-blocks (`page_account_passkey_list_item`, …). |
| R3-L19 | `PasskeyRelyingPartyResolver`: use `$context->getSalesChannel()->getDomains()` instead of the DBAL query. Passkey grid: `criteria.addIncludes` without `publicKey`. |
| R3-L41 | `StepUpController::startOidc`/`passkeyOptions`, `OidcAdminAuthController::startLink`: `consume(SCOPE_FLOW_START . ':' . $userId, $ip)`. |

---

## P4 — One SSO-only invariant

**Findings:** R3-H7, R3-M20, R3-M21, R3-M22, R3-L37

### R3-H7 — re-activating or re-scoping a provider skips the lockout checks
- **New `Service/Security/SsoOnlyInvariant`:**
  - `effectivePolicy(string $loginType): bool` — the same logic as `PasswordLoginPolicy`.
  - `adminAccessPossible(?SimulatedChange $change): bool` — true when at least one **active** admin is bound to an **active, admin-serving** provider. `$change` simulates the pending write (provider row, user row or binding row).
- **Callers, one check everywhere:**
  - `Sw6OidcProviderWriteGuardSubscriber`: insert, update and delete. This replaces `validateLockout`'s "flag key present" shortcut and `validateRemovalKeepsAdminAccess`. Any write whose **result** has the policy on must keep admin access.
  - `AdminLockoutGuardSubscriber`: user update (active / admin) and delete.
  - The P2 trust guard: binding delete.
  - `OidcUserProviderAdminController::unlink`.
- The bound-account check counts **active** users only.
- **Revocation:** compute the effective policy before the write (PreWrite) and after it (written event, after commit). When it flips off → on, run `PasswordSessionRevoker`, whichever field caused it.
- **Admin unlink** requires `UserVerifiedScope::isPresent()`, the same as core's user changes.
- **Tests:** the three scenarios in the review, plus "only bound admin inactive + confirm" → refused.

### R3-M20 — user-access-key block is bypassable
- **New `Service/AdminAuth/PolicyAwareClientRepository`** decorates `Shopware\Core\Framework\Api\OAuth\ClientRepository` (it is not final and implements `ClientRepositoryInterface`):
  - `validateClient()` and `getClientEntity()` refuse `AccessKeyHelper::getOrigin($id) === 'user'` while the admin policy is on and `SW6OIDC_ALLOW_USER_ACCESS_KEYS` is off.
- `AdminPasswordLoginGuardSubscriber` keeps only the friendly 403 message. Normalise `client_id` like League does (`trim`, fall back from `''` to the Basic-auth user).
- **Tests:** `" SWUA…"` and `""` plus Basic auth → refused.

### R3-M21 — revoker logs out guests and runs one unbounded UPDATE
- `PasswordSessionRevoker`: select the affected context tokens with a join on `customer` (`guest = 0`, no binding), then delete in batches of 500.
- Run the revocation as a Messenger message (`PasswordSessionRevocationMessage`, async transport) dispatched from the written event, so the admin save doesn't block.

### R3-M22 — lockout confirmation is weak
- `LockoutConfirmationStore` moves to `AtomicCacheInterface` (DB-backed), keyed by `providerId + userId`.
- `confirm-lockout` requires a `user-verified` token (step-up works after P1).

### R3-L37 — force logout can repeatedly hit a superadmin
- `SessionActivityController::forceLogout`: refuse when the target is an admin user and the caller isn't (`AdminApiSource::isAdmin()`).

---

## P5 — Reliable IdP-initiated logout, bounded state

**Findings:** R3-H5, R3-M1, R3-M17, R3-M18, R3-M19, R3-L8, R3-L9, R3-L30–R3-L36, R3-L39, R3-L40

### R3-H5 — registry rows expire before the sessions they track
Default decision (see [Q3](#open-questions)): **derive liveness from core's own state** instead of a fixed TTL.
- **Admins:** a registry row is alive while the user has any unexpired `refresh_token` row, or (for a session without refresh) while the row is younger than the access-token TTL.
- **Customers:** alive while `sales_channel_api_context` has the token with `updated_at` within `shopware.api.store.context_lifetime`. Store the context-token hash so you can join on it.
- `prune()` deletes only rows that fail these conditions. `fetch()` drops the `expires_at` filter, and `expires_at` becomes a hard upper bound only: 90 days.
- **Test:** an integration test with a refreshed admin token on day 8 (simulated by moving `created_at`/`expires_at` back) → back-channel logout ends the session.

### R3-M1 — anonymous logout tokens force unlimited JWKS fetches
- Logout scope: key the refetch cooldown on the **endpoint only**, at most 1 refetch per 60 s per endpoint.
- Reject a `kid` longer than 256 bytes before any lookup.
- `BackChannelLogoutController`: call `isBlocked()` for the resolved provider **before** `verifyLogoutToken()`.
- Don't let a failed logout-scope refetch trip the shared login breaker: use separate breaker keys per scope.

### R3-M17 — one-time-token table grows without bound
- `DatabaseAtomicCache::prune()` loops in batches of 5 000 until 0 rows or 30 s have passed.
- New scheduled task `sw6oidc.one_time_token_cleanup`, hourly.
- `login.html.twig`: add `rel="nofollow"` to the SSO links. Also add `<meta name="robots" content="noindex">` handling for `/sw6oidc/login` responses via an `X-Robots-Tag: noindex, nofollow` header.

### R3-M18 — Redis error replies treated as "already seen"
- `RedisAtomicCache`: check the return value of `setex()`. For `set(… nx)`:
  - `true` → added;
  - `false` with `getLastError() === null` → it exists;
  - anything else → treat it as an error: log it, `clearLastError()`, fall back to the DB store.
- The same handling for the Lua `getAndDelete`.
- Document "this Redis must use `noeviction`" in the README. `InfrastructureInspector` warns when `maxmemory-policy` isn't `noeviction`.

### R3-M19 — a mid-logout failure leaves the session alive and untargetable
- `Sw6OidcIdpLogoutHandler`: `destroy()` first, then `remove()`.
- `BackChannelLogoutController`: on any exception after the `jti` marker is set, delete the marker (new `AtomicCacheInterface::delete()`) and answer 500, so the IdP retry works.

### Lows
| ID | Change |
|---|---|
| R3-L8 | Read the global `backchannel_logout` budget in `isBlocked()` for unresolvable tokens (it is written today but never read). |
| R3-L9 | Front-channel: the malformed-request budget blocks only malformed requests. Well-formed `iss` + `sid` requests are never blocked. |
| R3-L30 | `Sw6OidcSessionDestructionService`: when `delete()` hits 0 rows, fall back to `revokeAllCustomerTokens()` for that customer. |
| R3-L31 | `Sw6OidcSessionActivityRecorder`: find rows with one DBAL query (`registry_session_id = :id OR session_key_hash = :hash`, using both indexes) and close them with one `UPDATE`. |
| R3-L32 | `NodeHeartbeat`: record from web requests only (a `kernel.terminate` subscriber, throttled to once per 60 s via APCu or a static), not from the scheduled-task handler. |
| R3-L33 | Revocation in `RpInitiatedLogoutService`: a fixed 3 s timeout, and dispatch it as an async message when Messenger has a transport. |
| R3-L34 | Migration: delete orphaned `sw6oidc_session` rows, then add an FK to `sw6oidc_provider` with `ON DELETE CASCADE`. |
| R3-L35 | `RedisAtomicCache`: encrypt values with `Sw6OidcEncryptor` (same purpose as the DB store). |
| R3-L36 | `HealthCheckController`: without `SW6OIDC_HEALTH_TOKEN`, return `{status}` only. |
| R3-L39 | `RedisConnectionFactory`: without APCu, fall back to a static per-process marker plus a temp-file marker (`sys_get_temp_dir()`, mtime-based). |
| R3-L40 | Store `sha256(sid)` in `sw6oidc_session_activity.sid`; a migration hashes existing values. Nothing reads it in clear text. |

---

## P6 — Identity and provisioning

**Findings:** R3-M9–R3-M16, R3-L20–R3-L29

### R3-M9 — binding ignores the issuer and compares `sub` loosely
- Migration: `sw6oidc_user_provider.sub` and `.issuer` become `VARCHAR … COLLATE utf8mb4_bin`. The unique key becomes `(provider_id, user_type, issuer_hash, sub)`. `issuer` is up to 2048 chars, so add a `issuer_hash CHAR(64)` column.
- Every lookup in `UserProviderBindingService` filters on `issuer` too. Legacy rows with `issuer = NULL` are backfilled from the provider's current issuer during the migration.
- **Issuer-change guard:** when a provider's `issuer` changes (write guard), set `bindings_suspended_at` on the provider. Logins of bound accounts then fail with `SW6OIDC_BINDINGS_SUSPENDED` until a superadmin confirms (re-bind all to the new issuer, or clear the bindings). There's a new "confirm issuer change" action in the provider detail page. See [Q4](#open-questions).

### R3-M10 — legacy-binding upgrade skips the superadmin guard
- `IdentityResolver`: for a legacy binding of a **privileged** account (any admin, see R3-L23), don't auto-upgrade; throw `AccountLinkingRequiredException` and require `linkExplicitly()`.
- Log every auto-upgrade at `warning`.

### R3-M11 — role and group sync is single-valued and overwrites manual roles
Default decision (see [Q2](#open-questions)):
- `GroupMappingResolver::resolveAll()` returns **every** matching ACL role.
- New table `sw6oidc_managed_acl_role (user_id, acl_role_id, provider_id)`. Sync adds missing mapped roles and removes only roles in this table that no longer match. Manually granted roles are never touched.
- Defaults (`default_acl_role_id`, `default_customer_group_id`) apply on **create only**, never during sync.
- **Superadmin revoke** (`revoke_superadmin_on_sso`) also runs when nothing resolves.
- The "last superadmin" check and the update run in one transaction, with `SELECT … FOR UPDATE` on the active superadmin rows.
- Customer group sync: change the group only when a mapping matches. With several matches, the lowest `sort_order` wins (customers have one group).
- Migration: existing admin role assignments made by the plugin can't be told apart from manual ones. Seed the table with each SSO-bound user's current roles that match a mapping row.

### R3-M12 — first-login race leaves duplicate customers
- `CustomerProvisioningService` / `AdminProvisioningService`: create + bind in `Connection::transactional()`. Core's `EntityRepository::create()` participates in the same DBAL connection transaction.
- On `UniqueConstraintViolationException` (either the binding or the `uniq.user.email` index): roll back, then re-run `IdentityResolver::resolve()` once and use the winner.
- The admin username retry only catches the username index violation.

### R3-M13 — JIT customers ignore Shopware's sales-channel binding
- On create, set `boundSalesChannelId` with core's rule: when `core.systemWideLoginRegistration.isCustomerBoundToSalesChannel` is on, **or** when a bound account with this email already exists (mirror `RegisterRoute.php:531-560`).

### R3-M14 — with channel binding on, SSO only works in the first channel
**Decided (Q6): one SSO binding per sales channel.** One IdP subject can own one customer account per sales channel when Shopware binds customers to sales channels.
- **Schema** (migration, together with the R3-M9 key change): add `binding_scope BINARY(16) NOT NULL` to `sw6oidc_user_provider`. Its value is the bound sales channel id for channel-bound customers, else 16 zero bytes, meaning "global": every admin, and every customer that isn't channel-bound. It's a non-null sentinel because MySQL unique keys don't treat `NULL`s as equal. Backfill it from `customer.bound_sales_channel_id`.
- **Unique keys:**
  - `(provider_id, user_type, issuer_hash, sub, binding_scope)`, so the same subject can be bound once per scope;
  - `(user_type, user_id)` stays, so each account still has at most one binding.
- **Lookup** (`UserProviderBindingService::findUserIdBySubject(provider, identity, ?salesChannelId)`):
  1. the binding with `binding_scope = <current channel>`;
  2. else the global binding, if that customer is allowed in the current channel (`CustomerSalesChannelBinding::allows()`).
- **Provisioning:** when nothing resolves in channel B and the global or channel-A account isn't allowed there, JIT creation, if enabled, creates a customer bound to B (the R3-M13 rule) and binds it with scope B. The email-match and link rules of `IdentityResolver` apply per scope.
- **Explicit linking:** `/sw6oidc/link` binds with the scope of the logged-in customer's bound channel.
- **Denials:** `CustomerProvisioningDeniedException` (auto-create off) stays a policy denial, shown as a flash message and not counted by the rate limiter (R3-L24).
- **Cleanup:** `UserProviderCleanupSubscriber` and the admin binding info show the scope (channel name) for customers.
- **Tests:**
  - channel A and channel B logins of the same subject give two accounts, two bindings, both working;
  - an unbound customer plus a channel-bound login reuses the global binding;
  - two first logins at once in the same channel give exactly one account (with R3-M12).

### R3-M15 — rewriting email transforms lock out every user
- `OidcCallbackResult::emailVerified()` checks `email_verified` against the **raw** `email` claim, never against the mapped profile email.
- When the mapped email differs from the raw claim beyond case or whitespace and `require_email_verified` is on, refuse **at save time**: an `AttributeMappingWriteGuardSubscriber` violation `SW6OIDC_EMAIL_TRANSFORM_UNVERIFIABLE`. A transform on email is then only allowed with `require_email_verified` off.

### R3-M16 — placeholder addresses flagged but never read
- New `Storefront/EventSubscriber/PlaceholderAddressSubscriber` on `CheckoutConfirmPageLoadedEvent`: when the billing or shipping address has `customFields.sw6oidc_placeholder_address`, redirect to `frontend.account.address.edit.page` with a flash message.
- On address write: clear the flag on any customer or admin edit (`customer_address.written` with `street`/`city` changed) and when address sync writes real values.

### Lows
| ID | Change |
|---|---|
| R3-L20 | `AttributeMapper`: `EmailIdnConverter::encode()` before validating and before every lookup. |
| R3-L21 | Truncate with `mb_substr` to the DAL limits (names 255, title 100, company 255, street 255, zipcode 50, city 255, phone 40, …) in both provisioning services. A constant map lives in `MappedProfile`. |
| R3-L22 | `AvatarFetcher`: `max_duration: 10`, sniff the bytes with `getimagesizefromstring()`, and reject anything that isn't PNG/JPEG/GIF/WebP. |
| R3-L23 | `AdminProvisioningService`: `$emailMatchIsPrivileged = true` for every admin. |
| R3-L24 | Provisioning denials become policy denials (not counted). Drop the email from exception messages and log `userId`/`providerId` only. |
| R3-L25 | Username dedup: one `LIKE 'base%'` query, then pick the first free suffix. After 3 collisions use a random 4-hex suffix. |
| R3-L26 | Email lookup: filter `active = 1`, sort `createdAt DESC` (as core does). |
| R3-L27 | `OidcCallbackController`: `register()` and `recordLogin()` are wrapped. Their failures are logged and never turn a completed login into "login failed". |
| R3-L28 | `OidcAdminAuthController::callback`: refuse inactive admins right after resolution, before sync, `rememberForAdmin`, the registry or the nonce. |
| R3-L29 | Extract `Service/Provisioning/AccountResolution` (find by email, reload, fallback names, partial payload builder). `loginByCustomerId()` takes the entity. Sync skips the `update()` when the diff is empty. |

---

## P7 — OIDC protocol, HTTP, JWT

**Findings:** R3-M2, R3-M3, R3-L1–L4, L6, L7, L12, L44, L45, R4-L1–R4-L4

### R3-M2 — `max_redirects: 0` has no effect
- `Sw6OidcHttpClientFactory`: apply `withOptions(['max_redirects' => 0])` **after** wrapping with `NoPrivateNetworkHttpClient`.
- **Test:** a `MockHttpClient` that returns 302 → no second request. The avatar per-request override still works.

### R3-M3 — Keycloak and Auth0 detected as Authelia
- New provider column `logout_style` (`standard` | `authelia_forward_auth`, default `standard`) via migration, plus a select in the admin form.
- Remove the URL heuristic. Always send `client_id`. Send `id_token_hint` when available.
- **Migration:** existing providers keep today's behaviour unless it is provably wrong (Q8: Authelia is in use):
  - `authelia_forward_auth` for every provider whose end-session URL matches the old heuristic (path ends in `/logout`, contains neither `/oauth2/` nor `/oidc/`) **and** isn't a known other IdP: Keycloak `/protocol/openid-connect/logout`, Auth0 `/v2/logout` or `/oidc/logout`, Okta, Azure `/oauth2/v2.0/logout`.
  - `standard` for everything else.
  - The migration logs each provider's assigned style, and the admin form shows it, so a wrong guess is a one-click fix.
- **Sibling module:** `MartinKuhl/magento2-oidc` (`Model/Service/RpInitiatedLogoutService.php:57-64`) has the same heuristic. Its only difference is that it ignores a trailing `/`. It has the same Keycloak bug: its test fixture for Keycloak uses `/realms/x/protocol/oidc/logout`, but the real Keycloak path is `/protocol/openid-connect/logout`, which it classifies as Authelia. Port the explicit `logout_style` setting there afterwards, so both modules behave the same. The Shopware migration mirrors the **Shopware** heuristic, which has no trailing-slash trim, so existing behaviour is kept exactly.
- **Authelia check before implementing:** confirm whether the Authelia instance in use exposes a standard `end_session_endpoint` in its discovery document. Recent Authelia versions are adding OIDC RP-initiated logout. If it does, `standard` is the better long-term setting for it, and `authelia_forward_auth` stays only for older versions or the portal `/logout?rd=` URL.

### R4 JWT findings
| ID | Change |
|---|---|
| R4-L1 | Reject any token with a `crit` protected header (no extensions are supported). |
| R4-L2 | A present `nbf`/`iat` must be numeric, otherwise `InvalidJwtException`. |
| R4-L3 | Add `ES256`, `ES384`, `PS256` (and the `EC` key type in `candidateKeys()`). The allowed list comes from discovery's `id_token_signing_alg_values_supported` ∩ supported. The connection test fails clearly when the intersection is empty. Composer: `web-token/jwt-library` (P9) already contains these algorithms. |
| R4-L4 | Both callbacks: truncate `error` to 64 and `error_description` to 200 chars and log at `notice`. When the `state` is unknown, record a failure on the callback budget. |

### Lows
| ID | Change |
|---|---|
| R3-L1 | `ProviderResolver::getActiveById()`: `Uuid::isValid()` first, else `ProviderNotFoundException`. |
| R3-L2 | `RelayStateValidator`: add the `D` modifier: `'#^/(?![/\\\\])[^\x00-\x20\x7f\\\\]*$#D'`. Add a test with `%0A`. |
| R3-L3 | `BrowserBinding`: re-issue the cookie (same value, new 30-min expiry) on every flow start. |
| R3-L4 | Move the "`sub` required" check into `ClaimsMerger::merge()`, shared by the live test. |
| R3-L6 | `ClaimsNormalizer::flattenRecursive`: decode object **keys** for claims in `base64_claims`, the same as `decodeGroups()`. Add a parity test with access rules. |
| R3-L7 | `JwtVerifier::verify()`: `string $expectedNonce`, and delete the skip branch. |
| R3-L12 | `LoginType::Test` enum case. The live test carries its locale in a typed `AuthorizationFlowContext::locale` field, not `relayState`. |
| R3-L44 | Test endpoints: clamp `httpTimeout` to 1–30 and cast every input to `string|null` (400 on a wrong type). `OidcConnectionTestService` gets `bool $hasSecret` instead of the decrypted secret. |
| R3-L45 | `SensitiveDataProcessor`: also mask a leading `code=`, JSON bodies (`"access_token":"…"`), `Bearer`/`Basic` values, URL-encoded URLs, `;`-separated parameters, and keys like `contextToken`, `sessionKey`, `jwt`, `cookie`, `apiKey`. Mask non-string values and exception messages. One table-driven test with every pattern. |

---

## P8 — Frontend

**Findings:** R3-F2, R3-F6–R3-F8, R3-F10–R3-F17, 3 hygiene rows

| ID | Change |
|---|---|
| R3-F2 | `acl/index.js`: wrap the registration in the same "when ready" helper as `defer-module-register.js` (wait for `Shopware.Service('privileges')`). Add the `sw-privileges.permissions.sw6oidc_*.label` snippets (de-DE, en-GB). Move `force_logout` to `additional_permissions`. The P0 smoke spec's viewer pass proves it. |
| R3-F6 | `sw-inactivity-login`: use the `sw-login.sw6oidc.login.*` keys. |
| R3-F7 | `sw6oidc-profile-passkey`: `mt-label` → `mt-badge`. |
| R3-F8 | `sw-profile-index-general`: extend `sw_profile_index_general_information` with `{% parent %}` plus a sibling card. |
| R3-F10 | `sw-login`: override only `sw_login_login_user_field`, `_password_field` and `_submit`, so "Keep me logged in" stays. |
| R3-F11 | Listing pages: drop the extra `created()` fetch. In the passkey list, write `ownerNames` after the request-id check. |
| R3-F12 | Provider detail: a request counter for `loadEntity()` / `loadFormContext()`. |
| R3-F13 | Sessions list: "Only active" also filters expired rows. "Force logout" is disabled on them. |
| R3-F14 | Provider detail: pass `:disabled="!canEdit"` to every select, tag field and context menu. |
| R3-F15 | Use the Meteor badge variants (`critical`, `positive`, …). |
| R3-F16 | `snippetOr()` for every dynamic key (`atomicStore.*`, `infrastructureWarning.*`, `testMessage.*`). |
| R3-F17 | `role="alert"`/`aria-live="polite"` on the error containers. `aria-labelledby` on the storefront dialog. |
| Hygiene 1 | **Decided (Q9): migrate everything now.** Every deprecated `sw-*` component in the plugin's templates moves to its Meteor `mt-*` equivalent: `sw-text-field`, `sw-switch-field`, `sw-number-field`, `sw-card`, `sw-button`, `sw-button-process`, `sw-password-field`, `sw-label` and the rest, about 30 templates. Every `$tc` (239 uses) becomes `$t`. Check the event and prop renames per component (`v-model` → `model-value`, `@update:value`, `variant` names) against `/var/www/html/vendor/shopware/administration` 6.7. Do it as its own commit at the **start** of P8, so the functional P8 fixes land on the migrated templates. Add a CI grep step that fails on any new `<sw-(text\|switch\|number\|password)-field\|<sw-card\|<sw-button` or `$tc(` in `src/Resources/app/administration`. |
| Hygiene 2 | `sw6oidc-rp-id-field`: a placeholder per scope (admin host vs. sales channel). Remove both "VERIFICATION NEEDED" comments. |
| Hygiene 3 | `sw6oidc-user-provider-info`: declare `emits: ['unlinked']` and listen to it in the user detail to refresh the binding card. |

Rebuild the admin and storefront bundles. The smoke spec covers every page.

---

## P9 — Shopware conventions, DAL, DI, dependencies

**Findings:** R3-M24, R3-L5, R3-L10, R3-L38, R3-L46–R3-L50, R4-L5, R4-L6

| ID | Change |
|---|---|
| R3-M24 | `AttributeMappingWriteGuardSubscriber`: for an `UpdateCommand`, load the stored row and validate the **merged** `transform_function` + `transform_params`. |
| R3-L5 | Make `BrowserBinding` (in `OidcSecurityHelper`) and the dependencies of `OidcLiveLoginTestService` required. The tests use doubles. |
| R3-L10 | New `Sw6OidcException extends HttpException`, with static factories per case and `SW6OIDC_*` error codes. Migrate all 17 exception classes: either keep them as subclasses of `Sw6OidcException` (so existing `catch` blocks stay intact) or replace them with factories. Default: subclasses, which is the smaller diff. |
| R3-L38 | The three early migrations get the `SHOW COLUMNS` guard. |
| R3-L46 | Twig extensions → `twig.runtime` (functions declared in the extension, logic in a lazy runtime). Write guard: `lazy="true"`. `pendingRevocations` is cleared on `WriteFailedEvent` / `kernel.reset`. Replace the fake `OidcLogger` FQCN service id with `sw6oidc.logger`. Encryption purposes become constants on `Sw6OidcEncryptor`. |
| R3-L47 | `StorefrontLoginOptionsExtension`: memoise per request (keyed by sales channel + login type), reset on `kernel.reset`. |
| R3-L48 | `TestResultTranslator`: ship `Resources/translations/messages.{de-DE,en-GB}.json` (or `.xlf`) and use the Symfony translator. Don't read the admin snippet files. |
| R3-L49 | Track the `@internal` / `BecomesInternal` dependencies: one `Service/AdminAuth/CoreOAuthBridge` class as the **only** place that touches them, plus a test that fails if a class is missing (an early warning for 6.8). Pass `Context` down from controllers and subscribers wherever a request context exists, and keep `createDefaultContext()` only in truly anonymous pre-auth paths (comment each). |
| R3-L50 | Share `accessTokenJti()` (in `AdminTokenIssuer`) and `ERROR_CODE`. Use `LoginType::Admin->value` instead of the `'admin'` literals. |
| R4-L5 | `OidcCustomerLoginRoute` → call `AccountService::loginById()`, keep `CustomerSalesChannelBinding::allows()` as a pre-check, delete the class and the `AbstractLoginRoute` inheritance (Unused-code row). |
| R4-L6 | `composer.json`: drop `league/oauth2-server`, `symfony/psr-http-message-bridge` and `nyholm/psr7` (provided by core), and replace `web-token/jwt-framework` with `web-token/jwt-library: ^4.0`. Safe because the plugin is distributed via Composer only (Q10). |

---

## P10 — Unused code, comments, docs

**Findings:** every row of the Unused-code table, R3-L11, R3-L52

1. **Delete:**
   - `SsrfUrlValidator::isInsecureModeEnabled()`
   - `PasskeyCredentialRepository::findOneByCredentialId()`
   - `UserProviderBindingService::assertNotBoundToDifferentProvider()`
   - `Sw6OidcSessionRegistry::resolveByUser()`
   - `AdminLoginNonce::$browserBinding`
   - `Sw6OidcSession::$createdAt` and `$salesChannelId`
   - the registry's `$customerTtlSeconds` parameter
   - the constructor defaults `'PT10M'` and `'P1W'`
   - the duplicate admin snippets `sw6oidc.login.*`
   - the storefront snippet `sw6oidc.passkey.reauthRequired`

   Adjust the tests that used them.
2. **Already handled elsewhere:** `claim_encoding` (P2), `sign_count` (P2), `OidcCustomerLoginRoute` (P9), the `AdminTokenIssuer` request mutation (P1), `PLACEHOLDER_ADDRESS_FIELD` (now read, P6), `UserProvider.issuer` (now compared, P6), the activity indexes (now used, P5).
3. **Make private:** `OidcSecurityHelper::deriveCodeChallenge`, `OidcCallbackResult::subject`, `RpInitiatedLogoutService::revokeToken`, `SsrfUrlValidator::isPublicIp`, `Sw6OidcRateLimiter::clientKey`, `destroyCustomerSession`, `destroyAdminSessions`. Tests go through the public API.
4. **Keep** the public event getters `PasskeyRegisteredEvent::getUserType()` / `getUserId()` (extension API).
5. **R3-L11:** merge the double docblocks (`OidcCallbackProcessor`, `ClaimsNormalizer`, `RpInitiatedLogoutService`).
6. **Stale comments:** `GenderMapper`, `attributeTypeColumnWidth`, `sw-login`, `sw-profile-index-general`, the `DatabaseAtomicCache` docblock.
7. **R3-L52:** bring `CLAUDE.md` and `TECHNICAL_DOCUMENTATION.md` up to date with everything P1–P9 changed. Add a `CHANGELOG.md` entry for `0.2.0`.

---

## P11 — Static analysis

**Findings:** the PHPStan level 8 errors (52), Psalm unused code (roadmap 13)

1. Add `phpstan/phpstan-shopware`, or the DAL generics stubs. Add `@extends EntityRepository<…Collection>` / `@param EntityRepository<…>` annotations, which fixes the 31 `missingType.generics`.
2. Fix the 21 others, as real fixes, not ignores: `RpInitiatedLogoutService:134` (handle a `false` from `parse_url()`), `OidcCallbackProcessor:118` and `OidcLiveLoginTestService:144` (validate `list<string>`), `PasskeyRelyingPartyResolver:66`, `Sw6Oidc.php:34`, `ConfigurableLevelHandler:36`, `OidcSecurityHelper:119`, `PasskeyConfig:32,39`, `AdminOidcGrant:160`, and the iterable value types.
3. Raise `phpstan.neon.dist` to **level 8**, and also analyse `tests/` at level 5.
4. Run Psalm `--find-unused-code` with a DI-aware baseline; new unused code fails CI.

---

## P12 — Roadmap (optional, after release)

These come from `Code-Review.md` "Future improvements". Items already done by P0–P11 are in brackets.

- [1] tests (P0)
- [2] trust data (P2)
- [3] lazy secrets (P2)
- [4] session state from core (P5)
- [5] invariant (P4)
- [6] `(iss, sub)` key (P6)
- [7] JIT through core rules (P6, partly)
- [8] declarative role sync (P6)
- **9** passkeys: conditional mediation (`autocomplete="username webauthn"`), `PasskeyCredentialDeletedEvent`, a clone-detection Flow trigger, one shared ceremony service
- **10** response-size caps on token, userinfo and JWKS; keep `error`/`error_description` for diagnostics; separate HTTP clients for the IdP and user content
- **11** enforce `S256` PKCE unless discovery lacks it; check `typ: logout+jwt`
- [12] Shopware 6.8 readiness: the `mt-*` migration (P8); the `@internal` dependencies are tracked in P9 (R3-L49)
- [13] static analysis (P11)
- **14** a `Configuration` tree for the `SW6OIDC_*` env vars, autowiring (also deferred L13)
- **15** a small pre-auth admin bundle (also closes the rev-2 partials **F-M2** and **F-M14**)
- [16] lean on core (P9)
- [17] JOSE (P7)

---

## Coverage matrix

Every finding of `Code-Review.md` revision 4, with the phase that closes it.

| Phase | Findings |
|---|---|
| P0 | R4-L7, R3-L51 |
| P1 | R3-H1, R3-F1, R3-F4, R3-F5, R3-F9, Unused: `AdminTokenIssuer` request mutation |
| P2 | R3-H2, R3-H3, R3-H4, R3-M4, R3-M23, R3-L42, R3-L43, Unused: `sign_count`, `claim_encoding` / `getClaimEncoding()` |
| P3 | R3-H6, R3-M5, R3-M6, R3-M7, R3-M8, R3-F3, R3-L13, L14, L15, L16, L17, L18, L19, L41 |
| P4 | R3-H7, R3-M20, R3-M21, R3-M22, R3-L37 |
| P5 | R3-H5, R3-M1, R3-M17, R3-M18, R3-M19, R3-L8, L9, L30, L31, L32, L33, L34, L35, L36, L39, L40, Unused: activity indexes |
| P6 | R3-M9, M10, M11, M12, M13, M14, M15, M16, R3-L20, L21, L22, L23, L24, L25, L26, L27, L28, L29, Unused: `PLACEHOLDER_ADDRESS_FIELD`, `UserProvider.issuer` |
| P7 | R3-M2, R3-M3, R3-L1, L2, L3, L4, L6, L7, L12, L44, L45, R4-L1, R4-L2, R4-L3, R4-L4 |
| P8 | R3-F2, F6, F7, F8, F10, F11, F12, F13, F14, F15, F16, F17, the 3 hygiene rows |
| P9 | R3-M24, R3-L5, L10, L38, L46, L47, L48, L49, L50, R4-L5, R4-L6, Unused: `OidcCustomerLoginRoute::login()` + inheritance |
| P10 | all remaining Unused-code rows, R3-L11, R3-L52 |
| P11 | PHPStan level 8 (52 errors), Psalm unused code |
| P12 | Roadmap 9–12, 14, 15; rev-2 partials F-M2, F-M14; deferred L13 |

The rev-2 items listed as Incomplete or Regressed in `Code-Review.md` (N-H2, N-H3, H3, H7, H8.3–H8.6, C2 residue, M12, M16, M21, N-M12, F-H1, F-H5, F-N1, F-N3, F-N7, F-N8, F-M10, Twig memoisation) are each covered by the R3 finding they map to.

---

## Decisions

Answered on 2026-10-05:

| # | Decision |
|---|---|
| Q6 | **One SSO binding per sales channel** (schema change, see R3-M14 in P6). |
| Q8 | **Authelia is in use.** The logout-style migration keeps today's behaviour for Authelia-shaped URLs and only changes recognisable Keycloak, Auth0, Okta and Azure URLs (see R3-M3 in P7). |
| Q9 | **Migrate all deprecated admin components and `$tc` now** (first commit of P8). |
| Q10 | **Composer only.** R4-L6 goes ahead as planned. |
| Q11 | **Breaking changes are fine.** The plugin is in development, with no production customers. No BC shims, deprecation layers or upgrade paths for external extensions. Data migrations for existing development installs are still written, because they cost little. |
| Q12 | **Continue on `fix/code-review-rev2`.** One commit per phase or more; finding IDs go in the commit messages. |

The questions left unanswered (Q1–Q5, Q7, Q13) keep the defaults below.

## Open questions

| # | Question | Default in this plan |
|---|---|---|
| **Q1** | **R3-H4:** who may change trust-relevant provider settings (endpoints, issuer, scope, superadmin mapping, …)? Superadmins only, or a new grantable privilege `sw6oidc_provider:security`? | Superadmins only, plus CLI. |
| **Q2** | **R3-M11:** role sync becomes multi-valued and touches only roles the plugin granted. Today's sync **replaces** all roles. Is that behaviour change wanted? Existing installs need a one-time seed of "plugin-managed" roles. | Yes, multi-valued and managed-only, with the seed. |
| **Q3** | **R3-H5:** derive registry liveness from core's `refresh_token` / `sales_channel_api_context` tables (more queries, always correct), or push `expires_at` forward from a decorated refresh grant (cheaper, needs a League grant decorator)? | Derive from core tables. |
| **Q4** | **R3-M9:** when a provider's issuer changes, suspend its bindings until a superadmin confirms, which blocks logins of bound users in the meantime. Or only warn? | Suspend and confirm. |
| **Q5** | **R3-H6:** ship the "SSO account linked" Flow Builder flow and mail template active by default? Customers then get a mail on every link. | Ship it inactive; the merchant enables it. |
| **Q6** | **R3-M14:** with sales-channel-bound customers, support one SSO binding per sales channel (schema change), or document "one provider per sales channel" and refuse cleanly? | **Decided:** one binding per channel. |
| **Q7** | **R3-L43:** deleting a provider deletes all its account bindings. Require an explicit confirmation (UI dialog plus API header), or forbid deletion while bindings exist? | Confirmation dialog plus header. |
| **Q8** | **R3-M3:** do you have Authelia installations in use? | **Decided:** yes, so the migration keeps the Authelia style for Authelia-shaped URLs. |
| **Q9** | **Hygiene:** migrate all deprecated `sw-*` admin components and `$tc` now, or only in files touched anyway? | **Decided:** everything now. |
| **Q10** | **R4-L6:** Composer only, or a Store/ZIP package with a bundled `vendor/`? | **Decided:** Composer only. |
| **Q11** | **Compatibility:** are BC breaks allowed? | **Decided:** yes, development phase, no production customers. |
| **Q12** | **Workflow:** which branch? | **Decided:** continue on `fix/code-review-rev2`. |
| **Q13** | **E2E in CI:** making `e2e` blocking needs the dockware + Dex setup to be stable in GitHub Actions. Has it been reliable lately, or should it stay non-blocking until P1 is done? | Blocking from P0; the P0 tests are tagged `known-broken` until P1. |

# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

This is a Shopware 6 plugin (`MartinKuhl\Sw6Oidc`, composer package `martinkuhl/shopware6-oidc-sso`, currently v0.1.0) that provides OpenID Connect (OIDC) and Passkey (WebAuthn/FIDO2) single sign-on for both Storefront customers and Administration users. It mirrors the architecture of the sibling `magento2-oidc-sso` module: multi-provider OIDC with JIT provisioning and group/role mapping, plus a second, independent passwordless login method (Passkey) that bridges into native authentication the same way OIDC does.

The plugin is early-stage (v0.1.0, MIT license, a unit-test suite covering the OIDC core, provisioning, WebAuthn and all security components, no integration tests against a live Shopware instance yet). See "Known gaps / implementation notes" below before assuming any given feature is fully wired end to end, and see `TODO.md` for the remaining roadmap.

## Development commands

### Composer scripts (`composer.json`)
```bash
composer test              # phpunit
composer test-coverage     # phpunit with HTML + Clover + text coverage
composer phpstan           # phpstan analyse -c phpstan.neon.dist (level 5)
composer psalm             # psalm (errorLevel 4)
composer rector            # rector process --dry-run
composer rector-fix        # rector process (applies fixes)
composer cs-check          # phpcs (PSR12-based ruleset)
composer cs-fix            # phpcbf
composer ci                # cs-check -> phpstan -> psalm -> rector -> test
```

### Plugin lifecycle
```bash
bin/console plugin:refresh
bin/console plugin:install --activate Sw6Oidc
bin/console plugin:update Sw6Oidc
bin/console cache:clear
```
Schema is **migration-driven**, not `install()`-driven — `Sw6Oidc.php` has no custom `install()`/`activate()`/`deactivate()`, only `uninstall()`. Table creation happens the first time migrations run (automatically during `plugin:install`, or explicitly via):
```bash
bin/console database:migrate Sw6Oidc --all
bin/console database:migrate-destructive Sw6Oidc --all   # drops removed columns (e.g. sync_on_sso)
```

### Provider config export/import (`Console/`, logic in `Service/Config/OidcConfigTransfer`)
```bash
bin/console sw6oidc:config:export [--provider-id=<id>] [-o file.json] [--keep-encrypted|--plaintext]
bin/console sw6oidc:config:import -i file.json|- [--dry-run] [--overwrite] [--skip-unresolved]
```
- Versioned JSON (`version: 1`): provider fields + attribute/role mappings; `last_test_*`/timestamps left out. ACL roles/customer groups are exported as `{id, name}` and resolved on import by id, else unique name (otherwise the provider fails, or with `--skip-unresolved` the reference/row is dropped).
- Client secret **omitted by default**; `--keep-encrypted` exports the stored envelope (only importable where `APP_SECRET` is identical — the field serializer rejects foreign envelopes), `--plaintext` the decrypted value. An import without a secret keeps the stored one; a *new* confidential provider without one fails.
- Matches providers by `id`; existing ones are skipped unless `--overwrite` (then child mappings are replaced, per-provider transaction). Writes go through the repository, so encryption and `Sw6OidcProviderWriteGuardSubscriber` apply. `--dry-run` wraps the whole import in a rolled-back transaction.

## Architecture — OIDC flow

Two independent SP-initiated entry points share all downstream machinery:
- Storefront: `GET /sw6oidc/login` (`SendAuthorizationRequestController`) → IdP → `GET /sw6oidc/callback` (`OidcCallbackController`)
- Admin: `GET /api/sw6oidc/admin/login` → IdP → `GET /api/sw6oidc/admin/callback` (`OidcAdminAuthController`)

1. **`AuthorizationRequestBuilder::build()`** calls `OidcSecurityHelper::beginAuthorizationRequest()`, which generates state, PKCE `code_verifier`, and nonce (all `random_bytes` base64url), bundles them with `providerId`/`loginType`/`relayState`/`codeChallengeMethod` into an `AuthorizationFlowContext`, and caches it under `sw6oidc_flow_{state}` (600s TTL). Code challenge is derived per provider config: `plain` (verifier itself) or `S256` (base64url(sha256(verifier))). PKCE is always used.

2. **`OidcCallbackProcessor::process()`** is the single pipeline shared by both the Storefront and Admin callbacks ("so the two flows can never drift"):
   - `OidcSecurityHelper::consumeAuthorizationFlow($state)` — atomic get-and-delete; missing/expired/already-consumed state throws `InvalidStateException` (single-use, combined CSRF + replay protection).
   - Resolves the provider by `flow->providerId` (must still be active).
   - `TokenExchangeService::exchangeCodeForTokens()` — POSTs the authorization code + PKCE verifier; `client_secret` is included **only if** the provider is not a public client (RFC 6749 §2.1).
   - If an `id_token` is present, `JwtVerifier::verify()` runs (see below); if absent, logs a warning and relies on the userinfo endpoint alone.
   - `UserInfoService::fetchClaims()` — GET with `Authorization: Bearer`; returns `[]` if no userinfo endpoint is configured.
   - Merges `id_token` claims with userinfo claims (userinfo wins on collision).
   - Extracts the **raw, unflattened** groups claim and normalizes it via `ClaimsNormalizer::normalizeGroups()` **before** flattening — this order matters, because flattening a Zitadel-style nested role object (`{"Engineering": {"orgId": "..."}}`) would turn group names into dotted leaf paths and lose them.
   - `ClaimsNormalizer::flatten()` — recursive dot-notation flattening (max depth 5, max 2000 keys, else `ClaimsTooComplexException`); base64-decodes leaf string values when `claim_encoding === 'base64'` (Zitadel-style).
   - `Sw6OidcAccessControlEvaluator::evaluate()` — claims-based access-control rules (see below); throws `AccessControlDeniedException` before anything is looked up, created or synced.
   - `AttributeMapper::map()` → `MappedProfile` (see Provisioning below).

3. **JWT verification (`JwtVerifier::verify`)** — via `web-token/jwt-framework`. Only `RS256`/`RS384`/`RS512` accepted. JWKS fetched and cached (Shopware's `cache.app`, keyed by sha256 of the JWKS URL, TTL = provider's `jwks_cache_ttl`). A failed fetch (HTTP error, unparsable or empty key set) sets a 60s `sw6oidc_jwks_fail_<sha256>` circuit-breaker flag that short-circuits further fetches; a signature that fails against the cached set triggers one forced refetch (key rotation), itself limited to once per 60s per endpoint via `sw6oidc_jwks_refreshed_<sha256>`. Checks: signature, `exp` (future), `nbf` (past, if present), `iss` (exact match), `aud` (string-or-array match), `nonce` (exact match against the expected nonce — if no nonce was expected, logs a warning and **skips** the check rather than failing).

4. **Admin OIDC ↔ League OAuth2 bridge** (`Service/AdminAuth/`) — rather than reimplementing Shopware's admin token issuance, the plugin runs a **second**, plugin-owned `League\OAuth2\Server\AuthorizationServer` wired directly to Shopware core's own `ClientRepository`/`AccessTokenRepository`/`ScopeRepository`/`FakeCryptKey` plus `APP_SECRET` as the encryption key, so the tokens it mints are indistinguishable from a normal password-grant login.
   - `AdminOidcGrant` (grant identifier `sw6oidc_admin`) reads a **pre-verified** user id off a PSR-7 request attribute (`REQUEST_ATTRIBUTE_USER_ID`) set by the calling controller *after* it has already verified the user via OIDC or WebAuthn — there is no password check inside the grant itself; trust is established entirely by the caller.
   - `AdminAuthorizationServerFactory` exists only because `enableGrantType()` needs a `\DateInterval` (access-token TTL `PT10M`) that isn't constructible in `services.xml`. Registered under the plugin's own service id, deliberately never aliased to the bare `League\OAuth2\Server\AuthorizationServer` class id (would collide with Shopware core's own `/api/oauth/token` server).
   - `AdminLoginNonceService` bridges the full-page OIDC redirect back into the Administration SPA: 120s-TTL nonce → admin redirected to `{administrationBaseUrl}/#/login?sw6oidc_nonce=...` → the `sw-login` JS override POSTs the nonce to `POST /api/sw6oidc/admin/token` → `OidcAdminAuthController::exchangeNonce()` redeems it, sets the grant's user-id attribute, and calls `respondToAccessTokenRequest()` — a genuine OAuth2 token response. The JS then forces a manual router push / page reload, because a non-full-page-reload login otherwise leaves the SPA's modules/menu uninitialized.
   - Admin Passkey login uses the same grant/server directly (no nonce hand-off needed, it's a same-page AJAX ceremony) but must manually set `client_id=administration` on the request, since League validates the client before the grant's `validateUser()` runs.

5. **Logout** — `RpInitiatedLogoutService`, shared:
   - `buildLogoutUrl()` returns `null` if the provider has no `end_session_endpoint`. Special-cases **Authelia** forward-auth (endpoint path ends in `/logout`, no `/oauth2/`/`/oidc/`) with `?rd=<postLogoutRedirectUri>` instead of standard OIDC params.
   - `revokeToken()` — fire-and-forget RFC 7009 POST to the revocation endpoint; all exceptions are swallowed with a warning log (a failed revocation must never block logout).
   - `LogoutContextStore` — because Shopware storefront sessions are stateless context tokens with no room for custom session data, this caches `{providerId, idToken}` per login (86400s TTL, keyed by the sales-channel context token), consumed exactly once at logout.
   - Storefront logout is two steps, because the logout route can't itself replace the HTTP response:
     - `Storefront/Service/OidcLogoutRoute` decorates core `LogoutRoute`. It captures the **pre-logout** context token, delegates, then consumes the `LogoutContextStore` entry, resolves the provider, revokes the token, builds the IdP logout URL (post-logout target: absolute `frontend.account.login.page`) and hands it to the request-scoped `PendingLogoutRedirect`. This can't be a `CustomerLogoutEvent` listener: core swaps in a fresh random context token *before* dispatching that event, so the event's token never matches the stored entry.
     - `CustomerLogoutSubscriber` (`KernelEvents::RESPONSE`) overwrites the response with that URL — only on route `frontend.account.logout.page`, so Store API logouts keep their JSON body.
   - Admin logout: the admin callback stores `{providerId, idToken}` via `LogoutContextStore::rememberForAdmin()`, keyed by **admin user id** (access-token `jti`s change on every silent refresh). The `sw-admin-menu` override's `onLogoutUser()` POSTs `/api/sw6oidc/admin/logout` (`OidcAdminAuthController::logout()`, auth required), which returns `{"logoutUrl": string|null}`. With a URL, the override revokes Shopware's token, clears local state and navigates to the IdP; with `null` or on any failure it falls through to core's stock logout. Inactivity logouts never reach it and stay local.
   - IdP-initiated logout: see "Back-Channel Logout" below. **Gap**: no Front-Channel Logout yet.

## Architecture — Back-Channel Logout & rate limiting

- **`Controller/Oidc/BackChannelLogoutController`** — `POST /sw6oidc/backchannel-logout` (storefront route scope, unauthenticated, `logout_token` form field). Steps: rate-limit peek → `JwtVerifier::decodeUnverified()` for `iss`/`aud` only → `ProviderResolver::findByIssuer()` (active providers with that exact issuer) → first whose `clientId` is in `aud` → `JwtVerifier::verifyLogoutToken()` → `jti` replay check (`cache.app`, `sw6oidc_bcl_jti_<sha256(iss,jti)>`, until `exp`, capped 1h; a replay answers 200 without acting) → `Sw6OidcIdpLogoutHandler::logout(providerId, sub, sid)`. 200 + `Cache-Control: no-store` on success (incl. nothing to do), 400 JSON `invalid_request` on any validation failure, 429 when blocked.
- **`JwtVerifier::verifyLogoutToken()`** — shares `verifySignedPayload()` (signature, RS* only, JWKS cache/refetch/circuit breaker) and `assertStandardClaims()` (`exp`/`nbf`/`iss`/`aud`) with `verify()`, then requires `iat`, `events[BACKCHANNEL_LOGOUT_EVENT]` as an object, `sub` or `sid`, and rejects any `nonce` (what stops an id_token being replayed as a logout token). `typ` is not checked (IdPs differ).
- **`Service/Session/Sw6OidcIdpLogoutHandler`** — shared by Back-/Front-Channel Logout: `sid` given → `resolveBySid()` (filtered to the `sub` too when both are present), else `resolve(sub)`; removes each entry, consumes its `LogoutContextStore` entry, calls the destruction service (admin once per user, since that destroys all of the user's sessions anyway). Returns the ended sessions.
- **`Service/Security/Sw6OidcRateLimiter`** — Symfony `RateLimiterFactory` built in the class (plugins can't join core's `shopware.api.rate_limiter`), fixed window 10/60s per scope + sha256(client IP), `CacheStorage` on `cache.rate_limiter` (`on-invalid="null"` → `cache.app`). **Penalty model**: `isBlocked()` peeks (`consume(0)`), only `recordFailure()` consumes — used by the back-channel endpoint (validation failures) and both OIDC callbacks (the generic `\Throwable` failure path only; access-control and provisioning denials are legitimate users and don't count).

## Architecture — Session/subject registry

Plumbing for Back-/Front-Channel Logout and forced logouts: which local sessions did an OIDC login create, keyed by what an IdP logout notification carries.

- **`Service/Session/Sw6OidcSessionRegistry`** — plain `cache.app` (PSR-6; not `AtomicCacheInterface`, nothing is one-time here). One entry per login (`Sw6OidcSession`: provider, `sub`, optional `sid`, user type/id, `sessionKey`, sales channel, id_token, created-at; 86400s TTL) plus three hashed index lists: by provider+`sid`, by provider+`sub` (both only unique per issuer), by local account. `register()`, `resolve(providerId, sub)`, `resolveBySid()`, `resolveByUser()`, `revoke(providerId, sub, ?sid)`, `revokeBySid()`, `remove()`. Index updates are unlocked read-modify-write (a race can only make a logout miss a session, never grant access); lists are capped at 50.
- **`sessionKey`** — Storefront: the sales-channel context token minted by the login (`OidcCallbackController`). Admin: the jti of the access token minted by `OidcAdminAuthController::exchangeNonce()` — the callback can't know it yet, so `AdminLoginNonceService::createNonce()` carries provider/sub/sid/id_token forward in the nonce (`AdminLoginNonce`) and `exchangeNonce()` registers once the token exists (`JwtPayloadReader::stringClaim($accessToken, 'jti')`). `sub` comes from the verified id_token, else userinfo; `sid` only from the verified id_token (`OidcCallbackResult::subject()`/`sessionId()`). No `sub` → nothing registered. Passkey logins are not registered (no IdP session).
- **`Service/Session/Sw6OidcSessionDestructionService`** — the storefront-vs-admin asymmetry: a **customer** session is destroyed exactly (`SalesChannelContextPersister::delete()` of the context token — the next request loads an anonymous context). An **admin** session cannot be targeted: Shopware admin access tokens are stateless JWTs (`AccessTokenRepository::revokeAccessToken()` is a no-op) and refresh-token ids rotate on each refresh. So it ends **all** of that admin's sessions: `RefreshTokenRepository::revokeRefreshTokensForUser()` plus bumping `user.last_updated_password_at`, which core's `SymfonyBearerTokenValidator` already uses to reject access tokens issued before it (the password-change mechanism; the password itself is untouched).

## Architecture — Claims-based access control

- **Schema** — `sw6oidc_access_control_rule` (`Core/Content/AccessControlRule/`, one-to-many `accessControlRules` on the provider, cascade delete): `claim_key`, `operator` (`Sw6OidcAccessControlRuleDefinition::OPERATORS`: `eq`/`neq`/`contains`/`not_contains`/`exists`/`not_exists`), nullable `value`/`error_message`, `sort_order`.
- **`Service/Security/Sw6OidcAccessControlEvaluator`** — loads the provider's rules (repository, ordered by `sortOrder`), AND-combines them, first failure throws `AccessControlDeniedException` (rule id, claim key, configured message). No rules = allow. Works on the **flattened** claims, list-aware: a claim's *members* are its numeric children's scalar values (`groups.0`, `groups.1`) plus its non-numeric child names (Zitadel role objects `roles.Admins.orgId` → `admins`); `contains` tests membership when there are members, else substring of the scalar; `eq` compares the scalar (or a one-member list's member). Case-insensitive, trimmed; `true`/`1`/`false`/`0` compare as booleans. Unknown operator → deny (fail closed, error log). `matches()` is public for tests.
- **Hook** — `OidcCallbackProcessor::process()`, after `flatten()`, before `AttributeMapper::map()`. Not applied by the live login test (`OidcLiveLoginTestService`), which only reports claims.
- **Surfacing the message** — `AccessControlDeniedException::getDisplayMessage()` returns the message as plain text (tags stripped, whitespace collapsed, 500 chars) or null. Storefront `OidcCallbackController` flashes it (fallback snippet `sw6oidc.login.accessDenied`). Admin `OidcAdminAuthController::callback` never puts free text into the URL: it stores the message via `Service/AdminAuth/AdminLoginErrorTicketStore` (`AtomicCacheInterface`, 60s, single-use) and redirects with `sw6oidc_error=access_denied&sw6oidc_error_ticket=…`; the `sw-login` override redeems it via `GET /api/sw6oidc/admin/login-error/{ticket}` (anonymous; the ticket is the capability).
- **Config transfer** — rules are exported/imported with the provider (`accessControlRules`), and replaced on `--overwrite`, so an import can never silently drop a restriction.

## Architecture — Passkey (WebAuthn) flow

Independent of OIDC; uses `web-auth/webauthn-lib` **^5.3** (no repository contract: the plugin looks up and persists `CredentialRecord`s itself).

- **`WebauthnCeremonyFactory`** — single seam building every webauthn-lib object: RP entity, ES256+RS256 only, `AuthenticatorSelectionCriteria` with `residentKey=required` (registrations are always discoverable/passwordless-capable), attestation always `'none'` (broad compatibility over provenance — a deliberate trade-off, matching the Magento module).
  All JSON (browser responses via `loadCredential()`, options for the browser via `serializeOptions()`, stored records) goes through the library's own Symfony serializer (`serializer()`).
- **`PasskeyCredentialRepository`** — the sole DAL↔library seam: `findOneByCredentialId(rawId)`, `findAllForUserHandle(rawHandle)` (hex-compared), `saveNewCredentialRecord()`, `updateAfterAssertion()` (persists the counter-bumped record `check()` returns — 5.x no longer saves it itself), `toRecord()`/`toJson()`, plus self-service helpers (`findAllForOwner`, `deleteOwnedByUser`, the latter enforcing ownership itself). `public_key` holds normalized `CredentialRecord` JSON; rows written by 4.x deserialize unchanged.
- **`PasskeyRegistrationService::buildCreationOptions()`** / **`PasskeyAuthenticationService::buildRequestOptions()`** — generate a challenge, build the options (userHandle = `sha256("{userType}:{userId}")`), and cache the **raw inputs** (not the serialized options object) for 300s; verify rebuilds the options via the constructor. Originally a workaround for a webauthn-lib 4.9.3 base64 round-trip bug, kept because it doesn't depend on symmetric (de)serialization.
- **`verifyAndPersist()`/`verifyAssertion()`** take the request **host** string (origin/rpId check). Assertion passes the credential's own user handle to `check()` when the login was email-scoped (`allowCredentials` non-empty), `null` for usernameless login (then the authenticator's response must carry a matching userHandle).
- **`AdminPasskeyLoginTokenTracker`** — keyed by the minted access token's own `jti` → which `credentialId` authenticated it, so "My passkeys" can force-logout a session that's authenticating with the exact passkey being deleted. Scope limitation: only covers the 10-minute access-token window; a silent refresh-token renewal mints a new `jti` this tracker never learns about, so protection stops applying past that point (documented, not a bug).
- Usernameless/discoverable login (`allowCredentials=[]`) is used Storefront-side; email-scoped `allowCredentials` is used Admin-side when an email was typed.

## Architecture — Provisioning & mapping

- **`AttributeMapper::map()`** — 19 attribute types (`Sw6OidcAttributeMappingDefinition::TYPE_*`: email, username, firstname, lastname, birthday, gender, phone, plus 6 billing_* and 6 shipping_* address fields). Only identity fields (email, username, firstname, lastname, birthday, gender, phone) have hardcoded OIDC-standard default claim keys; address/state/country fields have **no default** and stay unmapped until an admin explicitly configures them per provider. Missing/invalid email throws `MissingEmailClaimException`. Gender is normalized via `GenderMapper` into a Shopware `salutation` technical name (`mr`/`mrs`, recognizing English + German variants).
- **`AttributeTransformer::apply()`** — optional per-mapping value transform, applied inside `AttributeMapper`'s claim read (on every login, so it affects creation and sync alike), even when the mapped claim itself is missing: `concat {claims[], separator=" "}`, `split {separator, index}` (negative index from the end), `prefix {value}`, `regex_replace {pattern, replacement}` (pattern and input capped at 4096 bytes). Never throws — a bad function/params/regex logs a warning and passes the value through. `transform_function` carries a strict `Choice` flag (`AttributeTransformer::FUNCTIONS`); the admin mapping grid edits both columns.
- **`CustomerProvisioningService::findOrCreateCustomer()`** — looks up by email; if found, enforces `UserProviderBindingService::assertNotBoundToDifferentProvider()`, binds if unbound (first-login-wins), then runs `syncExisting()` (see below). If not found and `auto_create_customer` is false, throws `CustomerProvisioningDeniedException`. Otherwise creates a customer via `GroupMappingResolver`-resolved customer group, a generated customer number, a billing address (falls back to `-` placeholders for missing fields), an optional distinct shipping address, and a random unused password (no local login).
- **Sync-on-SSO for an already-bound customer/admin** — `CustomerProvisioningService::syncExisting()` and `AdminProvisioningService::syncProfile()`/`syncRole()` re-apply mapped claims on every login, gated independently per provider toggle (`sync_customer_profile_on_sso`, `sync_customer_address_on_sso`, `sync_customer_group_on_sso`, `sync_admin_profile_on_sso`, `sync_admin_role_on_sso`). Every field is a partial update: a claim that isn't mapped (null on the `MappedProfile`) or a group/role that doesn't resolve is left alone rather than overwritten with a placeholder — this is a refresh of whatever the IdP actually provided on this login, not a reset to defaults. Address sync only ever updates the customer's *existing* default billing address in place; it never creates one (a customer always has one by the time they can log in again).
- **`AdminProvisioningService::findOrCreateAdmin()`** — same email lookup + binding pattern. If found and `sync_admin_role_on_sso` is set, re-resolves and overwrites the user's ACL roles on every login (see superadmin exception below). If not found and `auto_create_admin` is false, throws `AdminProvisioningDeniedException::autoCreateDisabled()`. Creation requires *either* a resolved ACL role (`GroupMappingResolver::resolveAclRoleId()`, falling back to the provider's `default_acl_role_id`) *or* an explicit superadmin grant (see below) — otherwise throws `AdminProvisioningDeniedException::noRoleResolved()`. Username is derived from the mapped username or the email's local-part, deduplicated with an incrementing suffix.
- **Superadmin via OIDC group (opt-in, two-gate)** — Shopware's native superadmin (`user.admin = true`) bypasses ACL entirely and is a distinct mechanism from any ACL role, however permissive. `AdminProvisioningService` only ever sets `admin = true` when *both* the provider's `allow_superadmin_group_mapping` flag is on *and* `GroupMappingResolver::matchesSuperadminGroup()` finds a `sw6oidc_role_mapping` row with `mapping_type = 'superadmin'` matching the user's OIDC groups — deliberately gated two ways so a stray mapping row alone can never grant it. `syncRole()` (on `sync_admin_role_on_sso`) only ever *grants* superadmin this way, never revokes it if a later login's groups stop matching (avoids an IdP claims glitch silently locking out the only superadmin) — downgrading a superadmin back to an ACL role is a manual admin action.
- **`GroupMappingResolver`** — resolves OIDC group claims → ACL role (admin) or customer group (storefront) via `sw6oidc_role_mapping`, case-insensitive match ordered by `sort_order`, first match wins, else the provider's own default, else `null`. `matchesSuperadminGroup()` is separate: case-insensitive match against `mapping_type = 'superadmin'` rows only, boolean, **no default/fallback** — a superadmin grant must always come from an explicit group match.
- **`UserProviderBindingService`** — reads/writes `sw6oidc_user_provider`. `assertNotBoundToDifferentProvider()` throws `ProviderMismatchException` if already bound to a different IdP; `bindIfUnbound()` only writes if unbound (first login wins, permanently).

## Directory / component reference

**`Service/Oidc/`**
- `OidcCallbackProcessor` — shared post-redirect pipeline (see above).
- `AuthorizationRequestBuilder`, `OidcSecurityHelper` — authorize-URL construction, state/PKCE/nonce lifecycle.
- `TokenExchangeService` — authorization_code token exchange.
- `UserInfoService` — userinfo endpoint fetch.
- `ClaimsNormalizer` — flattening, group normalization, base64 claim decoding; `extractEmail()` exists as a utility but is not called from the main flow (unwired).
- `OidcDiscoveryService` — maps a `.well-known/openid-configuration` response onto provider entity fields; used by the admin Provider save screen for auto-discovery.
- `JwtVerifier` — JWT signature + claims verification, JWKS caching.
- `OidcHttpClient` — thin HTTP wrapper (`postForm`/`getWithBearerToken`/`getJson`), one retry after 500ms on transport exceptions, throws `OidcHttpException` on HTTP ≥400 or non-JSON body.
- `RpInitiatedLogoutService`, `LogoutContext`/`LogoutContextStore` — logout (see above).

**`Service/AdminAuth/`**
- `AdminOidcGrant`, `AdminAuthorizationServerFactory`, `AdminLoginNonceService` — admin OIDC ↔ League OAuth2 bridge (see above).
- `AdminLoginNonce` — what a redeemed nonce carries (user id + the login's provider/sub/sid/id_token for the session registry).
- `AdminLoginErrorTicketStore` — one-time error-message hand-off to the `sw-login` screen (access-control denials).

**`Service/Passkey/`**
- `WebauthnCeremonyFactory`, `PasskeyCredentialRepository`, `PasskeyRegistrationService`, `PasskeyAuthenticationService`, `AdminPasskeyLoginTokenTracker` — WebAuthn ceremony (see above).
- `PasskeyConfig` — reads plugin system config (`passkeyEnabledAdmin`, `passkeyEnabledCustomer` per sales channel, `passkeyRpName`, `passkeyRpId`, with hostname/shop-name fallbacks).

**`Service/Provisioning/`**
- `AttributeMapper`, `Sw6OidcAttributeMappingDefinition`, `CustomerProvisioningService`, `AdminProvisioningService`, `GroupMappingResolver`, `UserProviderBindingService`, `CountryResolver`, `GenderMapper`, `MappedProfile`/`AddressProfile` DTOs.

**`Storefront/Controller/`**
- `SendAuthorizationRequestController` (`GET /sw6oidc/login`), `OidcCallbackController` (`GET /sw6oidc/callback`), `PasskeyController` (registration/login options+verify under `/sw6oidc/passkey/*`), `AccountPasskeyController` (`GET /account/passkey`, `POST /account/passkey/delete/{credentialId}`).
- `Storefront/Service/OidcCustomerLoginRoute` (`extends AbstractLoginRoute`) — a standalone passwordless login route, deliberately **not** a decorator (`getDecorated()` throws) so the real `AbstractLoginRoute` alias keeps doing normal password checks for everyone else; only ever invoked after OIDC/Passkey has already verified the customer for this request.

**`Controller/Oidc/`**
- `BackChannelLogoutController` (`POST /sw6oidc/backchannel-logout`), see "Back-Channel Logout & rate limiting".

**`Controller/Api/`**
- `OidcAdminAuthController` — `login-options`, `login`, `callback`, `token` (nonce exchange), `login-error/{ticket}` under `/api/sw6oidc/admin/*`; `auth_required: false` at the class level (all actions are necessarily pre-auth).
- `OidcUserProviderAdminController` — `POST /api/_action/sw6oidc/user-provider/info` (batch `{userType, userIds}` → bindings keyed by id) and `.../unlink`, backing the Administration "OIDC Provider" info (users listing column, user detail incl. the native-SSO `user.sso.detail` variant, own profile, customer base info; `extension/sw-users-permissions-user-*`, `extension/sw-sso-users-permission-user-detail`, `extension/sw-profile-index-general`, `extension/sw-customer-base-info`, shared `component/sw6oidc-user-provider-info`). Gated per request by the core `user:read|update` / `customer:read|update` privileges of the given userType (not by any `sw6oidc_user_provider` privilege, which ordinary roles lack); an admin may always read their own binding (profile). Unlink runs in system scope for the same reason.
- `PasskeyAdminController` — registration/`my-credentials`/delete (auth required) plus `login-options`/`login-verify` (route-level `auth_required: false` override) under `/api/sw6oidc/admin/passkey/*`.

**`Migration/`**
- `Migration1730000001CreateOidcSchema` — creates the initial 5 tables below; `updateDestructive()` is a no-op.
- `Migration1758000001AddProviderTestStatus` — adds `sw6oidc_provider.last_test_status`/`last_test_at` (live login test result).
- `Migration1789383427AddSuperadminGroupMapping` — adds `sw6oidc_provider.allow_superadmin_group_mapping`.
- `Migration1789390512AddLastTestClaims` — adds `sw6oidc_provider.last_test_claims` (JSON; the flattened claims from the last live login test, so the Attribute Mapping picker's discovered-claims list survives a page reload).
- `Migration1789470000AddUserProviderUpdatedAt` — adds the missing `sw6oidc_user_provider.updated_at`.
- `Migration1790685361EncryptProviderClientSecrets` — widens `client_secret` to 2048 and encrypts existing plaintext rows (idempotent; needs `APP_SECRET`).
- `Migration1790686535DropAttributeMappingSyncOnSso` — destructive step drops `sw6oidc_attribute_mapping.sync_on_sso`.
- `Migration1790800001CreateAccessControlRuleSchema` — creates `sw6oidc_access_control_rule`.

**`Service/Security/`** — `OidcSecurityHelper` (state/PKCE/nonce), `Sw6OidcEncryptor`, `SsrfUrlValidator`, `PasswordLoginPolicy`, `Sw6OidcCspHostCollector`, `Sw6OidcAccessControlEvaluator`, `Sw6OidcRateLimiter`, exceptions (`ClientSecretUnavailableException`, `PasswordLoginDisabledException`, `InvalidStateException`, `AccessControlDeniedException`).

**`Service/Session/`** — `Sw6OidcSession`, `Sw6OidcSessionRegistry`, `Sw6OidcSessionDestructionService` (see "Session/subject registry"), `Sw6OidcIdpLogoutHandler`. **`Service/Jwt/JwtPayloadReader`** — unverified payload decode for tokens verified elsewhere.

**`Service/Cache/`** — `AtomicCacheInterface`, `RedisAtomicCache` (always wired, runtime backend selection), `CachePoolAtomicCache`, `RedisConnectionFactory`. **`Service/Http/`** — `OidcHttpClient`, `Sw6OidcHttpClientFactory` (SSRF-guarded client). **`Service/Config/`** — `OidcConfigTransfer`, `ImportResult`. **`Console/`** — export/import commands. **`Event/`** — see Extension points.

**`Subscriber/`** — `UserProviderCleanupSubscriber`, `Sw6OidcProviderWriteGuardSubscriber`, `AdminPasswordLoginGuardSubscriber`, `Sw6OidcCspSubscriber`. **`Storefront/Service/PasswordLoginGuardLoginRoute`** — decorator of core `LoginRoute`.

**`Twig/`**
- `AdminEntrypointsExtension` — registers `sw6oidc_admin_scripts()`/`sw6oidc_admin_styles()`, reading the plugin's own Vite `entrypoints.json` directly (Pentatrion's helper only resolves Shopware's own pre-registered bundle name). Forces the plugin's admin JS to load on the pre-auth login screen, which Shopware's normal `loadPlugins()` boot path otherwise skips.
- `StorefrontLoginOptionsExtension` — registers `sw6oidc_storefront_sso_providers(context)` (one `{id, label}` per visible customer-scoped provider, `label` falling back to a generic translated string only when a provider has no `displayName`) / `sw6oidc_storefront_passkey_available(context)`, used by the storefront login template override to render one SSO button per provider plus the Passkey button.

**`Sw6Oidc.php`** — plugin bootstrap; no custom `install()`/`activate()`/`deactivate()`. `uninstall()` drops all plugin tables (FK-safe order) unless the admin checks "keep user data" in the uninstall dialog.

## Database schema (`Migration1730000001CreateOidcSchema` + follow-up migrations)

- **`sw6oidc_provider`** — one row per configured IdP: identity/OAuth fields (`app_name`, `client_id`, `client_secret`, `public_client`), endpoints (auto-fillable via discovery), protocol knobs (`scope`, `pkce_flow`, `claim_encoding`, `group_attribute`), behavior flags (`auto_create_customer`/`auto_create_admin`, `disable_non_oidc_*_login`, `show_*_link`, `is_active`, `login_type`), sync-on-SSO toggles (all five wired, see Provisioning & mapping above), `allow_superadmin_group_mapping` (gates `superadmin`-type role mapping rows, see Provisioning & mapping above), ops settings (`http_timeout`, `jwks_cache_ttl`), live-test bookkeeping (`last_test_status`, `last_test_at`, `last_test_claims`), and FK defaults (`default_customer_group_id`, `default_acl_role_id`).
- **`sw6oidc_attribute_mapping`** — per-provider claim → Shopware-field mapping (`attribute_type`, `attribute_name`, optional `transform_function`/`transform_params` applied by `AttributeTransformer`, see Provisioning above). The former per-attribute `sync_on_sso` column is dropped by `Migration1790686535DropAttributeMappingSyncOnSso` (destructive step); re-sync is gated only by the provider-level toggles.
- **`sw6oidc_access_control_rule`** — per-provider claims-based login gate (`claim_key`, `operator`, `value`, `error_message`, `sort_order`), see "Claims-based access control" above.
- **`sw6oidc_role_mapping`** — per-provider OIDC-group → ACL role / customer group / superadmin grant (`mapping_type`: `admin_role`|`customer_group`|`superadmin`, `oidc_group`, `acl_role_id`, `customer_group_id`, `sort_order`); `superadmin` rows leave both `acl_role_id`/`customer_group_id` null.
- **`sw6oidc_user_provider`** — permanent IdP binding, polymorphic (`user_type`, `user_id`) → `provider_id`, unique per account.
- **`sw6oidc_passkey_credential`** — one WebAuthn credential per user (polymorphic `user_type`/`user_id`, `credential_id`, `public_key`, `sign_count`, `user_handle`, `nickname`).

## Extension points / events (`src/Event/`)

All implement `ShopwareEvent` (have `getContext()`), dispatched via `event_dispatcher`:
- `AttributeMappingCompletedEvent` — end of `AttributeMapper::map()`, every OIDC login, before lookup/create/sync. Carries provider, flattened claims and the `MappedProfile`; `MappedProfile` is readonly, so listeners replace it via `setProfile()`. The email is re-validated afterwards (`MissingEmailClaimException`).
- `CustomerBeforeCreateEvent` / `AdminBeforeCreateEvent` — right before `repository->create()` in the JIT-create path; `getPayload()`/`setPayload()` let listeners change the create payload. `id` is re-forced afterwards. The admin payload contains `admin` and `aclRoles`, so a listener can bypass the two-gate superadmin rule — deliberate power, document it for integrators.
- `CustomerAfterCreateEvent` / `AdminAfterCreateEvent` — read-only, after create + provider binding, with the reloaded entity. Not dispatched for existing (synced) accounts.

## Architecture — Provider save-time validation & password-login enforcement

- **SSRF** — `Service/Security/SsrfUrlValidator` (replaces the former `DiscoveryUrlValidator`): https only, host must resolve and every resolved IP must be public (Symfony `IpUtils::PRIVATE_SUBNETS` + multicast). `SW6OIDC_ALLOW_INSECURE_IDP_URLS=1` allows http/private hosts with a warning (dev only); unresolvable hosts are always blocked. Used by discovery, the connection test, and `Subscriber/Sw6OidcProviderWriteGuardSubscriber` (`PreWriteValidationEvent`), which validates `well_known_config_url` + the six fetched endpoint columns on every insert/update of `sw6oidc_provider` (`issuer` is never fetched, so not checked). Violations carry the camelCase property path + code `SW6OIDC_URL_BLOCKED`, surfaced per field in the admin form via `mapPropertyErrors`.
- **Runtime SSRF guard** — `sw6oidc.http_client` (built by `Service/Http/Sw6OidcHttpClientFactory`) wraps the core HTTP client in `NoPrivateNetworkHttpClient` (resolved-IP check per connection *and* redirect) unless the insecure flag is set; `OidcHttpClient` and `JwtVerifier` use it.
- **Lockout guard** — the same subscriber rejects setting `disable_non_oidc_{admin,customer}_login` to true unless `sw6oidc_user_provider` has at least one binding of that user type **for this provider** (code `SW6OIDC_LOCKOUT_GUARD`).
- **Enforcement** — `Service/Security/PasswordLoginPolicy::isPasswordLoginDisabled($loginType)` is true when any *active* provider serving that login type has the flag (shop-wide; providers aren't sales-channel scoped). Password path only:
  - Storefront/Store API: `Storefront/Service/PasswordLoginGuardLoginRoute` decorates core `LoginRoute` and throws `PasswordLoginDisabledException` (403, `SW6OIDC_PASSWORD_LOGIN_DISABLED`; subclasses `CustomerOptinNotCompletedException` only so the Storefront `AuthController` renders its snippet instead of "bad credentials"). `OidcCustomerLoginRoute` is standalone, so OIDC/Passkey are unaffected. The login template hides the password form (`sw6oidc_storefront_password_login_disabled()`).
  - Admin: `Subscriber/AdminPasswordLoginGuardSubscriber` (`kernel.request`, priority 8) answers `grant_type=password` on route `api.oauth.token` with a 403; refresh_token/client_credentials untouched. `login-options` returns `passwordLoginDisabled` so the `sw-login` override hides the native form.
  - Break-glass: `SW6OIDC_ALLOW_PASSWORD_LOGIN=1`.

## Architecture — CSP

Shopware 6.7 has **no CSP contribution API**: `CoreSubscriber::setSecurityHeaders()` sets the header from the fixed `shopware.security.csp_templates` (storefront default: none at all; administration: `object-src`/`script-src`/`base-uri`/`frame-ancestors`), and only if the response has none yet. So the plugin takes the response-subscriber path: `Subscriber/Sw6OidcCspSubscriber` (`kernel.response`, priority -10, storefront/administration route scopes only) appends the origins from `Service/Security/Sw6OidcCspHostCollector` (deduplicated https origins of all active providers' endpoints, cached in `cache.app`, invalidated on `sw6oidc_provider.written`/`.deleted`) to `form-action`/`connect-src`/`frame-src`/`img-src` — **only if the directive is already present and isn't `'none'`**; it never adds a directive (that would tighten the policy). With the stock templates it's a no-op, because every IdP interaction is a top-level navigation or server-side call; it exists for shops with their own stricter policy. The live-test popup's own CSP in `OidcProviderAdminController` is unrelated (api scope, nonce'd inline script) and stays as is.

## Known gaps / implementation notes

Do not assume the following are fully wired just because the schema or config UI suggests they are:

- `config.xml`'s `debugLoggingEnabled` toggle is not wired to the Monolog channel; the actual log level is controlled solely by the `SW6OIDC_LOG_LEVEL` env var (default `debug`), set in `services.xml`.
- `client_secret` is encrypted at rest (`Core/Content/Provider/Field/Sw6OidcEncryptedField` + serializer, `Service/Security/Sw6OidcEncryptor`, key derived from `APP_SECRET`, envelope `sw6oidc_v1:`) and write-only over the Admin API (no `ApiAware` flag). Rotating `APP_SECRET` makes stored secrets undecryptable: hydration passes the envelope through, and `TokenExchangeService` throws `ClientSecretUnavailableException`. An envelope written back is accepted only if it decrypts with this installation's key.
- No OIDC Front-Channel Logout support. Back-Channel Logout ends *all* sessions of an admin user (see "Session/subject registry"), and only knows sessions created after the registry shipped.
- `AtomicCacheInterface` is always `RedisAtomicCache`, which selects its backend **at runtime**: Redis GETDEL when `SW6OIDC_REDIS_DSN` (`redis://` or `rediss://`) is set and connectable, else `CachePoolAtomicCache` (sequential get-then-delete on `cache.app`, single-node only). Deliberately not a compiler pass — that would freeze the choice into the cached container. Redis errors degrade to the fallback per call, and `getAndDelete()` consults the fallback on a Redis miss.
- `ClaimsNormalizer::extractEmail()` exists but has no caller in the current codebase. (`UserProviderBindingService::unbind()` is called by the Administration unlink action and `Subscriber/UserProviderCleanupSubscriber`, which removes a binding on `user.deleted` / `customer.deleted` since `user_id` has no FK.)
- Tests are unit-only (`tests/Unit/`, ~290 tests): OIDC core (state/PKCE, JWT, claims), provisioning, group mapping, bindings, WebAuthn ceremonies against the real 5.x validators (`SoftwareAuthenticator` test helper), and every security/config component. No integration tests against a live Shopware instance or IdP (Dex harness still a TODO).
- No Docker/dev Shopware environment committed in this repo. Deploy note for a running shop: PHP-FPM opcache may keep serving stale plugin classes after an update — reset it (e.g. `cachetool opcache:reset`) in addition to `cache:clear`.

## Tooling

- `phpstan.neon.dist` — level 5, scans `src` (excludes `src/Resources`), `treatPhpDocTypesAsCertain: false` (much of the code validates untrusted third-party data at runtime — IdP responses, WebAuthn JSON — against its own PHPDoc shapes).
- `psalm.xml` — `errorLevel="4"`, `findUnusedCode="false"`; explicit suppressions for `MissingOverrideAttribute` (PHP 8.2 predates `#[\Override]`), `UndefinedDocblockClass` (Shopware DAL generics stubs), `InternalMethod` (`Context::createDefaultContext()`, needed pre-auth; plus file-scoped for the encrypted-field serializer and the provider write guard, which necessarily use DAL write-stack internals), `UndefinedClass` (`\Redis`, optional ext-redis for `RedisAtomicCache`).
- `phpcs.xml.dist` — PSR12 base, relaxed line length (soft 180 / hard 200) for long route-attribute/constructor-promotion lines.
- `rector.php` — `withPhpSets()` (auto-detects PHP 8.2 floor from `composer.json`), `deadCode`/`codeQuality`/`typeDeclarations`/`earlyReturn` sets.
- CI (`.github/workflows/ci.yml`) — 4 parallel jobs on push/PR to `main`: `lint` (PHPCS), `static-analysis` (PHPStan + Psalm), `rector` (dry-run, fails if changes remain), `tests` (PHPUnit matrix across PHP 8.2/8.3/8.4/8.5, coverage uploaded per version).

# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

This is a Shopware 6 plugin (`MartinKuhl\Sw6Oidc`, composer package `martinkuhl/shopware6-oidc-sso`, currently v0.1.0) that provides OpenID Connect (OIDC) and Passkey (WebAuthn/FIDO2) single sign-on for both Storefront customers and Administration users. It mirrors the architecture of the sibling `magento2-oidc-sso` module: multi-provider OIDC with JIT provisioning and group/role mapping, plus a second, independent passwordless login method (Passkey) that bridges into native authentication the same way OIDC does.

The plugin is early-stage (v0.1.0, MIT license, two narrow unit tests, no integration tests run against a live Shopware instance yet). See "Known gaps / implementation notes" below before assuming any given feature is fully wired end to end, and see `TODO.md` for the planned `web-auth/webauthn-lib` 4.x→5.x migration (deferred until the OIDC/Passkey flows are proven in production).

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
```

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
   - `AttributeMapper::map()` → `MappedProfile` (see Provisioning below).

3. **JWT verification (`JwtVerifier::verify`)** — via `web-token/jwt-framework`. Only `RS256`/`RS384`/`RS512` accepted. JWKS fetched and cached (Shopware's `cache.app`, keyed by sha256 of the JWKS URL, TTL = provider's `jwks_cache_ttl`). Checks: signature, `exp` (future), `nbf` (past, if present), `iss` (exact match), `aud` (string-or-array match), `nonce` (exact match against the expected nonce — if no nonce was expected, logs a warning and **skips** the check rather than failing).

4. **Admin OIDC ↔ League OAuth2 bridge** (`Service/AdminAuth/`) — rather than reimplementing Shopware's admin token issuance, the plugin runs a **second**, plugin-owned `League\OAuth2\Server\AuthorizationServer` wired directly to Shopware core's own `ClientRepository`/`AccessTokenRepository`/`ScopeRepository`/`FakeCryptKey` plus `APP_SECRET` as the encryption key, so the tokens it mints are indistinguishable from a normal password-grant login.
   - `AdminOidcGrant` (grant identifier `sw6oidc_admin`) reads a **pre-verified** user id off a PSR-7 request attribute (`REQUEST_ATTRIBUTE_USER_ID`) set by the calling controller *after* it has already verified the user via OIDC or WebAuthn — there is no password check inside the grant itself; trust is established entirely by the caller.
   - `AdminAuthorizationServerFactory` exists only because `enableGrantType()` needs a `\DateInterval` (access-token TTL `PT10M`) that isn't constructible in `services.xml`. Registered under the plugin's own service id, deliberately never aliased to the bare `League\OAuth2\Server\AuthorizationServer` class id (would collide with Shopware core's own `/api/oauth/token` server).
   - `AdminLoginNonceService` bridges the full-page OIDC redirect back into the Administration SPA: 120s-TTL nonce → admin redirected to `{administrationBaseUrl}/#/login?sw6oidc_nonce=...` → the `sw-login` JS override POSTs the nonce to `POST /api/sw6oidc/admin/token` → `OidcAdminAuthController::exchangeNonce()` redeems it, sets the grant's user-id attribute, and calls `respondToAccessTokenRequest()` — a genuine OAuth2 token response. The JS then forces a manual router push / page reload, because a non-full-page-reload login otherwise leaves the SPA's modules/menu uninitialized.
   - Admin Passkey login uses the same grant/server directly (no nonce hand-off needed, it's a same-page AJAX ceremony) but must manually set `client_id=administration` on the request, since League validates the client before the grant's `validateUser()` runs.

5. **Logout** — `RpInitiatedLogoutService`, shared:
   - `buildLogoutUrl()` returns `null` if the provider has no `end_session_endpoint`. Special-cases **Authelia** forward-auth (endpoint path ends in `/logout`, no `/oauth2/`/`/oidc/`) with `?rd=<postLogoutRedirectUri>` instead of standard OIDC params.
   - `revokeToken()` — fire-and-forget RFC 7009 POST to the revocation endpoint; all exceptions are swallowed with a warning log (a failed revocation must never block logout).
   - `LogoutContextStore` — because Shopware storefront sessions are stateless context tokens with no room for custom session data, this caches `{providerId, idToken}` per login (86400s TTL, keyed by the sales-channel context token), consumed exactly once at logout.
   - Storefront logout is wired via two listeners on `CustomerLogoutSubscriber`: `CustomerLogoutEvent` (captures + consumes the `LogoutContextStore` entry, resolves the provider, revokes the token, builds the IdP logout URL) and `KernelEvents::RESPONSE` (overwrites the logout controller's own redirect with that IdP URL) — two steps because a domain event listener can't itself replace the HTTP response.
   - **Gaps**: no OIDC Back-Channel Logout support (no endpoint for an IdP to push server-side logout notifications), and no admin-side RP-initiated logout — only the Storefront/customer path redirects to the IdP on logout today.

## Architecture — Passkey (WebAuthn) flow

Independent of OIDC; uses `web-auth/webauthn-lib` **^4.7** (see `TODO.md` for the planned 5.x migration and why it's deferred).

- **`WebauthnCeremonyFactory`** — single seam building every webauthn-lib object: RP entity, ES256+RS256 only, `AuthenticatorSelectionCriteria` with `residentKey=required` (registrations are always discoverable/passwordless-capable), attestation always `'none'` (broad compatibility over provenance — a deliberate trade-off, matching the Magento module).
- **`PasskeyCredentialRepository implements PublicKeyCredentialSourceRepository`** (the 4.x interface removed in 5.x) — the sole DAL↔library seam: library-contract methods (`findOneByCredentialId`, `findAllForUserEntity`, `saveCredentialSource` for sign-count bumps) plus plugin-specific self-service helpers (`saveNewCredentialSource`, `findAllForOwner`, `deleteOwnedByUser`, the latter enforcing ownership itself).
- **`PasskeyRegistrationService::buildCreationOptions()`** — generates a challenge, builds `PublicKeyCredentialCreationOptions` (userHandle = `sha256("{userType}:{userId}")`), and caches the **raw inputs** (not the serialized options object) for 300s. This is deliberate: a documented webauthn-lib 4.9.3 bug means `PublicKeyCredentialUserEntity::jsonSerialize()` encodes the user id as url-safe base64 while `createFromArray()` decodes it as standard base64, which throws whenever the sha256-derived id happens to contain a url-safe-only character. Rebuilding the options via the constructor on verify sidesteps the round-trip entirely.
- **`PasskeyAuthenticationService::verifyAssertion()`** — passes **raw bytes**, not the already-base64-encoded id, into `assertionResponseValidator()->check()`: the repository double-base64-encodes if you pass the encoded form, producing "The credential ID is invalid."
- **`AdminPasskeyLoginTokenTracker`** — keyed by the minted access token's own `jti` → which `credentialId` authenticated it, so "My passkeys" can force-logout a session that's authenticating with the exact passkey being deleted. Scope limitation: only covers the 10-minute access-token window; a silent refresh-token renewal mints a new `jti` this tracker never learns about, so protection stops applying past that point (documented, not a bug).
- Usernameless/discoverable login (`allowCredentials=[]`) is used Storefront-side; email-scoped `allowCredentials` is used Admin-side when an email was typed.

## Architecture — Provisioning & mapping

- **`AttributeMapper::map()`** — 19 attribute types (`Sw6OidcAttributeMappingDefinition::TYPE_*`: email, username, firstname, lastname, birthday, gender, phone, plus 6 billing_* and 6 shipping_* address fields). Only identity fields (email, username, firstname, lastname, birthday, gender, phone) have hardcoded OIDC-standard default claim keys; address/state/country fields have **no default** and stay unmapped until an admin explicitly configures them per provider. Missing/invalid email throws `MissingEmailClaimException`. Gender is normalized via `GenderMapper` into a Shopware `salutation` technical name (`mr`/`mrs`, recognizing English + German variants).
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

**`Service/Passkey/`**
- `WebauthnCeremonyFactory`, `PasskeyCredentialRepository`, `PasskeyRegistrationService`, `PasskeyAuthenticationService`, `AdminPasskeyLoginTokenTracker` — WebAuthn ceremony (see above).
- `PasskeyConfig` — reads plugin system config (`passkeyEnabledAdmin`, `passkeyEnabledCustomer` per sales channel, `passkeyRpName`, `passkeyRpId`, with hostname/shop-name fallbacks).

**`Service/Provisioning/`**
- `AttributeMapper`, `Sw6OidcAttributeMappingDefinition`, `CustomerProvisioningService`, `AdminProvisioningService`, `GroupMappingResolver`, `UserProviderBindingService`, `CountryResolver`, `GenderMapper`, `MappedProfile`/`AddressProfile` DTOs.

**`Storefront/Controller/`**
- `SendAuthorizationRequestController` (`GET /sw6oidc/login`), `OidcCallbackController` (`GET /sw6oidc/callback`), `PasskeyController` (registration/login options+verify under `/sw6oidc/passkey/*`), `AccountPasskeyController` (`GET /account/passkey`, `POST /account/passkey/delete/{credentialId}`).
- `Storefront/Service/OidcCustomerLoginRoute` (`extends AbstractLoginRoute`) — a standalone passwordless login route, deliberately **not** a decorator (`getDecorated()` throws) so the real `AbstractLoginRoute` alias keeps doing normal password checks for everyone else; only ever invoked after OIDC/Passkey has already verified the customer for this request.

**`Controller/Api/`**
- `OidcAdminAuthController` — `login-options`, `login`, `callback`, `token` (nonce exchange) under `/api/sw6oidc/admin/*`; `auth_required: false` at the class level (all actions are necessarily pre-auth).
- `PasskeyAdminController` — registration/`my-credentials`/delete (auth required) plus `login-options`/`login-verify` (route-level `auth_required: false` override) under `/api/sw6oidc/admin/passkey/*`.

**`Migration/`**
- `Migration1730000001CreateOidcSchema` — creates the 5 tables below; `updateDestructive()` is a no-op.
- `Migration1758000001AddProviderTestStatus` — adds `sw6oidc_provider.last_test_status`/`last_test_at` (live login test result).
- `Migration1789383427AddSuperadminGroupMapping` — adds `sw6oidc_provider.allow_superadmin_group_mapping`.
- `Migration1789390512AddLastTestClaims` — adds `sw6oidc_provider.last_test_claims` (JSON; the flattened claims from the last live login test, so the Attribute Mapping picker's discovered-claims list survives a page reload).

**`Twig/`**
- `AdminEntrypointsExtension` — registers `sw6oidc_admin_scripts()`/`sw6oidc_admin_styles()`, reading the plugin's own Vite `entrypoints.json` directly (Pentatrion's helper only resolves Shopware's own pre-registered bundle name). Forces the plugin's admin JS to load on the pre-auth login screen, which Shopware's normal `loadPlugins()` boot path otherwise skips.
- `StorefrontLoginOptionsExtension` — registers `sw6oidc_storefront_sso_available(context)` / `sw6oidc_storefront_passkey_available(context)`, used by the storefront login template override to conditionally show the SSO/Passkey buttons.

**`Sw6Oidc.php`** — plugin bootstrap; no custom `install()`/`activate()`/`deactivate()`. `uninstall()` drops all 5 tables (FK-safe order) unless the admin checks "keep user data" in the uninstall dialog.

## Database schema (`Migration1730000001CreateOidcSchema` + follow-up migrations)

- **`sw6oidc_provider`** — one row per configured IdP: identity/OAuth fields (`app_name`, `client_id`, `client_secret`, `public_client`), endpoints (auto-fillable via discovery), protocol knobs (`scope`, `pkce_flow`, `claim_encoding`, `group_attribute`), behavior flags (`auto_create_customer`/`auto_create_admin`, `disable_non_oidc_*_login`, `show_*_link`, `is_active`, `login_type`), sync-on-SSO toggles (all five wired, see Provisioning & mapping above), `allow_superadmin_group_mapping` (gates `superadmin`-type role mapping rows, see Provisioning & mapping above), ops settings (`http_timeout`, `jwks_cache_ttl`), live-test bookkeeping (`last_test_status`, `last_test_at`, `last_test_claims`), and FK defaults (`default_customer_group_id`, `default_acl_role_id`).
- **`sw6oidc_attribute_mapping`** — per-provider claim → Shopware-field mapping (`attribute_type`, `attribute_name`, `sync_on_sso`, `transform_function`/`transform_params` — the last two are unused, see gaps below).
- **`sw6oidc_role_mapping`** — per-provider OIDC-group → ACL role / customer group / superadmin grant (`mapping_type`: `admin_role`|`customer_group`|`superadmin`, `oidc_group`, `acl_role_id`, `customer_group_id`, `sort_order`); `superadmin` rows leave both `acl_role_id`/`customer_group_id` null.
- **`sw6oidc_user_provider`** — permanent IdP binding, polymorphic (`user_type`, `user_id`) → `provider_id`, unique per account.
- **`sw6oidc_passkey_credential`** — one WebAuthn credential per user (polymorphic `user_type`/`user_id`, `credential_id`, `public_key`, `sign_count`, `user_handle`, `nickname`).

## Known gaps / implementation notes

Do not assume the following are fully wired just because the schema or config UI suggests they are:

- `transform_function`/`transform_params` columns on `sw6oidc_attribute_mapping` are schema-only — no code path reads or applies them.
- `config.xml`'s `debugLoggingEnabled` toggle is not wired to the Monolog channel; the actual log level is controlled solely by the `SW6OIDC_LOG_LEVEL` env var (default `debug`), set in `services.xml`.
- `client_secret` is stored **in plaintext** on `sw6oidc_provider` (the entity carries a `// TODO(later phase): encrypt at rest` comment) — unlike the Magento sibling module, which encrypts secrets at rest today.
- No OIDC Back-Channel Logout support; no admin-side RP-initiated logout (only the Storefront/customer logout path redirects to the IdP).
- `RedisAtomicCache`/`RedisConnectionFactory` exist in the codebase but are **not** wired into `services.xml` — the default `AtomicCacheInterface` alias is `CachePoolAtomicCache` (sequential get-then-delete on `cache.app`, single-node only). A multi-node deployment must override the alias in its own app-level `services.xml`.
- `ClaimsNormalizer::extractEmail()` and `UserProviderBindingService::unbind()` exist but have no caller in the current codebase.
- Test coverage is thin: only `tests/Unit/Service/Passkey/AdminPasskeyLoginTokenTrackerTest.php` and `PasskeyConfigTest.php` exist (both pure-logic unit tests). No integration tests, and no tests exercise the OIDC flow, provisioning, controllers, or WebAuthn ceremonies against a live Shopware instance.
- No `CHANGELOG.md` and no Docker/dev Shopware environment committed in this repo. (`LICENSE.txt` — MIT, matching `composer.json` — is present.)
- `OidcDiscoveryService` is exposed as a service but no controller action calling it was found in the read source — likely invoked from the admin Provider save screen, not confirmed.

## Tooling

- `phpstan.neon.dist` — level 5, scans `src` (excludes `src/Resources`), `treatPhpDocTypesAsCertain: false` (much of the code validates untrusted third-party data at runtime — IdP responses, WebAuthn JSON — against its own PHPDoc shapes).
- `psalm.xml` — `errorLevel="4"`, `findUnusedCode="false"`; explicit suppressions for `MissingOverrideAttribute` (PHP 8.2 predates `#[\Override]`), `UndefinedDocblockClass` (Shopware DAL generics stubs), `InternalMethod` (`Context::createDefaultContext()`, needed pre-auth), `UndefinedClass` (`\Redis`, optional ext-redis for the unwired `RedisAtomicCache`).
- `phpcs.xml.dist` — PSR12 base, relaxed line length (soft 180 / hard 200) for long route-attribute/constructor-promotion lines.
- `rector.php` — `withPhpSets()` (auto-detects PHP 8.2 floor from `composer.json`), `deadCode`/`codeQuality`/`typeDeclarations`/`earlyReturn` sets.
- CI (`.github/workflows/ci.yml`) — 4 parallel jobs on push/PR to `main`: `lint` (PHPCS), `static-analysis` (PHPStan + Psalm), `rector` (dry-run, fails if changes remain), `tests` (PHPUnit matrix across PHP 8.2/8.3/8.4/8.5, coverage uploaded per version).

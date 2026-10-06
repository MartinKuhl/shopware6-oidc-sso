# Code Review: `martinkuhl/shopware6-oidc-sso`

| | |
|---|---|
| **Revision** | 6: open findings only. Every finding fixed and verified up to this revision has been removed from this file. |
| **Review date** | 2026-10-06 (revision 5: 2026-10-06 fix round, revision 4: 2026-10-05, revision 3: 2026-10-01, revision 2: 2026-09-30, revision 1: 2026-09-29) |
| **Reviewed commit** | `f635fb4` on `fix/code-review-rev2` |
| **Scope** | Everything under `src/`: PHP, DI config, migrations, admin and storefront JS and Twig. Also `composer.json`, CI and tooling config, the committed bundles. |
| **Verified against** | Shopware 6.7 core and administration, Symfony 7.4, League OAuth2 9.4, DBAL 4, webauthn-lib 5.3 (`/var/www/html/vendor` plus a fresh `composer install` of the plugin) |
| **Reviewer stance** | Harsh on purpose. Assume every finding reaches production unless it is fixed. |

Backend paths are relative to `src/`. Frontend paths are relative to `src/Resources/`. Admin JS paths start at `app/administration/src/`.

**Line numbers:** finding IDs and file names are stable. Line numbers in the R3 rows come from revision 3 (`88a19fc`), and many of those files changed in the fix round, so **search by the symbol named in the row**. R4 and R6 rows point to `f635fb4`.

The way to fix what is left is in `Code-Review_implementation_plan.md` (phases Q0–Q10).

---

## Summary

### Verdict

**Every Critical, High and Medium finding is fixed and verified. What is left is Low.** The release gate is not met yet, for two reasons:
1. **The test gate is still not real** (R3-L51, R4-L7).
   - `assets` and `e2e` are still `continue-on-error: true` (`.github/workflows/ci.yml:200, 270`).
   - The integration and E2E suites were **not run** for the fix round, and no admin smoke spec exists.
   - The fix round changed the identity key, the SSO-only invariant, session liveness and the admin token path, and none of that has been exercised against a real shop plus Dex. Until it has, the "fixed" status of the Highs rests on unit tests and scripted DB checks only.
2. **Two follow-up defects of the fix round** are open:
   - **R6-L1:** the SSO-only confirmation and the password-session revocation ignore the issuer, so they disagree with the invariant itself.
   - **R6-L2:** the ACL registration gives up silently after 5 s.

   Both are Low, but they sit in security-relevant paths.

### What was verified for this revision

I checked every fix claimed by the revision 5 status table against the code at `f635fb4`. Each one listed below is fixed as described.

| Commit | Verified fixed (removed from this file) |
|---|---|
| `b020e3e` | **R3-H1**: fresh PSR request in `AdminTokenIssuer::issue()`; the client's request is never converted. **R3-F1**: `diagnostics?.infrastructure`. **R3-F4**: `me?.data?.username`. **R3-F5**: no 401 from the anonymous/redeem endpoints. **R3-F6**. Unused: the `AdminTokenIssuer` request mutation. |
| `f3487b0` | **R3-H2** and **R3-H3**: `WriteProtected(SYSTEM_SCOPE)` plus `TrustEntityWriteGuardSubscriber`. **R3-H4**: `ProviderTrustGuardSubscriber`, superadmin only. **R3-H7**: `SsoOnlyInvariant`, checked on the result of every relevant write. **R3-M4**: stored `public_client`, `ResetOnClone` on the secret. **R3-M21**: guests excluded, batches of 500, message queue. **R3-M22**: atomic store keyed by provider plus admin, `user-verified`. **R3-M23**: `Required` dropped, nullable column. **R3-F2**: deferred registration, snippets, `force_logout` under `additional_permissions`. Hygiene row `emits`. |
| `116e22b` | **R3-H6** and **R3-M5**: `SessionAuthenticationClock`, `AuthTimeValidator`. **R3-M6**: `PasskeySessionTerminator` plus `PasskeyDeletionSubscriber`. **R3-M7**: ceremony purpose. **R3-M8**: `passkeyRpIdAdmin` plus validator. **R3-F3**: one dialog per row. `AdminPasskeyLoginTokenTracker` removed. |
| `ece912e` | **R3-H5**: registry liveness from `refresh_token` / `sales_channel_api_context`. **R3-M1**: `MAX_KID_LENGTH`, endpoint-only logout cooldown, `isBlocked()` before verifying. **R3-M17**: batched prune, hourly task, `rel="nofollow"`. **R3-M18**: `setex()` result checked, `getLastError()`. **R3-M19**: destroy before remove, jti marker deleted on failure. |
| `8021261` | **R3-M2**: `max_redirects: 0` on the outer client. **R3-M3**: `logout_style`, `client_id` always sent. **R3-M15**: save-time refusal (`SW6OIDC_EMAIL_TRANSFORM_UNVERIFIABLE`). **R3-M20**: `PasswordLoginGuardClientRepository` decorating core's `ClientRepository`. **R3-M24**: merged-row validation. |
| `08352cb` | **R3-M9** (`issuer_hash`, `utf8mb4_bin`), **R3-M10**, **R3-M11** (`AdminRoleStore`, `FOR UPDATE`), **R3-M12** (create plus bind in one transaction, re-resolve), **R3-M13**, **R3-M14** (`binding_scope`), **R3-M16** (`PlaceholderAddressSubscriber`). **R3-L23**. Unused: `PLACEHOLDER_ADDRESS_FIELD` and `UserProvider.issuer` are now read. |
| `f8c1195` | Issuer-aware `SsoOnlyInvariant::adminAccessPossible()` (except R6-L1). Docs and changelog. |

The status tables of revisions 3–5, the revision 2 re-verification and every fixed finding text are in git history: `git show 6a52dce:Code-Review.md` (revision 4 text) and `git show f8c1195:Code-Review.md` (with the revision 5 table).

**Partly fixed, so kept below with the remaining part only:** R3-L24 (no email in messages anymore; customer denials are still counted), R3-L43 (`user_type`/`mapping_type` `Choice` and the binding `UpdatedAtField` are done), R3-F17 (storefront dialog `aria-labelledby` is done), R4-L7 (`AdminTokenIssuerTest` with a real League server is done).

### Numbers

| | Critical | High | Medium | Low |
|---|---|---|---|---|
| Open, backend | 0 | 0 | 0 | 51 R3 Lows (2 of them partial) + 7 R4 + 1 R6 |
| Open, frontend | 0 | 0 | 0 | 11 R3-F Lows (1 partial) + 2 hygiene rows + 1 R6 |
| Unused-code rows open | | | | 15 |
| Fixed since revision 4 (removed) | 0 | 10 | 26 | R3-L23, R3-F6, 1 hygiene row, 3 unused-code rows |

### Tooling (run on a clean `git archive` of `f635fb4`)

| Tool | Result |
|---|---|
| PHPUnit (unit) | **OK**, 795 tests, 2006 assertions (699 at revision 4) |
| PHPCS | clean |
| PHPStan level 5 (configured) | no errors |
| PHPStan level 8 (not configured) | **52 errors**: 31 `missingType.generics`, 8 `argument.type`, 8 `missingType.iterableValue`, 3 `cast.string`, 1 `method.nonObject` (`Sw6Oidc.php`), 1 `argument.templateType`. |
| Psalm (errorLevel 4) | no errors |
| Rector (dry-run) | clean |
| Integration / E2E | **not run.** They need a dedicated shop plus Dex. This is the main open risk (see the verdict). |

---

## Revision 6: new findings

| # | Severity | Where | What's wrong | Fix |
|---|---|---|---|---|
| R6-L1 | Low | `Service/Security/SsoOnlyInvariant.php` `unboundActiveAdminIds()` | `adminAccessPossible()` only counts bindings whose `issuer_hash` matches the provider's current issuer (`f8c1195`). `unboundActiveAdminIds()` still counts **any** binding to a serving provider. After an issuer change that left bindings disconnected, those admins count as "bound" here but as "unbound" in the invariant. The result: the lockout confirmation (`SW6OIDC_LOCKOUT_UNBOUND_USERS`) isn't asked for them, and `PasswordSessionRevoker` doesn't end their password sessions when SSO-only mode turns on. They then hold a live password session the policy should have ended. | Apply the same `issuer_hash` filter as `adminAccessPossible()`, and share the subquery between both methods. Test: a provider whose issuer changed with "disconnect", then SSO-only turned on → the admin is in the unbound list, and their sessions are revoked. |
| R6-L2 | Low | `app/administration/src/acl/index.js` `registerWhenReady()` | It gives up after 100 × 50 ms = **5 s** and only logs `console.error`. On a slow connection the `main` chunk that registers `privileges` can take longer, so the plugin's privileges are again missing from the role editor (the R3-F2 symptom), silently and only sometimes. There's also a duplicated, unreachable `return;` in the success branch. | Wait for core's ready signal instead of polling (the same mechanism `defer-module-register.js` uses), or poll without a hard limit. Remove the dead `return;`. |

---

## Open findings

### Frontend

| # | Where | Issue | Fix |
|---|---|---|---|
| R3-F7 | `extension/sw-profile/page/sw6oidc-profile-passkey/…html.twig` | `mt-label` isn't a registered component in 6.7.14, so the "disabled" badge renders as plain text. | Use `mt-badge`. |
| R3-F8 | `extension/sw-profile-index-general/…html.twig` | In 6.7.14, `sw_profile_index_general_image` sits inside the info card's grid, so the plugin's `mt-card` lands in a grid cell. | Extend `sw_profile_index_general_information` with `{% parent %}` plus a sibling card. |
| R3-F9 | `extension/sw-verify-user-modal/index.js` (`sw6oidcStepUpWithSso`) | If the admin closes the IdP popup, no message arrives and `sw6oidcStepUpBusy` stays true, leaving both buttons disabled. The popup isn't closed on unmount. Backend side: step-up pipeline errors take the normal login-redirect path, so no `postMessage` is sent (`OidcAdminAuthController::callback`). | Poll `popup.closed`. Always render the `postMessage` page for `step_up` flows, `{error}` included. |
| R3-F10 | `extension/sw-login/sw-login.html.twig` | Password-disabled mode hides the whole form, including "Keep me logged in", so SSO logins always run with `rememberMe = false` (F-M1 gap). The comment "no narrower block" is wrong: `sw_login_login_user_field`, `_password_field` and `_submit` exist. | Override the field and submit blocks only. |
| R3-F11 | `provider-list/index.js`, `passkey-list/index.js` | The listing mixin's `created()` plus the component's own call fetch twice. In the passkey list, `ownerNames` is written before the request-id check. | Drop the own `created()` call, and move the write behind the check. |
| R3-F12 | `provider-detail/index.js` `loadEntity()` / `loadFormContext()` (F-M10 residue) | No request counter, so fast A→B navigation can apply A's data to B. | Add a request id. |
| R3-F13 | sessions list `index.js` (F-N3 residue) | "Only active" still returns rows labelled expired, and "Force logout" stays enabled on them. | Filter them out, and disable the action. |
| R3-F14 | provider detail `<fieldset :disabled>` (F-N8 residue) | It only disables native inputs. Selects, tag fields and grid context menus (rendered in popovers) stay interactive for viewers. Saving is blocked, so the impact is cosmetic. Since R3-H4 the same applies to non-superadmin editors and the trust fields (plan F-2). | Pass `:disabled` to each component. |
| R3-F15 | passkey-list (`variant="danger"`), sessions-list (`success`) | Probably not Meteor `mt-badge` variants (`critical` / `positive`). | Use the Meteor names. |
| R3-F16 | provider detail `atomicStore.${…}`, `infrastructureWarning.${…}`, `testMessage.${…}` | Snippet lookups without a fallback, so a new backend code shows a raw key. | Use `snippetOr`. |
| R3-F17 (partial) | admin error containers | No `role="alert"`/`aria-live` on any admin error container (0 in the templates). The storefront dialog part is fixed. | Add them. |
| Hygiene 1 | provider detail and other templates | 76 deprecated `sw-*` form, card and button components (`@deprecated tag:v6.8.0`) and 240 `$tc(` calls are still there. Decision: migrate everything now. | Migrate to `mt-*` and `$t` (plan Q7). Add a CI grep that blocks new uses. |
| Hygiene 2 | `component/sw6oidc-rp-id-field/index.js`; `views/administration/index.html.twig` | Both RP ID fields (`passkeyRpId` per channel, `passkeyRpIdAdmin`) show the admin host as placeholder. Three "VERIFICATION NEEDED" comments shipped. | A `scope` prop with the right placeholder per field; remove the comments (plan F-5). |

### Backend: OIDC core, HTTP, security helpers

| # | Where | Issue | Fix |
|---|---|---|---|
| R3-L1 | `Service/Provider/ProviderResolver.php` `getActiveById()` | `?providerId=` (empty) or a non-UUID reaches `new Criteria([$id])`, which throws `InvalidCriteriaIdsException` or `InvalidUuidException`. That is a 500 or JSON error page instead of the "provider unavailable" flash or redirect on storefront login, link, admin login and step-up. | Check `Uuid::isValid()` first and throw `ProviderNotFoundException`. |
| R3-L2 | `Service/Security/RelayStateValidator.php` | No `D` modifier, so `$` matches before a trailing `\n`. `redirectTo=/foo%0A` passes, then `header()` rejects the `Location` and the user gets a 302 with no target. | Use `'#^/(?![/\\\\])[^\x00-\x20\x7f\\\\]*$#D'`. |
| R3-L3 | `Service/Security/BrowserBinding.php` `bindCurrentBrowser()` | A reused cookie is never re-issued, so its 30-minute lifetime isn't extended. A retry 28 minutes after an earlier attempt, plus 2 minutes of MFA, fails as "different browser" and counts against the rate limit. | Re-issue the cookie on every flow start. |
| R3-L4 | `Service/Oidc/OidcLiveLoginTestService.php` | The live test passes a provider with no `sub`, while every real login fails (L5 parity gap). | Move the `sub`-required check into `ClaimsMerger`. |
| R3-L5 | `OidcSecurityHelper.php:22` (`?BrowserBinding = null`), `OidcLiveLoginTestService` | Optional dependencies exist only for tests. A wiring mistake silently disables M1. | Make them required, and use test doubles. |
| R3-L6 | `ClaimsNormalizer` `decodeGroups()` vs `flattenRecursive()`, `Sw6OidcAccessControlEvaluator::members()` | With `base64_claims` on an object-shaped group claim, group mapping sees decoded keys while access rules see encoded ones. | Decode object keys in `flattenRecursive`, or document the limitation. |
| R3-L7 | `JwtVerifier::verify()` | `$expectedNonce` is nullable, and the "nonce skipped" branch fails open. Both callers always pass a string. | Make it `string` and delete the branch. |
| R3-L8 | `BackChannelLogoutController` | The global `backchannel_logout` failure budget is written (unresolvable tokens) but never read by `isBlocked()`; only the per-provider budget is read. | Read it, or delete it. |
| R3-L9 | `FrontChannelLogoutController` | `isBlocked()` runs first, so ten malformed requests from one office NAT drop every real front-channel logout from that IP for 60 s. | Don't let the malformed budget block well-formed `iss`/`sid` requests. |
| R3-L10 | **16** domain exceptions under `Service/*/Exception/` (all except `PasswordLoginDisabledException`) | Plain `\RuntimeException`. The Shopware ≥6.5 convention is `HttpException` with error codes, so anything that escapes becomes an anonymous 500, and API clients get no stable error code. | Extend `HttpException` (404/400/403 with `SW6OIDC_*` codes), with static factories in one `Sw6OidcException` class, as core does (`CustomerException::…`). |
| R3-L11 | `OidcCallbackProcessor`, `ClaimsNormalizer`, `RpInitiatedLogoutService` | Two consecutive docblocks per method. Only the last attaches, so `@throws`/`@param` in the first are invisible to PHPStan, Psalm and IDEs. | Merge them. |
| R3-L12 | `OidcProviderAdminController` (`build($provider, 'test', $locale, …)`, `loginType !== 'test'`), `AuthorizationFlowContext` | Magic `'test'` login type outside the `LoginType` enum. The live test smuggles the locale through `relayState`. | Use an enum case and a typed field. |
| R4-L1 | `Service/Jwt/JwtVerifier.php` `verifySignedPayload()` | The `crit` header is never inspected. RFC 7515 §4.1.11 says a token whose `crit` lists an extension the verifier doesn't understand **must** be rejected. | Reject any token whose protected header has `crit`. |
| R4-L2 | `JwtVerifier.php:309` | `nbf` is cast with `(int)` without an `is_numeric()` check, while `exp` and `iat` have one. A non-numeric `nbf` becomes `0` and passes. | Treat a present non-numeric `nbf` as invalid. |
| R4-L3 | `JwtVerifier.php:182` (`AlgorithmManager`), `candidateKeys()` | Only `RS256`/`RS384`/`RS512` with `kty=RSA`. IdPs configured for `ES256` or `PS256` (Keycloak, Authentik, Okta custom servers, Zitadel) fail at the **first login** with a generic error. Neither discovery (`id_token_signing_alg_values_supported`) nor the connection test warns. | Add `ES256/384` and `PS256`. Until then, have discovery and the connection test fail clearly when the IdP advertises no RS\* algorithm. |
| R4-L4 | `Storefront/Controller/OidcCallbackController.php:86`, `Controller/Api/OidcAdminAuthController.php:225` | Both callbacks log the query parameters `error` and `error_description` at **warning** level, verbatim and uncapped, *before* any state check, and never call `recordFailure()`. Anyone can loop `GET /sw6oidc/callback?error=x&error_description=<8 KB>` and fill `var/log/sw6oidc-*.log` without being rate limited. | Truncate both (for example to 200 chars), log at `notice`, and count the branch as a failure when `state` is unknown. |

### Backend: passkeys

| # | Where | Issue | Fix |
|---|---|---|---|
| R3-L13 | `Storefront/Controller/PasskeyController` registration options; `PasskeyRegistrationService` | `registration-options` is not rate limited (only login options consume a budget). Each call stores a 300 s ceremony and deserializes every key. There is no cap on credentials per account. | Use a `consume()` budget per customer. Cap at about 20 keys. |
| R3-L14 | `PasskeyController` ceremony POSTs | Login CSRF: Shopware 6.7 has no CSRF token, and SameSite=Lax doesn't stop a top-level POST. An attacker signs an assertion with their own software authenticator and logs the victim into the attacker's account. | Require `Origin` / `Sec-Fetch-Site: same-origin` on the four ceremony POSTs. |
| R3-L15 | `PasskeyCredentialRepository::updateAfterAssertion()` | The counter update is read-check-write with an unconditional write. Concurrent assertions can lower the counter. The entity is loaded three times per login. | Use `UPDATE … WHERE sign_count < :new`, and pass the entity along. |
| R3-L16 | `PasskeyAdminController` (`my-credentials`, `delete`), `AccountPasskeyController` | Integration tokens (`userId = null`) and non-hex ids cause a 500. | Return 403 for non-user sources, and check `Uuid::isValid()`. |
| R3-L17 | both `webauthn-codec.js` | `response.getTransports()` is dropped, so step-up's `allowCredentials` has no transport hints and browsers show a worse picker. | Send `transports` and `clientExtensionResults`. |
| R3-L18 | `views/storefront/page/account/passkey/index.html.twig` | Keys disabled by clone detection look normal on the storefront. The page is one big block. | Add a badge and sub-blocks. |
| R3-L19 | `PasskeyRelyingPartyResolver` (`fetchFirstColumn` domain query); `passkey-list/index.js` | A raw DBAL domain query when the context already has the domains loaded. The full `publicKey` JSON is fetched per grid row. | Use the context, and `criteria.addIncludes`. |

### Backend: identity and provisioning

| # | Where | Issue | Fix |
|---|---|---|---|
| R3-L20 | `AttributeMapper` email validation | `FILTER_VALIDATE_EMAIL` without the Unicode flag rejects `user@bücher.de`. Core stores punycode (`EmailIdnConverter`), so lookups wouldn't match anyway. | Apply `EmailIdnConverter::encode()` before validating and looking up. |
| R3-L21 | `CustomerProvisioningService`, `AdminProvisioningService` create and sync payloads | No length caps (only the fallback name is truncated). A `given_name` longer than the DAL limit causes a `WriteException`, and with sync on, every login of that user fails. | Truncate with `mb_substr` to the field limits. |
| R3-L22 | `AvatarFetcher` | `timeout` is an idle timeout, so a slow-drip server stalls admin login. The MIME type comes from the header, and the file becomes public media. | Add `max_duration: 10`, and sniff the bytes (`getimagesizefromstring`). |
| R3-L24 (partial) | `OidcCallbackController::callback()` catch blocks | `CustomerProvisioningDeniedException` (auto-create off, or the bound customer not available in this channel) still lands in the generic `\Throwable` branch and counts against the callback budget. Ten denied users behind one NAT block SSO for that IP for 60 s. The email part is fixed. | Catch it with the other policy denials (not counted), with its own flash message. |
| R3-L25 | `AdminProvisioningService` username dedup | Unbounded username probe: one query per suffix. | Use a single `LIKE` query, or a random suffix after three tries. |
| R3-L26 | `CustomerProvisioningService::findByEmail()` | The email lookup has no sort and ignores `active`. Core sorts by `createdAt DESC`. | Sort like core, and skip inactive accounts. |
| R3-L27 | `OidcCallbackController::callback()` | If `register()` or `recordLogin()` throws after the context swap, the user is logged in but sees "login failed", a failure is counted, and the session is missing from the registry. | Register before swapping, or swallow and log registry errors. |
| R3-L28 | `OidcAdminAuthController::callback()` (H1 residue) | For an inactive admin, the callback still syncs, writes `rememberForAdmin`, registers a pending session and mints a nonce. Only the token exchange fails. | Check `active` before any side effect. |
| R3-L29 | `CustomerProvisioningService` / `AdminProvisioningService`; `OidcCustomerLoginRoute` | Find-by-email, reload-after-create, fallback names and partial-sync payloads are duplicated. `loginByCustomerId()` reloads a customer the caller already holds. Sync issues an `update` on every login even when nothing changed, which fires `*.written`, indexers and the cleanup subscriber. | Extract a shared resolution step. Pass the entity in. Skip no-op updates. |
| R4-L5 | `Storefront/Service/OidcCustomerLoginRoute.php` | `loginByCustomerId()` reimplements core's public `AccountService::loginById()`, and the copy has drifted. Every core change to the login sequence has to be ported by hand. | Call `AccountService::loginById()`, keep `CustomerSalesChannelBinding::allows()` as a pre-check, and delete the class (see Unused code). |

### Backend: state, sessions, logout, health, migrations

| # | Where | Issue | Fix |
|---|---|---|---|
| R3-L30 | `Sw6OidcSessionDestructionService::destroyCustomerSession()` | After core rotates a customer context token (for example on a password change), the registry holds the old one, `delete()` hits 0 rows, and the new token survives. | On 0 rows, fall back to `revokeAllCustomerTokens()`. |
| R3-L31 | `Sw6OidcSessionActivityRecorder` (`setLimit(200)`) | Matching loads up to 200 open rows into PHP and gives up beyond that. The `session_key_hash` and `registry_session_id` indexes are never used. | Query with an OR filter, and close rows with one DBAL `UPDATE`. |
| R3-L32 | `HealthCheckAlertTaskHandler`, `NodeHeartbeat` | The worker container records its own heartbeat, so one web node plus one worker shows `multi_node_without_redis` permanently. Rolling deploys trigger it too. | Record heartbeats from web requests only. |
| R3-L33 | `RpInitiatedLogoutService::revokeTokens()` | "Fire-and-forget" revocation is two synchronous calls with the provider's `http_timeout` (default 30 s), so logout can hang 60 s while the IdP is down. | Use a short fixed timeout, or an async message. |
| R3-L34 | `Migration1790800006CreateStateTables` | `sw6oidc_session` has no FK to the provider. After a provider is deleted, encrypted IdP tokens stay until the hard bound (90 days since R3-H5). | Add `ON DELETE CASCADE`, or a deleted-event listener. |
| R3-L35 | `RedisAtomicCache` | Values (PKCE verifiers, user ids) are stored in Redis in plaintext, while the DB store encrypts them. | Encrypt in both. |
| R3-L36 | `HealthCheckController` | Without `SW6OIDC_HEALTH_TOKEN` (the default), anyone sees IdP failures, counts and infrastructure warnings. | Without a token, return `status` only. |
| R3-L37 | `SessionActivityController::forceLogout()` | Any role with `force_logout` can repeatedly end all of a superadmin's sessions. | Refuse admin targets unless the caller is an admin. |
| R3-L38 | `Migration1758000001…`, `Migration1789383427…`, `Migration1789390512…` | `ADD COLUMN` with no existence check, unlike the later migrations, so a restore with a stale `migration` table fails. | Add the `SHOW COLUMNS` guard. |
| R3-L39 | `RedisConnectionFactory` (APCu marker) | The 30 s "Redis is down" marker only works with APCu. Without APCu, every request stalls 1.5 s during an outage (N-L14 residue). | Fall back to a static or file marker. |
| R3-L40 | `sw6oidc_session_activity.sid` | `sid` (a logout capability, N-M5) is stored in clear and never read. | Drop the column, or store a hash. |

### Backend: admin auth, DAL, config, logging, tooling

| # | Where | Issue | Fix |
|---|---|---|---|
| R3-L41 | `StepUpController::startOidc`/`passkeyOptions`, `OidcAdminAuthController::startLink` | Authenticated flow starts write one-time-token rows with no `flow_start` budget. | `consume(SCOPE_FLOW_START.':'.$userId, ip)`. |
| R3-L42 | `Sw6OidcEncryptor::decrypt()` via `Sw6OidcEncryptedFieldSerializer::decode()` | After an `APP_SECRET` change, every provider hydration logs two errors: on every login render, every password attempt and every admin listing. | Log once at the point of use (`getUsableClientSecret`), or decrypt lazily. |
| R3-L43 (partial) | `Sw6OidcProviderDefinition` | No `Choice` on `pkce_flow` (`s256` breaks every login) or `login_type`. `claim_encoding` is `Required` but dead. `http_timeout` (SMALLINT) and `jwks_cache_ttl` have no bounds (40000 gives a DB 500, a negative value disables the cache). NOT NULL booleans lack `Required` (null gives a 500). Provider delete cascades bindings at the DB level with no DAL event or warning. | Add `Choice`/`Range`/`Required`. Drop the dead field. Add a delete confirmation (plan decision Q7). |
| R3-L44 | `OidcProviderAdminController` (`(int) $request->request->get('httpTimeout', 10)`), `OidcConnectionTestService` | `httpTimeout` is uncapped in the test endpoints. A non-scalar JSON value causes a 500. The test decrypts the secret only to check that it is non-empty. | Clamp 1–30 s, validate input types, and pass a bool. |
| R3-L45 | `Service/Logging/SensitiveDataProcessor.php` | Does not mask: a leading `code=`, JSON bodies, `Bearer`/`Basic` headers, URL-encoded URLs, `;`-separated params, keys like `contextToken`, `sessionKey`, `jwt`, `cookie`, `apiKey`, non-string values, or exception objects. Current callers avoid these cases, so it is latent. | Add the patterns and keys. |
| R3-L46 | `services.xml` | Twig extensions are eager (`twig.extension`, no `twig.runtime`): the login-options extension builds the passkey and webauthn graph on every Twig boot, mail rendering included. The write guards on the global `PreWriteValidationEvent` aren't `lazy`. `OidcLogger` is a fake FQCN service id. Encryption purpose strings are duplicated as literals. | Use `twig.runtime` and `lazy`. |
| R3-L47 | `Twig/StorefrontLoginOptionsExtension.php` | Three un-memoised lookups (each decrypting two envelopes per provider) on every login render. | Memoise per request. |
| R3-L48 | `Service/Oidc/TestResultTranslator.php` | Reads the **admin source** snippet JSON at runtime, so a package without `Resources/app` shows raw keys. | Ship Symfony translations. |
| R3-L49 | `services.xml`; `AdminOidcGrant`; `PasswordLoginGuardClientRepository` | Relies on core internals: `FakeCryptKey` (`@internal final`); `ClientRepository`, `AccessTokenRepository`, `ScopeRepository`, `RefreshTokenRepository`, `UserRepository` and `OAuth\User\User` (`#[BecomesInternal('v6.8.0')]`); `ScopeRepository::PASSWORD_GRANT`; and `Context::createDefaultContext()` in subscribers and controllers. The fix round added another decorator of a `BecomesInternal` class. | Track these for 6.8. Concentrate them in one bridge class, inject interfaces, and pass `Context` down from the callers. |
| R3-L50 | `OidcAdminAuthController::accessTokenJti()` / `PasskeyAdminController::accessTokenJti()`; `ERROR_CODE` duplicates; `'admin'` literals | Duplicated `accessTokenJti()`, `ERROR_CODE` and user-type constants. | Share them (`AdminTokenIssuer`, `LoginType::Admin->value`). |
| R3-L51 | `composer.json` (`"block": false`); `.github/workflows/ci.yml:200, 270` | `policy.advisories.block: false`. Actions pinned by tag, not SHA, with no `permissions:` block. `assets` and `e2e` are `continue-on-error`. Static analysis runs on 8.3 only, at PHPStan level 5, with no Shopware extension and no analysis of `tests/`. | Make both jobs blocking. Pin SHAs. Add `permissions: contents: read`. Raise analysis levels (52 errors at level 8 today). |
| R3-L52 | `CLAUDE.md` | Still partly out of date after `f8c1195`: "19 attribute types" (the code has 22); "Unit tests (699 tests)" (795); `findOneByCredentialId()` described as live (tests only); the `sw6oidc_user_provider` row in "Database schema" still says one account per `(provider, user_type, sub)`; "the passkey list links to the owner profile" (it doesn't). | Update the file. |
| R4-L6 | `composer.json` `require` | Requires `league/oauth2-server`, `symfony/psr-http-message-bridge` and `nyholm/psr7` directly, although all three are `shopware/core` dependencies. Pulls the whole `web-token/jwt-framework` where `web-token/jwt-library` is enough, with a wide `^3.4 \|\| ^4.0` range. Composer-only distribution (decision Q10), so dropping them is safe. | Drop the core-provided packages. Depend on `web-token/jwt-library` with one major version. |
| R4-L7 (partial) | `tests/E2E/specs`, `.github/workflows/ci.yml` | `AdminTokenIssuerTest` with a real League server is done. Still missing: the admin smoke spec ("open every plugin page, zero console errors", as superadmin and as a viewer role), and blocking `e2e`/`assets`. The integration and E2E suites were never run against the fix round. | Plan Q0. |

---

## Unused code

| Symbol | Where | Evidence |
|---|---|---|
| `SsrfUrlValidator::isInsecureModeEnabled()` | `Service/Security/SsrfUrlValidator.php:34` | No caller in `src/` or `tests/`. **Delete it.** |
| `Sw6OidcProviderEntity::getClaimEncoding()` and the `claim_encoding` field | entity / definition `:103` | Never read since M10, but still `Required`, so every client must send a dead field. The admin JS still sets `provider.claimEncoding = 'none'` (`provider-detail/index.js:410`). |
| `PasskeyCredentialRepository::findOneByCredentialId()` | `:68` | Called from tests only. |
| `UserProviderBindingService::assertNotBoundToDifferentProvider()` | `:93` | Called from tests only. |
| `Sw6OidcSessionRegistry::resolveByUser()` | `:137` | Called from tests only. |
| `OidcCustomerLoginRoute::login(RequestDataBag)` + `AbstractLoginRoute` inheritance + `getDecorated()` | `Storefront/Service/OidcCustomerLoginRoute.php` | No caller. It is also a hazard: a passwordless "log in as any `customerId`" entry point. Goes away with R4-L5. |
| `AdminLoginNonce::$browserBinding` | `Service/AdminAuth/AdminLoginNonce.php` | Checked before the DTO is built and never read afterwards. |
| `sign_count` column, `Sw6OidcPasskeyCredentialEntity::getSignCount()` | definition `:61`, entity `:68` | Written, never read. The real counter lives in the `public_key` JSON, so this is a drifting copy. |
| `PasskeyRegisteredEvent::getUserType()` / `getUserId()` | `Event/` | No callers (public API, acceptable; keep). |
| `Sw6OidcSession::$createdAt`, `$salesChannelId`; the registry's `$customerTtlSeconds` constructor parameter | `Service/Session/` | Re-check after R3-H5: liveness no longer uses a customer TTL; delete what is unread. |
| `sw6oidc_session_activity` indexes `session_key_hash`, `registry_session_id` | `Migration1790800003…` | No query uses them (R3-L31). |
| Constructor defaults `'PT10M'` (`AdminAuthorizationServerFactory`), `'P1W'` (`AdminOidcGrant`) | | DI always injects real values. The defaults only hide misconfiguration. |
| Public-but-internal methods | `OidcSecurityHelper::deriveCodeChallenge`, `OidcCallbackResult::subject`, `RpInitiatedLogoutService::revokeToken`, `SsrfUrlValidator::isPublicIp`, `Sw6OidcRateLimiter::clientKey`, `destroyCustomerSession`, `destroyAdminSessions` | Make them `private` (check new callers from the fix round first). |
| Admin snippets `sw6oidc.login.{error, errorRoleMissing, errorAutoCreateDisabled, passwordLoginDisabled, errorAccessDenied, errorLinkRequired, errorEmailNotVerified, errorProviderMismatch}`; storefront snippet `sw6oidc.passkey.reauthRequired` | `snippet/*` | Duplicates of `sw-login.sw6oidc.login.*`, or replaced by `sw6oidc.account.reauthRequired`. Unused. |
| Stale comments | `GenderMapper` ("SalutationResolver" doesn't exist), `attributeTypeColumnWidth`, `sw-login` ("no narrower block"), `sw-profile-index-general` ("image card"), the "VERIFICATION NEEDED" blocks, the `DatabaseAtomicCache` docblock (still mentions id_tokens) | Delete or correct them. |

---

## Edge cases that still break the plugin

| Input or situation | Result |
|---|---|
| `GET /sw6oidc/login?providerId=` or a non-UUID | 500 instead of a flash message (R3-L1) |
| `redirectTo=/foo%0A` | Redirect without a `Location` header (R3-L2) |
| `given_name` of 300 characters with profile sync on | Every login of that user fails (R3-L21) |
| IdP that signs id_tokens with `ES256` or `PS256` | Discovery and the connection test pass, then every login fails with a generic error (R4-L3) |
| `GET /sw6oidc/callback?error=x&error_description=<8 KB>` in a loop | Unbounded warning-level log lines, never rate limited (R4-L4) |
| Ten customers without an account behind one NAT, auto-create off | SSO blocked for that IP for 60 s (R3-L24) |
| Provider `pkceFlow: "s256"` saved through the API | Every login of that provider fails (R3-L43) |
| Issuer changed with "disconnect", then SSO-only turned on | Those admins keep their password sessions; no confirmation asked (R6-L1) |
| Admin opens the role editor on a slow connection | Plugin privileges missing, only a console error (R6-L2) |

---

## Future improvements (still open)

Items 1–8, 12, 13, 16 and 17 of the revision 4 roadmap are done or are covered by open findings above. What remains is optional and comes after release:
- **9 Passkeys:** conditional mediation (`autocomplete="username webauthn"`), a `PasskeyCredentialDeletedEvent`, a clone-detection Flow Builder trigger, one shared ceremony service for admin and storefront.
- **10 Bounded HTTP layer:** response-size caps on token, userinfo and JWKS responses; keep `error`/`error_description` from 4xx responses for diagnostics; separate HTTP clients for the IdP and for user content (`picture` URLs).
- **11 Protocol defaults:** enforce `S256` PKCE unless discovery lacks it; check `typ: logout+jwt` when the IdP sets it.
- **14 Configuration hygiene:** a `Configuration` tree for the `SW6OIDC_*` env vars; autowiring (rev-2 L13, deferred).
- **15 Frontend architecture:** a small pre-auth bundle for `sw-login` and the inactivity modal (also closes the rev-2 partials F-M2 and F-M14); show the `PublicError` correlation reference in toasts.

## Suggested fix order

1. **Test gate** (R4-L7, R3-L51): admin smoke spec, run the integration and E2E suites against the fix round, make `e2e`/`assets` blocking. Fix whatever they find first.
2. **R6-L1, R6-L2**: the follow-ups in security-relevant paths.
3. The rest in plan order Q2–Q9 (`Code-Review_implementation_plan.md`).

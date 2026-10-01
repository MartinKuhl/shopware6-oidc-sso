# Code Review: `martinkuhl/shopware6-oidc-sso`

| | |
|---|---|
| **Revision** | 3 (re-review of the rev-2 fix branch) |
| **Review date** | 2026-10-01 (revision 2: 2026-09-30, revision 1: 2026-09-29) |
| **Reviewed commit** | `88a19fc` on `fix/code-review-rev2` (revision 2 reviewed `6986e4f`; +9.2k / −3.0k lines in `src/` since) |
| **Scope** | Everything under `src/`: PHP, DI config, migrations, admin and storefront JS and Twig. Also `composer.json`, CI and tooling config, the committed bundles. |
| **Verified against** | Shopware 6.7 core, Symfony 7.4, League OAuth2 9.4, DBAL 4, webauthn-lib 5.3.9, as installed in `/var/www/html/vendor` and in the plugin's own `vendor/` |
| **Reviewer stance** | Harsh on purpose. Assume every finding reaches production unless it is fixed. |

Backend paths are relative to `src/`. Frontend paths are relative to `src/Resources/`. Admin JS paths start at `app/administration/src/`. Line numbers point to commit `88a19fc`.

**How to read this revision**
- Revision 3 checks the claim made in the revision 2 status table ("Fixed" for almost every finding) and looks for new defects in the code that the fixes added.
- New findings are numbered `R3-H*`, `R3-M*`, `R3-L*` (backend) and `R3-F*` (frontend, all severities).
- Revision 2 findings keep their IDs. Only the ones that turned out **Incomplete** or **Regressed** are described again. Everything else is listed once as verified in [Revision 2 findings: re-verification](#revision-2-findings-re-verification).
- The revision 2 text, with its full finding descriptions and the per-commit status table, is in git history: `git show 88a19fc:Code-Review.md`.
- Every High finding was checked by hand against the code, and R3-H1 was reproduced with a script. Confidence is stated where a finding was read but not observed running.

---

## Summary

### Verdict

**Much better, but still not production-ready.** The rev-2 branch fixed the identity model: subject binding, `email_verified`, login-type scoping, step-up instead of `verify-session`, DB-backed state, a real rate limiter and fail-closed access rules. All three rev-2 Criticals are verified fixed, and I found no new Critical.

But:
- **Two headline features don't work at all.** Admin passkey login and both step-up methods always fail (R3-H1). The provider detail page crashes on every open (R3-F1). In SSO-only mode, R3-H1 means no admin can confirm any sensitive action: user and role management, Connect SSO and passkey registration are all blocked.
- **Three DAL entities are a privilege escalation away from superadmin.** These are the passkey credential (R3-H2), the account binding (R3-H3) and the provider itself (R3-H4). Nothing marks their trust-relevant fields as system-only, so an API role with update rights can log in as anyone.
- **Some "Fixed" items are only half fixed:**
  - N-H3: the session registry still expires before the sessions it tracks (R3-H5).
  - H7: the storefront account link still needs no fresh login (R3-H6).
  - F-H5: the ACL mapping most likely never registers (R3-F2).
  - N-M12: the secret re-entry rule can be bypassed (R3-M4).
- **The test suite is green, but it missed all of this.** The unit tests mock the AuthorizationServer. The E2E job that covers step-up and passkeys is `continue-on-error`. No E2E spec opens the provider detail page.

Do not deploy until **R3-H1 to R3-H7, R3-F1 to R3-F3, R3-M4 and R3-M9 to R3-M11** are fixed.

### Numbers

| | Critical | High | Medium | Low |
|---|---|---|---|---|
| **New in revision 3, backend** | 0 | 7 | 24 | 52 (grouped in tables) |
| **New in revision 3, frontend** | 0 | 3 | 2 | 12 (+3 unnumbered hygiene rows) |

The full per-ID result for revision 2 is in [Revision 2 findings: re-verification](#revision-2-findings-re-verification).

Rev-2 items that are **Incomplete or Regressed**: N-H2 (regressed), N-H3, H3, H7 (storefront), H8.3–H8.6, C2 (residue), M12, M16, M21, N-M12, F-H1, F-H5, F-N1 (regressed), F-N3, F-N7, F-N8, plus "Twig login-options memoisation", which was claimed fixed but is not.

### Tooling (run for this revision)

`composer install` worked in this checkout (rev 2 could not run anything).

| Tool | Result |
|---|---|
| PHPUnit (unit) | **OK**, 699 tests, 1743 assertions |
| PHPCS | clean |
| PHPStan level 5 (configured) | no errors |
| PHPStan level 8 (not configured) | **52 errors**: about 40 missing DAL generics (`EntityRepository<…Collection>`), plus real nullability issues. Examples: `RpInitiatedLogoutService.php:42,132` (`parse_url()` may return `false`, and `?? ''` doesn't catch that), `PasskeyRelyingPartyResolver` (`?string` key into `Collection::get()`), `Sw6Oidc.php` (`$this->container` nullable), `OidcLiveLoginTestService` (`decodeGroups()` gets an unvalidated array). |
| Psalm (errorLevel 4) | no errors |
| Psalm `--find-unused-code` | Mostly false positives from DI-wired classes. The real hits are in [Unused code](#unused-code). |
| Rector (dry-run) | clean |
| Integration / E2E | not run (needs a shop plus Dex). |

---

## High

### R3-H1. Admin passkey login, the inactivity passkey and both step-up methods always fail with `unsupported_grant_type`
- **Where:**
  - `Service/AdminAuth/AdminTokenIssuer.php:34-45`
  - callers `Controller/Api/StepUpController.php:87, 129, 143` and `Controller/Api/PasskeyAdminController.php:226`
- **What's wrong:**
  - `issue()` writes `grant_type` and `client_id` into `$request->request`, then converts the Symfony request with `PsrHttpFactory::createRequest()`.
  - For a JSON body, the bridge builds the PSR parsed body from the raw `getContent()` (`PsrHttpFactory.php:90-91`) and ignores the parameter bag.
  - The Administration posts JSON without `grant_type`: `sw-verify-user-modal/index.js:94, 107`, `sw-login/index.js:175`, `sw-inactivity-login/index.js:107`.
  - League therefore finds no grant that can respond and throws `unsupportedGrantType`.
- **Reproduced:** a Symfony JSON request `{"nonce":"abc"}` with `grant_type` and `client_id` set on `->request` converts to the parsed body `['nonce' => 'abc']`.
  - `/admin/token` only works because its JS sends `grant_type` and `client_id` itself (`sw-login/index.js:221`). So the token endpoint trusts client-supplied `grant_type`, `client_id` and `scope`. That is not exploitable today, but it is fragile.
- **Impact:**
  - Passkey admin login and step-up are dead.
  - Each attempt still consumes the assertion and counts a rate-limit failure.
  - In SSO-only mode the password confirmation is gone too, so **no `user-verified` action is possible at all**: core user and role management, Connect SSO and passkey registration.
  - E2E spec `05-admin-step-up-and-passkey` should be failing. The `e2e` job is `continue-on-error: true` (`ci.yml:270`), so nobody noticed.
- **Fix:** don't forward the client's request. Build a fresh PSR request, the way core's `OAuthAuthorizeController` does:
  ```php
  $psrRequest = $this->psr17->createServerRequest('POST', '/api/oauth/token')
      ->withParsedBody(['grant_type' => AdminOidcGrant::GRANT_IDENTIFIER, 'client_id' => 'administration', 'scope' => $scope])
      ->withAttribute(AdminOidcGrant::REQUEST_ATTRIBUTE_USER_ID, $userId)
      ->withAttribute(AdminOidcGrant::REQUEST_ATTRIBUTE_STEP_UP, $stepUp);
  ```
  Use it for `/admin/token` as well. Add a controller-level integration test with a real `AuthorizationServer` and `Content-Type: application/json`. Make the `e2e` job blocking.

### R3-H2. The passkey credential entity is writable through the Admin API, so a write privilege becomes impersonation of any account
- **Where:**
  - `Core/Content/PasskeyCredential/Sw6OidcPasskeyCredentialDefinition.php:44-53`
  - `Service/Passkey/PasskeyAuthenticationService.php:148`
- **What's wrong:**
  - No field is `WriteProtected`, and no write guard exists (unlike session activity, N-M8).
  - The account a passkey login resolves to comes from the `user_id` column. The library only checks the user handle inside the `public_key` JSON.
- **Attack:**
  1. A role or integration with `sw6oidc_passkey_credential:update` (grantable in the detailed privilege grid, and part of any "all privileges" integration) sends `PATCH /api/sw6oidc-passkey-credential/{ownKeyId}` with `{"userId":"<superadmin id>"}`.
  2. Its next passkey login returns a superadmin token.

  With `:create`, it can plant a credential with its own public key for any admin or customer.
- **Fix:** mark every field except `nickname` `WriteProtected(Context::SYSTEM_SCOPE)`. The repository already writes in system scope. Add a `PreWriteValidationEvent` guard that refuses create and update outside system scope, and add `user_type` `Choice`.

### R3-H3. The account binding (`sw6oidc_user_provider`) is writable through the Admin API and sync
- **Where:** `Core/Content/UserProvider/Sw6OidcUserProviderDefinition.php` (all fields)
- **What's wrong:** same class as R3-H2. A principal with `sw6oidc_user_provider:update` PATCHes a superadmin's binding to a `sub` it controls at the IdP and logs in as that superadmin. `:create` does the same for unbound accounts. `user_type` has no `Choice`.
- **Fix:** `WriteProtected(Context::SYSTEM_SCOPE)` on every field. All in-plugin writers (`bind`, `backfillSubject`, `unbind`) already run in system scope.

### R3-H4. The provider "editor" privilege is equivalent to superadmin, and nothing says so
- **Where:**
  - `Core/Content/Provider/Sw6OidcProviderDefinition.php:78-113`
  - `Core/Content/RoleMapping/Sw6OidcRoleMappingDefinition.php`
  - `acl/index.js` (editor role)
- **What's wrong:** a role with `sw6oidc_provider.editor` can take over any account, in either of two ways:
  - Set `scope` without `openid`, point the token and userinfo endpoints at its own host, and type in any secret (re-entering it satisfies N-M12). Its fake userinfo returns the `sub` of any bound admin.
  - Set `allowSuperadminGroupMapping` plus a `superadmin` mapping row plus `autoCreateAdmin`, which mints a new superadmin.

  The ACL UI presents "editor" as an ordinary settings role.
- **Fix:**
  - Require `AdminApiSource::isAdmin()`, or a dedicated `sw6oidc_provider:security` privilege, in the write guard for trust-relevant columns: endpoints, issuer, JWKS, scope, `public_client`, `allow_superadmin_group_mapping`, and `superadmin` mapping rows.
  - Write those changes to an audit trail.
  - At minimum, label the role as "equivalent to superadmin" in the role editor and the docs.

### R3-H5. Session registry rows expire before the sessions they track, so IdP logout still silently misses active users (N-H3 residue)
- **Where:** `Service/Session/Sw6OidcSessionRegistry.php:74-90` (`register`), `:171-181` (`activate`), `:211, 239` (prune/fetch filter on `expires_at`)
- **What's wrong:**
  - `expires_at` is set once and never extended.
  - Every admin refresh mints a new refresh token valid for the full `refresh_token_ttl` *from now* (League `AbstractGrant::issueRefreshToken`).
  - Customer context tokens also slide with activity.
- **Scenario:**
  1. An admin who uses the Administration daily is still logged in on day 8.
  2. The IdP disables them and sends a valid back-channel logout token.
  3. `fetch()` filters `expires_at > now`, finds nothing, answers **200** and logs `sessionsEnded: 0`.

  The failure is silent, exactly as in rev 2, just after 7 days instead of 24 h. CLAUDE.md's "kept for the real session lifetime" is wrong.
- **Fix:**
  - Admins: treat a row as alive while core's `refresh_token` table still has an unexpired row for that user, or push `expires_at` forward from a decorated refresh grant.
  - Customers: extend on context use, or prune by joining `sales_channel_api_context.updated_at`.

### R3-H6. Storefront "Connect SSO" needs no fresh login, so a stolen session can attach the attacker's IdP account permanently
- **Where:** `Storefront/Controller/SendAuthorizationRequestController.php:88-117`, `Storefront/Controller/OidcCallbackController.php:191-204`
- **What's wrong:**
  - `POST /sw6oidc/link` only has `_loginRequired`.
  - A hijacked customer session can bind the attacker's own IdP subject, giving a login that survives password changes and works in SSO-only mode.
  - This is the H7 class. It was fixed for passkey registration (600 s window) and for admin linking (`user-verified`), but not here.
- **Fix:** require the same recent-authentication proof as passkey registration (see R3-M5: per session, not per account) before starting the link. Notify the customer by mail or a Flow Builder trigger when a binding is created.

### R3-H7. Re-activating or re-scoping a provider that already has the admin flag turns SSO-only mode on with no lockout check and no session revocation (H8.3–H8.6 residue)
- **Where:** `Subscriber/Sw6OidcProviderWriteGuardSubscriber.php:255-330`
- **What's wrong:** the three lockout checks and `PasswordSessionRevoker` only run when the flag key is in the payload and flips on. `validateRemovalKeepsAdminAccess` only looks at deactivation.
- **Scenario:**
  1. Provider P has `disable_non_oidc_admin_login` and is deactivated, so the policy is off.
  2. Meanwhile the bound admins are deactivated and others log in with passwords. `AdminLockoutGuardSubscriber` doesn't act, because the policy is off.
  3. P is re-activated with a payload of `is_active` only, or its `login_type` changes from `customer` to `both`.

  SSO-only mode is back on with possibly **zero** admins able to log in, and the password sessions from step 2 stay alive.
- **Related gaps:**
  - The bound-account check counts inactive users (`:285-290, 307`). With the only bound admin inactive, one click on "confirm" locks everyone out, and the revoker then ends all sessions, including the acting admin's.
  - Unlink (`OidcUserProviderAdminController.php:98-124`) runs no lockout check at all. It needs only `user:update`, while core requires a `user-verified` token for any user change. A hijacked session of an admin with `user:update` can unbind every admin.
- **Fix:** write one "SSO-only invariant" check: at least one **active** admin is bound to an **active** admin-serving provider whenever the effective policy is on. Run it from every write that can change the result: provider insert/update/delete, `user` update/delete and binding delete. Trigger revocation whenever the effective policy goes from off to on. Require `UserVerifiedScope::isPresent()` for admin unlinks.

---

## Medium

### OIDC protocol, HTTP, JWKS

#### R3-M1. Anonymous logout tokens can force unlimited outbound JWKS fetches (N-H2 regression)
- **Where:** `Service/Jwt/JwtVerifier.php:169, 223-239`; `Controller/Oidc/BackChannelLogoutController.php:74-92`
- **What's wrong:**
  - The logout-scope cooldown key is `sha256(endpoint . "\0" . kid)`, and `kid` is chosen by the attacker. A logout token with the real `iss` and `aud` (both public) and a fresh random `kid` passes the cooldown every time and forces a JWKS GET.
  - The per-provider `isBlocked()` is only consulted in the `catch`, after the fetch.
  - If the IdP throttles and a fetch fails, the shared `sw6oidc_jwks_fail_<endpoint>` breaker (60 s) also blocks logins that need a refetch.
  - Each random `kid` writes a `cache.app` item.
- **Fix:**
  - Logout scope: key the cooldown on the endpoint only, or add a global per-endpoint cap.
  - Check `isBlocked()` before calling `verifyLogoutToken()`.
  - Reject a `kid` longer than 256 bytes.

#### R3-M2. "max_redirects 0" (M12 residue) has no effect in production, so up to 20 redirects are followed
- **Where:** `Service/Http/Sw6OidcHttpClientFactory.php:25-27`
- **What's wrong:**
  - `withOptions(['max_redirects' => 0])` is applied to the *inner* client before it is wrapped in `NoPrivateNetworkHttpClient`.
  - The wrapper keeps its own defaults (`max_redirects: 20`) and follows redirects itself via `redirect_url`.
  - So only insecure dev mode (no wrapper) actually has redirects off.
  - A 307/308 from the token or revocation endpoint re-POSTs `code`, `code_verifier` or the token being revoked to another public host, including an https→http downgrade.
- **Fix:**
  ```php
  $client = $allowInsecure ? $inner : new NoPrivateNetworkHttpClient($inner);
  return $client->withOptions(['max_redirects' => 0]);
  ```
  `AvatarFetcher`'s per-request `max_redirects: 3` still overrides this. Add a `MockHttpClient` test that returns a 302.

#### R3-M3. Keycloak (and Auth0 `/v2/logout`) are detected as Authelia, so RP-initiated logout breaks
- **Where:** `Service/Oidc/RpInitiatedLogoutService.php:130-135`, used at `:40-49`
- **What's wrong:**
  - Keycloak's `…/protocol/openid-connect/logout` ends in `/logout` and contains neither `/oauth2/` nor `/oidc/`, so it takes the Authelia `?rd=` branch.
  - That branch sends no `id_token_hint`, `post_logout_redirect_uri` or `state`. Keycloak 18+ ignores `rd`, and the user never returns to the shop.
  - Separately, the admin fallback path (no id_token) never sends `client_id`, which Keycloak requires without a hint.
- **Fix:** detect Authelia explicitly (a provider flag or the discovered issuer), not by URL shape. Always send `client_id`.

#### R3-M4. The secret re-entry rule (N-M12) can be bypassed in two ways
- **Where:** `Subscriber/Sw6OidcProviderWriteGuardSubscriber.php:171-174, 396-401`
- **What's wrong:**
  - **Public-client toggle:** `$isPublicClient` reads the **payload** before the stored row.
    1. Save `{publicClient:true, accessTokenEndpoint:"https://evil/token"}`. It passes.
    2. Save `{publicClient:false}`. It passes, because no URL changed.

    The next login sends `Basic client_id:secret` to `evil`.
  - **Clone:** the check only runs for `UpdateCommand`. `POST /api/_action/clone/sw6oidc-provider/{id}` with an overwritten endpoint is an insert. Core's clone carries the stored secret along, so a holder of only `sw6oidc_provider:create` gets the secret sent to their host.
- **Fix:**
  - Use the **stored** `public_client`, and require the secret when it flips to `false`.
  - Run the URL-change rule on inserts that carry an existing secret, or add `CloneProtection` to the definition.
  - Keep the decrypted secret out of `jsonSerialize()`; better, decrypt lazily (see the roadmap).

### Passkeys

#### R3-M5. The storefront registration window is per account, not per session (H7 incomplete)
- **Where:** `Storefront/Controller/PasskeyController.php:83, 243-248`; `SendAuthorizationRequestController.php:138-148` (`/sw6oidc/reauth`)
- **What's wrong:**
  - The check reads `customer.lastLogin`. An attacker with a stolen session polls `registration-options`. The moment the victim logs in anywhere, the window opens for the attacker's session too, and a permanent passkey gets planted.
  - `/sw6oidc/reauth` sends `prompt=login&max_age=0` but never checks `auth_time` (only `StepUpService` does), so an IdP that ignores `max_age` satisfies it silently.
- **Fix:** store a per-session "authenticated at" timestamp at every login (password, OIDC, passkey and reauth) and check that. Verify `auth_time` on the reauth callback.

#### R3-M6. Deleting a passkey does not end the sessions it created
- **Where:**
  - `Controller/Api/PasskeyAdminController.php:163-177`
  - `extension/sw-profile/page/sw6oidc-profile-passkey/index.js:122-124`
  - `Storefront/Controller/AccountPasskeyController.php:87-95`
  - the admin grid's generic DAL delete
- **What's wrong:** "force logout" only fires when the *current tab* was authenticated by that key. Core's `loginService.logout()` revokes nothing on the server, so the refresh token stays valid. A user who deletes a stolen key leaves the attacker's sessions running.
- **Fix:** on delete of a key with logins, call `Sw6OidcSessionDestructionService::destroyAllForUser()` (admins: revoke refresh tokens). Better: record the credential id on `sw6oidc_session_activity` and end exactly those sessions.

#### R3-M7. Login ceremonies are not bound to their purpose or surface
- **Where:** `Service/Passkey/PasskeyAuthenticationService.php:27, 51-67, 81`
- **What's wrong:**
  - Storefront login, admin login and admin step-up share the `sw6oidc_passkey_auth_` prefix and carry no purpose field.
  - With a `passkeyRpId` covering both hosts, a storefront ceremony can be redeemed at `/api/sw6oidc/admin/passkey/login-verify`, and its storefront origins pass. That undoes the exact-origin protection of N-M1 for the admin.
  - Step-up ceremonies are also not bound to the requesting admin.
- **Fix:** store `purpose` (`storefront-login:{salesChannelId}`, `admin-login`, `admin-stepup:{userId}`) and check it in `verifyAssertion()`.

#### R3-M8. `passkeyRpId` is one value for the admin and every sales channel, and is never validated
- **Where:** `Service/Passkey/PasskeyConfig.php:146-151`, `PasskeyRelyingPartyResolver.php:46, 74`
- **What's wrong:**
  - If the admin and the sales channels sit on different registrable domains, setting the value breaks every surface outside it: the origin list empties, and the resolver throws.
  - It is never checked to be a registrable suffix of the host.
- **Fix:** read it per sales channel, add a separate admin key, and validate it on save.

### Identity and provisioning

#### R3-M9. Subject binding ignores the issuer and compares `sub` case- and accent-insensitively (C2 residue)
- **Where:** `Service/Provisioning/UserProviderBindingService.php:45-57`; `Migration1730000001CreateOidcSchema.php:125` (`utf8mb4_unicode_ci`); unique key in `Migration1790800005…:47`
- **What's wrong:**
  - `issuer` is written but never compared. CLAUDE.md and rev 2 claim binding by `(provider, iss, sub)`.
  - Repointing a provider row at another tenant (staging → prod, realm migration) logs colliding `sub` values into the old accounts.
  - Under `unicode_ci`, `René`, `rene` and `RENE` are one subject. That matters for IdPs whose `sub` is a username.
- **Fix:** filter on `issuer` too. Make `sub` and `issuer` `utf8mb4_bin` or `VARBINARY`. When a provider's issuer changes, suspend its bindings until an admin confirms.

#### R3-M10. The legacy-binding upgrade skips the superadmin guard and the link opt-in
- **Where:** `Service/Provisioning/IdentityResolver.php:68-100` (runs before the `$emailMatchIsPrivileged` / `link_existing_accounts` check at `:102`)
- **What's wrong:** a legacy (pre-`sub`) binding of a **superadmin** is upgraded automatically for whoever holds that verified email at the IdP *today*. If the address has been recycled, the new owner gets the superadmin account.
- **Fix:** for legacy bindings of privileged accounts, require `linkExplicitly()` instead of the automatic upgrade. Log every upgrade at warning level.

#### R3-M11. Role and group sync is single-valued and overwrites manual assignments (H3 incomplete)
- **Where:** `Service/Provisioning/GroupMappingResolver.php:33-46, 84-92`; `AdminProvisioningService.php:259-323`; `CustomerProvisioningService.php:293-299`
- **What's wrong:**
  - `resolve()` returns the **first** matching role only. A user in several mapped groups keeps one role, and roles granted by hand in Shopware are deleted on every login.
  - The provider **default** counts as "resolved". With sync on, a customer the merchant moved to a B2B group is reset to the default group at the next login.
  - When nothing resolves, the method returns before the revoke step. With `revoke_superadmin_on_sso`, a superadmin removed from every IdP group stays superadmin (unless a default role is set).
  - The "last superadmin" check is a count followed by an update, with no lock. Two of the last superadmins logging in at the same time can both be revoked. The role delete and insert are separate writes.
- **Fix:**
  - Map every matching group.
  - Add or remove only roles the plugin manages (track them).
  - Don't apply defaults during sync.
  - Run the revoke in a transaction with `SELECT … FOR UPDATE` on the superadmin rows.

#### R3-M12. First-login race still leaves duplicate customers (M21 incomplete)
- **Where:** `CustomerProvisioningService.php:105-106`; `AdminProvisioningService.php:216-231`
- **What's wrong:**
  - Customers: `create()` then `bind()` run without a transaction. Two simultaneous first logins create two customers. The loser's `bind()` throws and leaves an **orphan customer with the same email**. Core's `AccountService::fetchCustomer()` returns the newest match, so password login and recovery then hit the orphan.
  - Admins: the retry also catches the `uniq.user.email` violation. It retries with new usernames, fails the same way twice, then throws a raw DBAL exception, and it never re-resolves.
- **Fix:** wrap create + bind in `Connection::transactional()`. On a unique violation, roll back and re-run `resolve()`.

#### R3-M13. JIT customers ignore Shopware's sales-channel binding, which can lock existing customers out of password login
- **Where:** `CustomerProvisioningService.php:186-203` (compare core `RegisterRoute.php:184, 531-560`)
- **What's wrong:**
  - Core sets `boundSalesChannelId` when the config is on, *or* when a bound account with that email already exists. The plugin never does.
  - Scenario: customer X is bound to channel A. SSO login happens in channel B, so an **unbound** duplicate is created. In channel A, `fetchCustomer()` now returns the newer duplicate. X's password stops working and recovery targets the duplicate.
- **Fix:** reuse core's rule when creating the customer.

#### R3-M14. With sales-channel binding on, SSO only ever works in the first channel
- **Where:** `CustomerProvisioningService.php:85-91` plus the unique key `(provider_id, user_type, sub)`
- **What's wrong:**
  - The subject resolves to the customer bound to channel A, and channel B refuses the login.
  - A second account can't be bound, because the subject is already taken.
  - The refusal lands in the generic `\Throwable` path and counts against the callback rate limit.
- **Fix:** include the sales channel in the binding key, or document "one provider per channel when binding is on". Catch `CustomerProvisioningDeniedException` as a policy denial that isn't counted.

#### R3-M15. Any email transform locks out every user while `require_email_verified` is on (the default)
- **Where:** `Service/Oidc/OidcCallbackResult.php:81-89`, `IdentityResolver.php:54`
- **What's wrong:** `emailVerified` is only true when the mapped email equals the raw claim. A lowercase or regex transform added after go-live makes it false for **everyone**, bound accounts included. In SSO-only mode that locks out every admin.
- **Fix:** check verification against the raw claim and apply the transform afterwards, or refuse that combination at save time.

#### R3-M16. Placeholder addresses are flagged but the flag is never read (M16 incomplete)
- **Where:** `CustomerProvisioningService.php:182-184`; address sync `:334-377`
- **What's wrong:** `customFields.sw6oidc_placeholder_address` is written, and nothing in PHP, Twig or JS reads or clears it. Orders go out with street and city set to `-`.
- **Fix:** add a checkout-confirm subscriber that sends flagged addresses to the edit form. Clear the flag when address sync or the customer fills in real values.

### State, sessions, logout

#### R3-M17. The one-time-token table grows without bound in the default (no Redis) setup
- **Where:** `Service/Cache/DatabaseAtomicCache.php:84-87` (`LIMIT 10000`), one `prune()` per day in `SessionActivityCleanupTaskHandler`, the anonymous `GET /sw6oidc/login`, and `login.html.twig:61` (a plain link with no `rel="nofollow"`)
- **What's wrong:**
  - Every flow start and passkey-options call inserts an encrypted row. The rate limit allows about 43k rows per day per IP (/64) per scope, and crawlers follow the login link.
  - Prune deletes at most 10k expired rows per day, so the backlog never shrinks, and each prune locks a bigger range.
- **Fix:** prune in a loop, hourly. Add `rel="nofollow"`, or start the flow with a POST.

#### R3-M18. Redis error replies are treated as "already seen", so valid back-channel logouts are dropped
- **Where:** `Service/Cache/RedisAtomicCache.php:66, 111`
- **What's wrong:**
  - phpredis returns `false` (it doesn't throw) for server error replies: `OOM` under `noeviction`, `READONLY` after a failover, `MISCONF`.
  - `addIfAbsent()` then reports a replay, and the controller answers 200 without ending anything. That fails open on a security path.
  - A failed `setex()` is ignored, so the flow state is lost and the login fails.
- **Fix:** check the return values plus `getLastError()`, and fall back to the database store. Document that this Redis must not evict keys.

#### R3-M19. A failure halfway through IdP logout leaves the session alive and impossible to target again
- **Where:** `Controller/Oidc/BackChannelLogoutController.php:94-105`; `Service/Session/Sw6OidcIdpLogoutHandler.php:63, 73, 84`
- **What's wrong:**
  - The `jti` marker is set before any work.
  - Registry rows are removed **before** `destroy()` runs.
  - If `destroy()` throws (lock wait or deadlock), the response is a 500, the IdP's retry is treated as a replay, and a fresh token for the same `sid` finds no row. The session survives.
- **Fix:** destroy first, then remove. Set the `jti` marker on success, or delete it in the `catch`.

### Admin auth and password policy

#### R3-M20. The user-access-key (SWUA…) block in SSO-only mode is bypassable
- **Where:** `Subscriber/AdminPasswordLoginGuardSubscriber.php:95-97`
- **What's wrong:** the guard checks `str_starts_with($body['client_id'] ?? $request->getUser(), 'SWUA')`. League trims `client_id`, treats an empty value as missing and falls back to the Basic-auth user. Both of these get through:
  - `"client_id":" SWUA…"` (leading space);
  - `"client_id":""` plus `Authorization: Basic SWUA…:secret`.

  No decorator backs this path, unlike passwords (N-H1).
- **Fix:** decorate `ClientRepository::validateClient()` / `getClientEntity()` to refuse `user`-origin keys while the policy is on, the same pattern as `PasswordLoginGuardUserRepository`.

#### R3-M21. `PasswordSessionRevoker` logs out guests and runs one unbounded UPDATE inside a provider save
- **Where:** `Service/Security/PasswordSessionRevoker.php:40-56`
- **What's wrong:**
  - `customer_id NOT IN (bound customers)` also matches guest checkouts in progress, which are explicitly allowed to continue.
  - It is a single full-table `UPDATE … NOT IN (subquery)` on `sales_channel_api_context`, run inside the admin's HTTP request.
- **Fix:** join `customer` with `guest = 0`. Batch it, or dispatch it to the message queue.

#### R3-M22. Lockout confirmation is weak
- **Where:** `Service/Security/LockoutConfirmationStore.php`; `Controller/Api/ProviderLockoutConfirmationController.php`
- **What's wrong:**
  - It lives on `cache.app`: per node, and wiped by `cache:clear`.
  - It is keyed per provider, not per confirming admin, so another admin's save can consume it.
  - It needs only `sw6oidc_provider:update`, although it can lock out superadmins and end their sessions.
- **Fix:** move it to `AtomicCacheInterface`, key it by provider plus user, and require `user-verified`.

### DAL and configuration

#### R3-M23. A public client can't be created without a dummy secret, and importing one always fails
- **Where:** `Sw6OidcProviderDefinition.php:77` (`Required` on `client_secret`); `OidcConfigTransfer.php:261, 450`
- **What's wrong:**
  - Core turns a missing `Required` field on insert into a null value, and NotBlank rejects it.
  - The export omits the secret for public clients, so the import fails with "This value should not be blank".
  - The admin form forces a junk secret.
- **Fix:** drop `Required`, and enforce "secret required unless public" in the write guard.

#### R3-M24. Write-guard bypass on partial updates of attribute mappings (F-N16 residue)
- **Where:** `Subscriber/AttributeMappingWriteGuardSubscriber.php:44`
- **What's wrong:** an `UpdateCommand` only carries changed columns. Changing only `transform_params` of an existing `regex_replace` row, or only `transform_function` to `regex_replace`, skips pattern validation. The broken pattern then fails at login, and for email or username that fails **every** login (N-L7).
- **Fix:** for updates, load the stored row and validate the merged state.

---

## Frontend

### High

#### R3-F1. The provider detail page crashes on every open
- **Where:** `module/sw6oidc-provider/page/sw6oidc-provider-detail/sw6oidc-provider-detail.html.twig:792`; `index.js:109`
- **What's wrong:**
  - `<template v-if="diagnostics.infrastructure">` sits *outside* the `<ul v-if="diagnostics">` (`:757-758`).
  - `diagnostics` starts as `null`, and the card is inside `<sw-card-view v-if="provider">`. As soon as the provider loads, the render throws a TypeError, and the page stays blank or broken.
  - It was introduced in `0d8f457` and is in the shipped bundle. No E2E spec opens the page.
- **Fix:** use `v-if="diagnostics?.infrastructure"`, or move it inside the `<ul>`. Rebuild, and add an E2E smoke test that opens every plugin page without console errors.

#### R3-F2. The ACL privilege mappings most likely never register (F-H5 / F-N7 ineffective)
- **Where:** `acl/index.js:7-9`; `views/administration/index.html.twig:45-46` (loaded in `administration_login_scripts`)
- **What's wrong:**
  - The plugin bundle runs right after core's entry module. Core registers the `privileges` service later, in the dynamically imported `main` chunk (`main.ts`).
  - So `Shopware.Service('privileges')` is `undefined`, and the `if (privileges)` guard silently skips everything. The browser's module cache then stops `loadPlugins()` from re-running the same URL.
  - The plugin already works around exactly this timing for modules (`defer-module-register.js`), but not for ACL.
  - In addition:
    - `sw-privileges.permissions.sw6oidc_*.label` snippets are missing in both locales.
    - `force_logout` is declared under category `permissions`, whose grid only renders viewer/editor/creator/deleter. It needs `additional_permissions` (see core `sw-order/acl`).
- **Confidence:** medium-high. The load order is read from core, not observed in a browser.
- **Fix:** defer the registration the way `registerModuleWhenReady` does. Add the snippets, move `force_logout` to `additional_permissions`, and add an E2E test that grants a non-admin role.

#### R3-F3. Storefront: deleting one passkey can delete a different one
- **Where:** `app/storefront/src/passkey/passkey-delete-confirm.plugin.js:14-16, 29-33`; `views/storefront/page/account/passkey/index.html.twig:58-59, 70`
- **What's wrong:**
  - Every row's form shares one `<dialog id="sw6oidc-passkey-delete-dialog">`, and every row's plugin listens for its `close` event.
  - Confirming the delete for row A makes **every** form call `requestSubmit()`. The last navigation wins, so typically the last key is deleted.
  - A user removing a lost or compromised key can delete their good key and keep the bad one.
- **Fix:** remember which form opened the dialog and submit only that one, or render one dialog per row.

### Medium

| # | Where | Issue | Fix |
|---|---|---|---|
| **R3-F4** (F-N1 regressed) | `extension/sw-login/index.js:116-118` | The same-admin check reads `me.data.attributes.username` or `me.username`. With `Accept: application/json`, core returns `{data:{username}}`, so the check is always false. After an inactivity SSO re-login the admin always lands on the dashboard, `lastKnownUser` stays behind, and other tabs stay on the modal. It fails safe, but the feature is dead. | Compare `me?.data?.username`, or use core's `userService.getUser()`. |
| **R3-F5** | `extension/sw-login/index.js:175-186`, `extension/sw-inactivity-login/index.js:107-122`; server `PasskeyAdminController.php:230` | **A failed passkey login hangs forever.** The 401 hits core's `refreshTokenInterceptor`. With no refresh token it rejects without notifying subscribers, and the request never settles. The button spins and no error shows, including for the F-H6 "different admin" refusal. In step-up (`StepUpController.php:126`), each 401 is refreshed and replayed, so failures count twice. | Answer 400/403 from the anonymous and redeem endpoints, or skip the interceptor for `anonymous` calls. |

### Low

| # | Where | Issue | Fix |
|---|---|---|---|
| R3-F6 | `extension/sw-inactivity-login/index.js:68-69` + twig `:69, 76` | Uses `sw6oidc.login.*` keys. Before login, core only serves the `sw-login` and `global` snippet namespaces, so a reload on `#/inactivity/login/…` shows raw keys. | Use `sw-login.sw6oidc.login.*`, as `sw-login` already does. |
| R3-F7 | `extension/sw-profile/page/sw6oidc-profile-passkey/…html.twig:42-48` | `mt-label` isn't a registered component in 6.7.14, so the "disabled" badge renders as plain text. | Use `mt-badge`. |
| R3-F8 | `extension/sw-profile-index-general/…html.twig:1-20` | In 6.7.14, `sw_profile_index_general_image` sits inside the info card's grid, so the plugin's `mt-card` lands in a grid cell. | Extend `sw_profile_index_general_information` with `{% parent %}` plus a sibling card. |
| R3-F9 | `extension/sw-verify-user-modal/index.js:54-75` | If the admin closes the IdP popup, no message arrives and `sw6oidcStepUpBusy` stays true, leaving both buttons disabled. The popup isn't closed on unmount. Backend side: step-up pipeline errors take the normal login-redirect path, so no `postMessage` is sent at all (`OidcAdminAuthController.php:240-241, 281-333`). | Poll `popup.closed`. Always render the `postMessage` page for `step_up` flows, `{error}` included. |
| R3-F10 | `extension/sw-login/sw-login.html.twig:12-14` | Password-disabled mode hides the whole form, including "Keep me logged in", so SSO logins always run with `rememberMe = false` (F-M1 gap). The comment "no narrower block" is wrong: `sw_login_login_user_field`, `_password_field` and `_submit` exist. | Override the field and submit blocks only. |
| R3-F11 | `provider-list/index.js:54-56`, `passkey-list/index.js:69-71` | The listing mixin's `created()` plus the component's own call fetch twice. In the passkey list, `ownerNames` is written before the request-id check. | Drop the own `created()` call, and move the write behind the check. |
| R3-F12 | `provider-detail/index.js:399-440` (F-M10 residue) | `loadEntity()` and `loadFormContext()` have no request counter, so fast A→B navigation can apply A's data to B. | Add a request id. |
| R3-F13 | sessions list `index.js:119-121` (F-N3 residue) | "Only active" still returns rows labelled expired, and "Force logout" stays enabled on them. | Filter them out, and disable the action. |
| R3-F14 | provider detail `<fieldset :disabled>` (F-N8 residue) | It only disables native inputs. `sw-single-select`, `sw-entity-single-select`, `sw-multi-tag-select` and grid context menus (rendered in popovers) stay interactive for viewers. Saving is blocked, so the impact is cosmetic. | Pass `:disabled="!canEdit"` to each component. |
| R3-F15 | passkey-list (`variant="danger"`), sessions-list (`success`) | These are probably not Meteor `mt-badge` variants (`critical` / `positive`). | Use the Meteor names. |
| R3-F16 | provider detail `atomicStore.${…}`, `infrastructureWarning.${…}`, `index.js:351` `testMessage.${…}` | Snippet lookups without a fallback, so a new backend code shows a raw key. | Use `snippetOr`. |
| R3-F17 | admin error containers, storefront `<dialog>` | No `role="alert"`/`aria-live` on the errors, and no `aria-labelledby` on the dialog. | Add them. |
| — | `provider-detail.html.twig` (~30 uses), `rp-id-field` | `sw-text-field`, `sw-switch-field`, `sw-number-field`, `sw-card`, `sw-button`, `sw-button-process` and `sw-password-field` are all `@deprecated tag:v6.8.0`. `$tc` (239 uses) is deprecated in core's Vue adapter. | Migrate to `mt-*` and `$t`. |
| — | `component/sw6oidc-rp-id-field/index.js:56-66`; `views/administration/index.html.twig:27-33` | The placeholder shows the admin host, which is wrong for storefronts. Two "VERIFICATION NEEDED" comments shipped. | Fix the placeholder, and remove the comments. |
| — | `sw6oidc-user-provider-info` | `$emit('unlinked')` has no `emits` declaration and no listener. | Declare it, or remove it. |

---

## Low (backend)

### OIDC core, HTTP, security helpers

| # | Where | Issue | Fix |
|---|---|---|---|
| R3-L1 | `Service/Provider/ProviderResolver.php:33` | `?providerId=` (empty) or a non-UUID reaches `new Criteria([$id])`, which throws `InvalidCriteriaIdsException` or `InvalidUuidException`. That is a 500 or JSON error page instead of the "provider unavailable" flash or redirect on storefront login, link, admin login and step-up. | Check `Uuid::isValid()` first and throw `ProviderNotFoundException`. |
| R3-L2 | `Service/Security/RelayStateValidator.php:25` | No `D` modifier, so `$` matches before a trailing `\n`. `redirectTo=/foo%0A` passes, then `header()` rejects the `Location` and the user gets a 302 with no target. | Use `'#^/(?![/\\\\])[^\x00-\x20\x7f\\\\]*$#D'`. |
| R3-L3 | `Service/Security/BrowserBinding.php:40-56, 77-98` | A reused cookie is never re-issued, so its 30-minute lifetime isn't extended. A retry 28 minutes after an earlier attempt, plus 2 minutes of MFA, fails as "different browser" and counts against the rate limit. | Re-issue the cookie on every flow start. |
| R3-L4 | `Service/Oidc/OidcLiveLoginTestService.php:128-141` | The live test passes a provider with no `sub`, while every real login fails (L5 parity gap). | Move the `sub`-required check into `ClaimsMerger`. |
| R3-L5 | `OidcSecurityHelper.php:22` (`?BrowserBinding = null`), `OidcLiveLoginTestService.php:36` | Optional dependencies exist only for tests. A wiring mistake silently disables M1. | Make them required, and use test doubles. |
| R3-L6 | `ClaimsNormalizer.php:61-68` vs `flattenRecursive`, `Sw6OidcAccessControlEvaluator::members()` | With `base64_claims` on an object-shaped group claim, group mapping sees decoded keys while access rules see encoded ones. | Decode object keys in `flattenRecursive`, or document the limitation. |
| R3-L7 | `JwtVerifier::verify()` `:57, 269-273` | `$expectedNonce` is nullable, and the "nonce skipped" branch fails open. Both callers always pass a string. | Make it `string` and delete the branch. |
| R3-L8 | `BackChannelLogoutController.php:62` | The global `backchannel_logout` failure budget is written but never read by `isBlocked()`. | Read it, or delete it. |
| R3-L9 | `FrontChannelLogoutController.php:57` | `isBlocked()` runs first, so ten malformed requests from one office NAT drop every real front-channel logout from that IP for 60 s. | Don't let the malformed budget block well-formed `iss`/`sid` requests. |
| R3-L10 | `InvalidStateException`, `ProviderNotFoundException`, `InvalidJwtException`, `ClaimsTooComplexException`, `OidcHttpException` | Plain `\RuntimeException`. The Shopware ≥6.5 convention is `HttpException` with error codes, so anything that escapes becomes an anonymous 500. | Extend `HttpException` (404/400 with codes). |
| R3-L11 | `OidcCallbackProcessor.php:35-45`, `ClaimsNormalizer.php:17-26, 70-75`, `RpInitiatedLogoutService.php:63-70` | Two consecutive docblocks per method. Only the last attaches, so `@throws`/`@param` in the first are invisible to PHPStan, Psalm and IDEs. | Merge them. |
| R3-L12 | `OidcProviderAdminController.php:205, 247`, `AuthorizationFlowContext::LOGIN_TYPES` | Magic `'test'` login type outside the `LoginType` enum. The live test smuggles the locale through `relayState`. | Use an enum case and a typed field. |

### Passkeys

| # | Where | Issue | Fix |
|---|---|---|---|
| R3-L13 | `Storefront/Controller/PasskeyController.php:71-143`; `PasskeyRegistrationService.php:194-207` | `registration-options` is not rate limited. Each call stores a 300 s ceremony and deserializes every key. There is no cap on credentials per account. | Use a `consume()` budget per customer. Cap at about 20 keys. |
| R3-L14 | `PasskeyController.php:173-241` | Login CSRF: Shopware 6.7 has no CSRF token, and SameSite=Lax doesn't stop a top-level POST. An attacker signs an assertion with their own software authenticator and logs the victim into the attacker's account. | Require `Origin` / `Sec-Fetch-Site: same-origin` on the four ceremony POSTs. |
| R3-L15 | `PasskeyCredentialRepository.php:272-290` | Counter update is read-check-write with an unconditional write. Concurrent assertions can lower the counter. The entity is loaded three times per login. | Use `UPDATE … WHERE sign_count < :new`, and pass the entity id along. |
| R3-L16 | `PasskeyAdminController.php:141, 166`, `AccountPasskeyController.php:69` | Integration tokens (`userId = null`) and non-hex ids cause a 500. | Return 403 for non-user sources, and check `Uuid::isValid()`. |
| R3-L17 | both `webauthn-codec.js` | `response.getTransports()` is dropped, so step-up's `allowCredentials` has no transport hints and browsers show a worse picker. | Send `transports` and `clientExtensionResults`. |
| R3-L18 | `views/storefront/page/account/passkey/index.html.twig:51-66` | Keys disabled by clone detection look normal on the storefront. The page is a single big block. | Add a badge and sub-blocks. |
| R3-L19 | `PasskeyRelyingPartyResolver.php:56-59`; `passkey-list/index.js:78-82` | A raw DBAL domain query when the context already has the domains loaded. The full `publicKey` JSON is fetched per grid row. | Use the context, and `criteria.addIncludes`. |

### Identity and provisioning

| # | Where | Issue | Fix |
|---|---|---|---|
| R3-L20 | `AttributeMapper.php:81, 124` | `FILTER_VALIDATE_EMAIL` without the Unicode flag rejects `user@bücher.de`. Core stores punycode (`EmailIdnConverter`), so lookups wouldn't match anyway. | Apply `EmailIdnConverter::encode()` before validating and looking up. |
| R3-L21 | `CustomerProvisioningService.php:166-224, 269-275`; `AdminProvisioningService.php:135-137, 343-348` | No length caps. A `given_name` longer than the DAL limit causes a `WriteException`, and with sync on, every login of that user fails. | Truncate with `mb_substr` to the field limits. |
| R3-L22 | `AvatarFetcher.php:44-58` | `timeout` is an idle timeout, so a slow-drip server stalls admin login. The MIME type comes from the header, and the file becomes public media. | Add `max_duration: 10`, and sniff the bytes (`getimagesizefromstring`). |
| R3-L23 | `AdminProvisioningService.php:62` | Only `admin = 1` counts as privileged. An admin with an all-powerful ACL role can be email-linked when the opt-in is on. | Treat every admin as privileged. |
| R3-L24 | `CustomerProvisioningService.php:99-102`, `AdminProvisioningDeniedException::autoCreateDisabled($email)`, `OidcCallbackController.php:173-179` | The full email is logged at warning level. Provisioning denials count as callback failures, so ten denied users behind one NAT block SSO for that IP. | Treat them as policy denials (not counted), and drop the email from the message. |
| R3-L25 | `AdminProvisioningService.php:448-461` | Unbounded username probe: one query per suffix. | Use a single `LIKE` query, or a random suffix after three tries. |
| R3-L26 | `CustomerProvisioningService.php:120-137` | Email lookup has no sort and ignores `active`. Core sorts by `createdAt DESC`. | Sort like core, and skip inactive accounts. |
| R3-L27 | `OidcCallbackController.php:107-144` | If `register()` or `recordLogin()` throws after the context swap, the user is logged in but sees "login failed", a failure is counted, and the session is missing from the registry. | Register before swapping, or swallow and log registry errors. |
| R3-L28 | `OidcAdminAuthController.php:248-269` (H1 residue) | For an inactive admin, the callback still syncs, writes `rememberForAdmin`, registers a pending session and mints a nonce. Only the token exchange fails. | Check `active` before any side effect. |
| R3-L29 | `CustomerProvisioningService` / `AdminProvisioningService`; `OidcCustomerLoginRoute.php:62` | Find-by-email, reload-after-create, fallback names and partial-sync payloads are duplicated. `loginByCustomerId()` reloads a customer the caller already holds. Sync issues an `update` on every login even when nothing changed, which fires `*.written`, indexers and the cleanup subscriber. | Extract a shared resolution step. Pass the entity in. Skip no-op updates. |

### State, sessions, logout, health, migrations

| # | Where | Issue | Fix |
|---|---|---|---|
| R3-L30 | `Sw6OidcSessionDestructionService.php:42, 64` | After core rotates a customer context token (for example on a password change), the registry holds the old one, `delete()` hits 0 rows, and the new token survives. | On 0 rows, fall back to `revokeAllCustomerTokens()`. |
| R3-L31 | `Sw6OidcSessionActivityRecorder.php:84-97, 130-137` | Matching loads up to 200 open rows into PHP and gives up beyond that. The `session_key_hash` and `registry_session_id` indexes are never used. | Query with an OR filter, and close rows with one DBAL `UPDATE`. |
| R3-L32 | `HealthCheckAlertTaskHandler.php:26`, `NodeHeartbeat` | The worker container records its own heartbeat, so one web node plus one worker shows `multi_node_without_redis` permanently. Rolling deploys trigger it too. | Record heartbeats from web requests only. |
| R3-L33 | `RpInitiatedLogoutService.php:71-75, 110` | "Fire-and-forget" revocation is two synchronous calls with the provider's `http_timeout` (default 30 s), so logout can hang 60 s while the IdP is down. | Use a short fixed timeout, or an async message. |
| R3-L34 | `Migration1790800006CreateStateTables.php:32-53` | `sw6oidc_session` has no FK to the provider. After a provider is deleted, encrypted IdP tokens stay for up to 30 days. | Add `ON DELETE CASCADE`, or a deleted-event listener. |
| R3-L35 | `RedisAtomicCache.php:66, 111` | Values (PKCE verifiers, user ids) are stored in Redis in plaintext, while the DB store encrypts them. | Encrypt in both. |
| R3-L36 | `HealthCheckController.php:125-131` | Without `SW6OIDC_HEALTH_TOKEN` (the default), anyone sees IdP failures, node count and Redis misconfiguration. | Without a token, return `status` only. |
| R3-L37 | `SessionActivityController.php:96-102` | Any role with `force_logout` can repeatedly end all of a superadmin's sessions. | Refuse admin targets unless the caller is an admin. |
| R3-L38 | `Migration1758000001…`, `Migration1789383427…`, `Migration1789390512…` | `ADD COLUMN` with no existence check, unlike the later migrations, so a restore with a stale `migration` table fails. | Add the `SHOW COLUMNS` guard. |
| R3-L39 | `RedisConnectionFactory.php:101` | The 30 s "Redis is down" marker only works with APCu. Without APCu, every request stalls 1.5 s during an outage (N-L14 residue). | Fall back to a static or file marker. |
| R3-L40 | `Sw6OidcSessionActivity*` (`sid`, `sub`) | `sid` (a logout capability, N-M5) is stored and never read. | Drop the column, or store a hash. |

### Admin auth, DAL, config, logging, tooling

| # | Where | Issue | Fix |
|---|---|---|---|
| R3-L41 | `StepUpController::startOidc`/`passkeyOptions`, `OidcAdminAuthController::startLink` | Authenticated flow starts write one-time-token rows with no `flow_start` budget. | `consume(SCOPE_FLOW_START.':'.$userId, ip)`. |
| R3-L42 | `Sw6OidcEncryptor.php:80-85` via the field serializer `decode()` | After an `APP_SECRET` change, every provider hydration logs two errors: on every login render, every password attempt and every admin listing. | Log once at the point of use (`getUsableClientSecret`). |
| R3-L43 | `Sw6OidcProviderDefinition.php:88-113`, RoleMapping, UserProvider | No `Choice` on `pkce_flow`, `login_type`, `mapping_type` or `user_type`; `s256` breaks every login. `claim_encoding` is `Required` but dead. `http_timeout` (SMALLINT) and `jwks_cache_ttl` have no bounds (40000 gives a DB 500, a negative value disables the cache). NOT NULL booleans lack `Required` (null gives a 500). `sw6oidc_user_provider.updated_at` exists but has no `UpdatedAtField`. Provider delete cascades bindings at the DB level with no DAL event or warning. | Add `Choice`/`Range`/`Required`. Move dead fields to defaults. Add the field. Add an association with `RestrictDelete` or a confirmation. |
| R3-L44 | `OidcProviderAdminController.php:129, 177`; `OidcConnectionTestService.php:246` | `httpTimeout` is uncapped in the test endpoints. A non-string JSON value causes a `TypeError` (500). The test decrypts the secret only to check that it is non-empty. | Clamp 1–30 s, cast inputs, and pass a bool. |
| R3-L45 | `Service/Logging/SensitiveDataProcessor.php:22-43, 61, 88` | Does not mask: a leading `code=`, JSON bodies, `Bearer`/`Basic` headers, URL-encoded URLs, `;`-separated params, keys like `contextToken`, `sessionKey`, `jwt`, `cookie`, `apiKey`, non-string values, or exception objects. Current callers avoid these cases, so it is latent. | Add the patterns and keys. |
| R3-L46 | `services.xml:708, 798-809` | Twig extensions are eager: the login-options extension builds the passkey and webauthn graph on every Twig boot, mail rendering included. The write guard on the global `PreWriteValidationEvent` isn't `lazy`. `pendingRevocations` is filled even when the write later fails. `OidcLogger` is a fake FQCN service id. Encryption purpose strings are duplicated as literals. | Use `twig.runtime` and `lazy`, and clear state on write failure. |
| R3-L47 | `Twig/StorefrontLoginOptionsExtension.php:60-90` | Claimed fixed, but not: three un-memoised lookups (each decrypting two envelopes per provider) on every login render. | Memoise per request. |
| R3-L48 | `Service/Oidc/TestResultTranslator.php:21` | Reads the **admin source** snippet JSON at runtime, so a package without `Resources/app` shows raw keys. | Ship Symfony translations. |
| R3-L49 | `services.xml:482-508, 678`; `AdminOidcGrant.php:98` | Relies on core internals: `FakeCryptKey` (`@internal final`); `ClientRepository`, `AccessTokenRepository`, `ScopeRepository`, `RefreshTokenRepository`, `UserRepository` and `OAuth\User\User` (`#[BecomesInternal('v6.8.0')]`); `ScopeRepository::PASSWORD_GRANT`; and `Context::createDefaultContext()` in subscribers and controllers. | Track these for 6.8. Inject interfaces, and pass `Context` down from the callers. |
| R3-L50 | `OidcAdminAuthController.php:581` / `PasskeyAdminController.php:263`; `AdminPasswordLoginGuardSubscriber.php:32` / `PasswordLoginDisabledException.php:20`; `'admin'` literal in four places | Duplicated `accessTokenJti()`, `ERROR_CODE` and user-type constants. | Share them. |
| R3-L51 | `composer.json:74-78`; `.github/workflows/ci.yml:200, 270` | `policy.advisories.block: false`. Actions pinned by tag, not SHA, with no `permissions:` block. `assets` and `e2e` are `continue-on-error`, so a stale bundle (F-H1) and R3-H1 never fail CI. Static analysis runs on 8.3 only, at PHPStan level 5, with no Shopware extension and no analysis of `tests/`. | Make both jobs blocking. Pin SHAs. Raise analysis levels. |
| R3-L52 | `CLAUDE.md` | Out of date: the registry "kept for the real session lifetime" (R3-H5); binding by `(provider, iss, sub)` (R3-M9); "19 attribute types" (the code has 22); "the passkey list links to the owner profile" (it doesn't); `findOneByCredentialId`/`assertNotBoundToDifferentProvider` described as live; L13 deferred "because the container can't be compiled", although CI already compiles it. | Update the file. |

---

## Unused code

| Symbol | Where | Evidence |
|---|---|---|
| `SsrfUrlValidator::isInsecureModeEnabled()` | `Service/Security/SsrfUrlValidator.php:34` | No caller in `src/` or `tests/`. **Delete it.** |
| `Sw6OidcProviderEntity::getClaimEncoding()` and the `claim_encoding` field | entity / definition `:89` | Never read since M10, but still `Required`, so every client must send a dead field. The admin JS still sets `provider.claimEncoding = 'none'` (`provider-detail/index.js:388`). |
| `PasskeyCredentialRepository::findOneByCredentialId()` | `:68` | Called from tests only. |
| `UserProviderBindingService::assertNotBoundToDifferentProvider()` | `:63-73` | Called from tests only. |
| `Sw6OidcSessionRegistry::resolveByUser()` | `:132` | Called from tests only (12 references). |
| `OidcCustomerLoginRoute::login(RequestDataBag)` + `AbstractLoginRoute` inheritance + `getDecorated()` | `Storefront/Service/OidcCustomerLoginRoute.php:49-58` | No caller. It is also a hazard: a passwordless "log in as any `customerId`" entry point. Drop the inheritance and keep only `loginByCustomerId()`. |
| `AdminLoginNonce::$browserBinding` | `Service/AdminAuth/AdminLoginNonce.php:18` | Checked before the DTO is built and never read afterwards. |
| `AdminTokenIssuer` `$request->request->set(...)` / `$request->attributes->set(...)` | `:34-38` | The first has no effect for JSON (R3-H1). The second duplicates `withAttribute()`. |
| `sign_count` column, `Sw6OidcPasskeyCredentialEntity::getSignCount()` | definition `:50` | Written, never read. The real counter lives in the `public_key` JSON, so this is a drifting copy. |
| `PasskeyRegisteredEvent::getUserType()` / `getUserId()` | `Event/` | No callers (public API, acceptable). |
| `CustomerProvisioningService::PLACEHOLDER_ADDRESS_FIELD` | `:182-184` | Written, never read (R3-M16). |
| `UserProvider.issuer` | binding table | Written, never compared (R3-M9). |
| `Sw6OidcSession::$createdAt`, `$salesChannelId`; the registry's `$customerTtlSeconds` constructor parameter | `Service/Session/` | Hydrated but never read; the parameter is never set through DI. |
| `sw6oidc_session_activity` indexes `session_key_hash`, `registry_session_id` | `Migration1790800003…` | No query uses them (R3-L31). |
| Constructor defaults `'PT10M'` (`AdminAuthorizationServerFactory`), `'P1W'` (`AdminOidcGrant`) | | DI always injects real values. The defaults only hide misconfiguration. |
| Public-but-internal methods | `OidcSecurityHelper::deriveCodeChallenge`, `OidcCallbackResult::subject`, `RpInitiatedLogoutService::revokeToken`, `SsrfUrlValidator::isPublicIp`, `Sw6OidcRateLimiter::clientKey`, `destroyCustomerSession`, `destroyAdminSessions` | Make them `private`. |
| Admin snippets `sw6oidc.login.{error, errorRoleMissing, errorAutoCreateDisabled, passwordLoginDisabled, errorAccessDenied, errorLinkRequired, errorEmailNotVerified, errorProviderMismatch}` | `app/administration/src/snippet/*` | Duplicates of `sw-login.sw6oidc.login.*`, unused. |
| Storefront snippet `sw6oidc.passkey.reauthRequired` | | Unused (PHP uses `sw6oidc.account.reauthRequired`). |
| Stale comments | `GenderMapper` ("SalutationResolver" doesn't exist), `attributeTypeColumnWidth` (`:style` binding gone), `sw-login` ("no narrower block"), `sw-profile-index-general` ("image card"), two "VERIFICATION NEEDED" blocks, `DatabaseAtomicCache` docblock (still mentions id_tokens) | Delete or correct them. |

---

## Revision 2 findings: re-verification

| Status | IDs |
|---|---|
| **Verified fixed** | C1, C2 (core; residue in R3-M9, R3-M10), F-C1, H1 (residue R3-L28), H2, H4 (residue R3-L2), H6, H7 (admin), H9, H10, H11, N-H1, F-H2, F-H3, F-H4, F-H6 (server; client R3-F5), M1 (residue R3-L3), M2, M3, M4, M5, M6, M7, M8, M9, M10 (residue R3-L6), M11, M13, M14, M15, M17, M18, M19, M20, M22, N-M1, N-M2, N-M3, N-M4, N-M5, N-M6, N-M7, N-M8, N-M9, N-M10, N-M11, N-M13, N-M14, N-M15, N-M16, N-M17, F-M1, F-M3–F-M13, F-M15, F-N2, F-N4–F-N6, F-N9–F-N17, N-L1–N-L21 (N-L14 residue R3-L39), L1–L12, L14, L16–L18, most rev-1 frontend Lows |
| **Regressed** | **N-H2** (the per-`kid` cooldown is now an anonymous fetch amplifier, R3-M1), **F-N1** (the same-admin check never matches, R3-F4) |
| **Incomplete** | **N-H3** (R3-H5), **H3** (R3-M11), **H7** storefront (R3-M5, R3-H6), **H8.3–H8.6** (R3-H7, R3-M20), **M12** (R3-M2), **M16** (R3-M16), **M21** (R3-M12), **N-M12** (R3-M4), **F-H1** (bundles committed, but the freshness job can't fail CI, R3-L51), **F-H5 / F-N7** (R3-F2), **F-N3** (R3-F13), **F-N8** (R3-F14), **F-M10** (R3-F12), "Twig login-options memoisation" (R3-L47) |
| **Partial, as stated in rev 2** | F-M2 (full template copy plus re-sync checklist), F-M14 (one bundle plus polling loop) |
| **Deferred** | L13 (autowiring). The stated reason no longer holds, because CI compiles the container. |
| **Now verifiable** | L15. PHPCS, PHPStan, Psalm, Rector and 699 unit tests pass in this checkout. |

---

## Edge cases that break the plugin today

| Input or situation | Result |
|---|---|
| Admin clicks "confirm with passkey" or "confirm with SSO" in core's verify modal | `unsupported_grant_type`. In SSO-only mode no `user-verified` action is possible (R3-H1). |
| Admin logs in with a passkey | Fails, and the button spins forever on a refusal (R3-H1, R3-F5) |
| Open any OIDC provider in the Administration | Page crashes (R3-F1) |
| Role with `sw6oidc_passkey_credential:update` PATCHes `userId` on its own key | Its next passkey login is as that user, superadmins included (R3-H2) |
| Role with `sw6oidc_user_provider:update` PATCHes a superadmin's `sub` | Logs in as that superadmin (R3-H3) |
| Provider editor points userinfo at its own host and drops `openid` | Logs in as any bound admin (R3-H4) |
| Admin active for more than 7 days, IdP sends back-channel logout | 200 OK, session not ended (R3-H5) |
| Stolen customer session, then `POST /sw6oidc/link` | Attacker's IdP account permanently bound (R3-H6) |
| Provider with the admin flag re-activated after its only bound admin was deactivated | SSO-only on, nobody can log in (R3-H7) |
| Logout tokens with random `kid` values, a few per second | Unlimited JWKS fetches against the IdP. The breaker can block logins (R3-M1). |
| Token endpoint answers 307 to another host | `code` and `code_verifier` re-POSTed there (R3-M2) |
| Keycloak as IdP, user clicks logout | Stuck on Keycloak's logout page, no return to the shop (R3-M3) |
| `{publicClient:true, tokenEndpoint:evil}`, then `{publicClient:false}` | Client secret sent to `evil` on the next login (R3-M4) |
| A lowercase transform added on the email mapping | Every login refused while `require_email_verified` is on (R3-M15) |
| Two tabs finishing the first SSO login at the same time | Orphan duplicate customer, and password login hits the wrong account (R3-M12) |
| Customer bound to channel A logs in by SSO in channel B | Unbound duplicate created, password login in A broken (R3-M13) |
| Redis `READONLY` after a failover, IdP sends back-channel logout | Treated as a replay, 200 OK, nothing ended (R3-M18) |
| Customer deletes one of three passkeys on the storefront | Usually the last one is deleted, not the chosen one (R3-F3) |
| `GET /sw6oidc/login?providerId=` | 500 instead of a flash message (R3-L1) |
| `redirectTo=/foo%0A` | Redirect without a `Location` header (R3-L2) |
| `given_name` of 300 characters with profile sync on | Every login of that user fails (R3-L21) |

---

## Future improvements (optimisation and hardening roadmap)

Done since revision 2 and dropped from this list: the identity model, DB-backed state, one step-up primitive, grant-level password enforcement, rate-limiter redesign, access-rule semantics, Store API logout URL, admin API client and ACL file, E2E suite.

1. **Make the test suite catch what matters.**
   - Controller-level integration tests with a real `AuthorizationServer` and JSON bodies for `/admin/token`, `/step-up/*` and `/passkey/login-verify` (R3-H1).
   - An admin smoke spec that opens every plugin page as viewer and as editor with zero console errors (R3-F1), and grants a non-admin role through the role editor (R3-F2).
   - Make `assets` and `e2e` **blocking**.
2. **Protect trust-relevant DAL data by default.** Mark every field of `passkey_credential`, `user_provider` and the trust-relevant provider columns as `WriteProtected(SYSTEM_SCOPE)`, or superadmin-only. Add `CloneProtection`. Audit-log changes to endpoints, scope and superadmin mapping, and optionally email the superadmins when they change (R3-H2, R3-H3, R3-H4, R3-M4).
3. **Lazy secret decryption.** Keep the envelope in the entity and decrypt only via `Sw6OidcSecretProvider::clientSecret($provider)` at the point of use. Keep secrets out of `jsonSerialize()`. That removes the clone exfiltration, the decrypt-log flood (R3-L42) and the in-memory plaintext copies.
4. **Tie session state to core's session state.** Derive registry liveness from `refresh_token` and `sales_channel_api_context` instead of a fixed TTL (R3-H5, R3-L30). Process IdP logout as a queued message with retries (R3-M19).
5. **One SSO-only invariant.** A single check, run from every write that can change the result (provider, user, binding), plus a `ClientRepository` decorator for user access keys (R3-H7, R3-M20).
6. **A real `(issuer, sub)` key.** Use binary columns, compare `iss` in every lookup, and add an "issuer changed" guard that suspends bindings (R3-M9).
7. **JIT creation through core's registration rules.** Bound sales channel, IDN encoding, newsletter, and create + bind + events in one transaction with a re-resolve on a unique violation (R3-M12, R3-M13, R3-L20).
8. **Declarative, multi-valued role sync.** Map every matching group, touch only roles the plugin owns, and lock the superadmin rows while revoking (R3-M11).
9. **Passkey hardening.**
   - Purpose-bound ceremonies (R3-M7) and a per-surface RP ID (R3-M8).
   - Record the credential id on activity rows, so deleting a key ends exactly its sessions (R3-M6).
   - Conditional mediation (`autocomplete="username webauthn"`).
   - `PasskeyCredentialDeletedEvent` and a clone-detection Flow Builder trigger.
   - One shared ceremony service, so the admin and storefront paths stop drifting.
10. **Bounded HTTP layer.** Response-size caps on token, userinfo and JWKS responses. Keep `error`/`error_description` from 4xx responses for diagnostics. Use separate clients for the IdP (relaxable in dev) and for user content (`picture` URLs, always guarded).
11. **Protocol defaults.** Enforce `S256` PKCE unless discovery lacks it. Check `typ: logout+jwt` when the IdP sets it. Detect Authelia explicitly (R3-M3).
12. **Shopware 6.8 readiness.** Remove `@internal` and `BecomesInternal` dependencies (R3-L49). Migrate `sw-*` components to `mt-*` and `$tc` to `$t`. Domain exceptions via `HttpException`. Use `twig.runtime` for the Twig extensions.
13. **Static analysis.** PHPStan level 8 with `phpstan/phpstan-shopware` and DAL generics (52 errors today). Psalm `findUnusedCode` with a DI-aware baseline, so the [Unused code](#unused-code) list can't grow back.
14. **Configuration hygiene.** Move the `SW6OIDC_*` env vars into a `config/packages/sw6oidc.yaml` bundle configuration with a real `Configuration` tree. Switch `services.xml` (≈900 lines) to autowiring now that CI compiles the container.
15. **Frontend architecture.** A small pre-auth bundle for `sw-login` and the inactivity modal, with everything else through `loadPlugins()`. That removes the polling hacks and the early-execution timing class (F-M14, R3-F2). Show the `PublicError` correlation reference in toasts instead of English server text.

---

## Suggested fix order

1. **R3-H1, R3-F1, R3-F5**: make step-up, passkey admin login and the provider page work. Add the controller and smoke tests, and make `e2e` and `assets` blocking. *Without this, SSO-only mode blocks every action that needs a `user-verified` token.*
2. **R3-H2, R3-H3, R3-H4, R3-M4**: write-protect trust data. Decide who may edit provider trust fields.
3. **R3-H6, R3-M5, R3-M6, R3-F3**: fresh-auth proof for storefront link and passkey registration. Deleting a key ends its sessions. Fix the wrong-key delete.
4. **R3-H7, R3-M20, R3-M22**: one SSO-only invariant, and a client-repository decorator for user access keys.
5. **R3-H5, R3-M18, R3-M19, R3-M17**: make IdP-initiated logout reliable. Bound the one-time-token table.
6. **R3-M9 – R3-M16**: identity key with issuer and binary `sub`, legacy upgrade, role sync, first-login transaction, sales-channel binding, email transform, placeholder addresses.
7. **R3-F2, R3-F4, R3-M1, R3-M2, R3-M3**: ACL registration, inactivity resume, JWKS amplifier, redirects, Keycloak logout.
8. **R3-M7, R3-M8, R3-M21, R3-M23, R3-M24**: then the Low tables, unused code and the roadmap.

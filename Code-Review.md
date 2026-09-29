# Code Review: `martinkuhl/shopware6-oidc-sso` v0.1.0

**Review date:** 2026-09-29
**Scope:** everything under `src/` (PHP, DI config, migrations, admin/storefront JS and Twig), plus `composer.json` and the CI/tooling config. The build output in `src/Resources/public` and `dist/` was only checked for staleness.
**Verified against:** Shopware `6.7.14.2` as installed in `/var/www/html/vendor` (OAuth grants, `ClientRepository`, `UserRepository`, `CustomerDefinition`).
**Reviewer stance:** harsh on purpose. Assume every finding reaches production unless it is fixed.

> **Note on "Magento best practices":** this is a Shopware 6 plugin, so it is reviewed against **Shopware 6.7 / Symfony 7 conventions** (DAL, ACL, route scopes, decoration, flows, `services.xml`). Where the code copies a pattern from the sibling Magento module and that pattern does not fit Shopware, the finding says so.

Paths are relative to `src/`, and line numbers point to the current working tree.

---

## Summary

| Severity | Backend (PHP/config) | Frontend (JS/Twig) | Theme |
|---|---|---|---|
| Critical | 2 | 1 | Account takeover through email-based linking; password re-confirmation bypassed across Users & Permissions |
| High | 11 | 6 | Auth bypasses, broken privilege revocation, open redirect, secret exposure, weak passkey policy, uncommitted build, missing ACL |
| Medium | 22 | 15 | CSRF/login fixation, token/claim validation gaps, SSRF, DoS, info leaks, Shopware conventions, broken listings |
| Low | 18 | 13 | Dead code, deprecations, naming, schema sizing, noise |

**Verdict: not production-ready.** The three Critical findings (C1, C2, F-C1) and at least H1–H5 must be fixed before the plugin runs on any shop that has real admin accounts. Most of the problems come from one design choice: the plugin trusts **the email claim of any active provider** as proof of identity for **any pre-existing account**, including admins and superadmins. There are also no brakes afterwards: no `email_verified` check, no login-type check, no `active` check, and no re-authentication.

---

## Critical

### C1. Any active provider can log into the Administration, even a customer-only IdP
- **Where:** `Service/Provider/ProviderResolver.php:27-36` (`getActiveById`), `Controller/Api/OidcAdminAuthController.php:106-109`, `Storefront/Controller/SendAuthorizationRequestController.php:42-44`, `Service/Oidc/OidcCallbackProcessor.php:54-65`
- **What's wrong:** `getActiveById()` only checks `isActive`. It never checks `loginType`. `GET /api/sw6oidc/admin/login?providerId=<id>` accepts the id of a provider configured as `login_type = customer`. The callback pipeline never compares `$flow->loginType` with the endpoint that consumed it, and `findOrCreateAdmin()` never checks the provider's login type either. Customer IdPs are usually the weak ones: social login, self-registration realms, B2C tenants. Through this path they become an admin login path.
- **Attack:** the attacker self-registers `admin@shop.tld` at the customer IdP (many IdPs allow unverified emails, see C2). They open `/api/sw6oidc/admin/login?providerId=<customer-provider-id>`. `findOrCreateAdmin()` finds the existing admin by email, binds it (the account is unbound, so first login wins), and issues a full admin token pair. `auto_create_admin = false` does not help, because the existing-user branch runs before that check.
- **Fix:** enforce login type on both ends:
  ```php
  public function getActiveById(string $providerId, string $loginType, Context $context): Sw6OidcProviderEntity
  {
      $criteria = (new Criteria([$providerId]))
          ->addFilter(new EqualsFilter('isActive', true))
          ->addFilter(new EqualsAnyFilter('loginType', [$loginType, 'both']));
      // ...
  }
  ```
  Also assert `$flow->loginType === 'admin'` in the admin callback and `=== 'customer'` in the storefront callback. Pass the expected login type into `OidcCallbackProcessor::process()` so the check lives in the shared pipeline.

### C2. Unverified email claims auto-link pre-existing accounts, admins and superadmins included
- **Where:** `Service/Provisioning/AdminProvisioningService.php:46-70`, `Service/Provisioning/CustomerProvisioningService.php:50-69`, `Service/Provisioning/AttributeMapper.php` (email read, no `email_verified`)
- **What's wrong:** there is **no `email_verified` check anywhere** in the codebase (`grep -rn email_verified src/` returns nothing). Any existing `user`/`customer` whose email matches the claim is:
  1. silently bound to whichever provider authenticates first (`bindIfUnbound`, "first login wins", **permanently**), and
  2. logged in.

  This includes the local superadmin created at installation, and every customer that existed before the plugin was installed. Binding is permanent, so the real owner is locked out of SSO afterwards (`ProviderMismatchException`).
- **Attack:** as in C1. Even with C1 fixed, any admin-scoped IdP that lets users set or change their own email (Keycloak with "edit username/email" enabled, Authentik and Zitadel defaults in some setups, any IdP federating social logins) gives a direct takeover of every unbound Shopware account.
- **Fix:**
  - Reject logins unless `email_verified === true` (make it configurable per provider, default **on**). Treat a missing claim as unverified.
  - **Do not auto-link pre-existing accounts by email at all.** Require an explicit opt-in per provider (`link_existing_accounts`, default off). Better: require the user to prove ownership of the local account once (password login, then "connect SSO" in the account settings). At minimum, never auto-link `admin = true` users.
  - Bind on the IdP's `iss` + `sub`, not on email. Add `subject` (and `issuer`) columns to `sw6oidc_user_provider`, look users up by `(provider_id, subject)` first, and use email only for the explicit linking step. Email is mutable at the IdP; `sub` is not.

---

## High

### H1. Deactivated or deleted admins still get valid tokens
- **Where:** `Service/AdminAuth/AdminOidcGrant.php:91-100` (`validateUser`), `Controller/Api/OidcAdminAuthController.php:152`, `Controller/Api/PasskeyAdminController.php:161-190`
- **What's wrong:** `validateUser()` wraps any non-empty string in `new ShopwareOAuthUser($userId)`. Nothing checks that the user exists or that `active = 1`. Shopware's own password grant (`vendor/shopware/core/Framework/Api/OAuth/UserRepository.php:65`) explicitly refuses inactive users. Neither the OIDC path (`findOrCreateAdmin` returns inactive users as they are) nor the passkey path (which never loads the user) does. Offboarding an admin by deactivating them in Shopware does not stop SSO or passkey logins. Passkeys also survive user deletion (see M15), so a deleted admin's passkey still mints a token for a user id that no longer exists.
- **Fix:** in `validateUser()`, load the user through a `Connection` query (`SELECT active FROM user WHERE id = :id`). Throw `OAuthServerException::invalidGrant()` if the user is missing or inactive. Doing it in the grant covers every caller: OIDC, passkey and `verifySession`.

### H2. `verify-session` turns any bearer token into a `user-verified` token
- **Where:** `Controller/Api/OidcAdminAuthController.php:282-324`
- **What's wrong:** Shopware's `user-verified` scope is the re-authentication gate for sensitive operations (changing own email/password, and for `user:editor`s creating and editing users). This endpoint mints it for **any** caller that holds a valid access token and has at least one binding **or one passkey** (`isSsoProvisioned`). A stolen 10-minute access token (XSS, leaked log, malicious extension) becomes a `user-verified` token **plus a fresh 1-month refresh token** (see H10). The re-authentication control becomes decoration. The passkey branch also means a normal password admin who registered a passkey once can skip password reconfirmation forever.
- **Fix:** require a **fresh** proof of authentication. Either run a real OIDC round trip with `prompt=login` and `max_age=0` and check `auth_time`, or run a passkey assertion with `userVerification: required` immediately before minting. Mint only an access token (no refresh token) with a short TTL.

### H3. "Role sync" only ever adds ACL roles, so revoking a role at the IdP does nothing
- **Where:** `Service/Provisioning/AdminProvisioningService.php:163-184`
- **What's wrong:** `userRepository->update([['id' => $userId, 'aclRoles' => [['id' => $aclRoleId]]]])` is a many-to-many **upsert**: the DAL inserts the mapping row and keeps the existing ones. An admin demoted from "Shop Admin" to "Content Editor" at the IdP ends up with **both** roles. The superadmin flag is never revoked either (a documented choice, but combined with this it means privileges only ever grow). `CLAUDE.md` says the sync "overwrites the user's ACL roles", and it doesn't.
- **Fix:** replace instead of append. Delete from `acl_user_role` for the user, then insert the resolved role, in one transaction, or use `acl_user_role.repository` to `delete` the stale ids. Offer an opt-in "revoke superadmin when the group no longer matches" setting. Keep the anti-lockout guard by refusing to remove the **last** superadmin rather than never revoking.

### H4. Open redirect after login through `redirectTo`
- **Where:** `Storefront/Controller/SendAuthorizationRequestController.php:39`, `Storefront/Controller/OidcCallbackController.php:115-132`
- **What's wrong:** `resolveSafeRelayState()` only blocks a leading `//` and URLs that have both a scheme and a host. These all get through unchanged:
  - `/\evil.tld`: browsers normalise `\` to `/`, so this becomes `//evil.tld`.
  - `http:evil.tld` (on an https shop): `parse_url` gives scheme with no host; browsers resolve it to `http://evil.tld`.
  - Leading tabs or newlines, for example `%09//evil.tld`.

  The result is a clean phishing chain: "Log in with SSO" on the real shop, then land on the attacker's page.
- **Fix:** accept only a relative path matching `^/(?![/\\])` with no control characters. Alternatively resolve it with Shopware's `RequestTransformer`/router and accept only a known route name plus parameters. Validate when the request is built as well as in the callback.

### H5. `client_secret` is readable in plaintext through the Admin API
- **Where:** `Core/Content/Provider/Sw6OidcProviderDefinition.php:55`
- **What's wrong:** the field is `ApiAware`, so every holder of `sw6oidc_provider:read` gets the confidential client secret back from `/api/search/sw6oidc-provider` and from the admin detail page's JS model. It is also stored unencrypted (TODO at line 53). A viewer-level role becomes a way to impersonate the shop at the IdP.
- **Fix:** encrypt at rest with a custom `FieldSerializer` (libsodium, key derived from `APP_SECRET`). **Never return it on read**: strip it in an `EntityLoadedEvent`/`sw6oidc_provider.loaded` subscriber when the source is `AdminApiSource`, and expose `hasClientSecret: bool` instead. Write it through a dedicated action endpoint that requires `sw6oidc_provider:update`.

### H6. Passkey logins don't require user verification, and the enable toggles are not enforced
- **Where:** `Service/Passkey/PasskeyAuthenticationService.php:38-43`, `Service/Passkey/WebauthnCeremonyFactory.php:56-61`, `Storefront/Controller/PasskeyController.php:57,101,116`, `Controller/Api/PasskeyAdminController.php:57,146,161`
- **What's wrong:**
  - `userVerification` is left at the default `preferred`. webauthn-lib only enforces the UV flag when it is `required`. For a primary, passwordless **admin** login, that means a stolen security key with no PIN, or an unlocked phone, is enough.
  - None of the passkey endpoints check `PasskeyConfig::isEnabledForAdmin()` / `isEnabledForCustomer()`. The toggles only hide buttons. Turning the feature "off" after an incident keeps every registered passkey working.
- **Fix:** set `userVerification: required` in both `PublicKeyCredentialRequestOptions` and `AuthenticatorSelectionCriteria`. Add a guard at the top of all six actions: `if (!$this->passkeyConfig->isEnabledForAdmin()) return 404;`.

### H7. Registering a passkey needs no re-authentication, so a hijacked session can plant a permanent backdoor
- **Where:** `Controller/Api/PasskeyAdminController.php:57-90`, `Storefront/Controller/PasskeyController.php:57-93`
- **What's wrong:** any valid admin access token (10 minutes) or customer session can register a new discoverable credential. That turns a short-lived session compromise into permanent account access that survives password resets and SSO unbinding.
- **Fix:** require a `user-verified` scope, a fresh OIDC or password re-auth, or an existing passkey assertion before `registration-options`. Send a notification email ("A new passkey was added to your account"). Log it at `info` with the user id.

### H8. The "Disable non-OIDC login" toggles do nothing
- **Where:** `Migration/Migration1730000001CreateOidcSchema.php:39-40`, `Core/Content/Provider/Sw6OidcProviderDefinition.php:71-72`, `Resources/app/administration/src/module/sw6oidc-provider/page/sw6oidc-provider-detail/sw6oidc-provider-detail.html.twig:288-295`
- **What's wrong:** no PHP or JS reads `disableNonOidcAdminLogin`/`disableNonOidcCustomerLogin`; the only hits are the entity, the definition and the form. An admin who switches it on believes password login is disabled, while `/api/oauth/token` with `grant_type=password` and `/account/login` still accept passwords. A security control that silently does nothing is worse than no control.
- **Fix:** either remove the fields from the UI and schema now, or implement them. For the admin side, block the `password` grant in a `KernelEvents::REQUEST` listener on `api.oauth.token` for users bound to such a provider (keep a break-glass local superadmin). For the storefront side, decorate `AbstractLoginRoute`, which is the right use of decoration here.

### H9. Admin login nonce and OIDC `state`/`nonce` are logged at the default level
- **Where:** `Resources/config/services.xml:8` (`default_log_level = debug`), `Controller/Api/OidcAdminAuthController.php:162-165` (`redirectUrl` contains `sw6oidc_nonce`), `Service/Oidc/AuthorizationRequestBuilder.php:50-60` (`authorizeUrl` contains `state`/`nonce`), `Service/Oidc/OidcCallbackProcessor.php:119-123` (email, groups)
- **What's wrong:** the default log level is **debug in production**. The admin hand-off nonce is a bearer credential: whoever redeems it first within 120 seconds gets an admin token pair. It is written in full to `var/log/sw6oidc-prod.log`. `SensitiveDataProcessor` masks only by key name (`code`, `token`, and so on) and never looks inside URL values. Personal data (email, groups) is also logged on every login.
- **Fix:** default to `info` (or `warning`). Never log URLs that carry credentials; log `hasNonce: true` instead. Extend `SensitiveDataProcessor` to strip `state`, `nonce`, `sw6oidc_nonce`, `code`, `id_token_hint` from query strings inside string values. Treat email and groups as personal data (hash them, or log only at debug behind the `debugLoggingEnabled` toggle, which is currently unwired, see L4).

### H10. Token lifetimes are hard-coded and ignore the shop's own configuration
- **Where:** `Service/AdminAuth/AdminOidcGrant.php:48` (`P1M`), `Service/AdminAuth/AdminAuthorizationServerFactory.php:23` (`PT10M`)
- **What's wrong:** Shopware 6.7 reads `shopware.api.access_token_ttl`/`refresh_token_ttl` (`ApiAuthenticationListener::setupOAuth`, default refresh `P1W`). The plugin ignores both, so SSO sessions live **4× longer** than password sessions, and a hardened shop's shorter TTLs do not apply to SSO users. There is no back-channel logout (documented gap), so disabling a user at the IdP leaves them a month of admin access. Every silent refresh extends it further, because Shopware's own refresh grant issues a new refresh token.
- **Fix:** inject `%shopware.api.access_token_ttl%` and `%shopware.api.refresh_token_ttl%` into the factory and the grant. Optionally re-validate against the IdP on refresh (store the IdP refresh token, call `TokenExchangeService::refreshAccessToken()`, which exists but is unused, see L4). Also call Shopware's `SsoService::revokeUserTokens()`-equivalent logic when a user is deactivated.

### H11. Storefront logout subscriber overwrites any response, including Store API JSON
- **Where:** `Storefront/EventSubscriber/CustomerLogoutSubscriber.php:38, 48-51, 85-96`
- **What's wrong:**
  - `CustomerLogoutEvent` is dispatched by the core `LogoutRoute`, which the **Store API** (`/store-api/account/logout`) uses too. Whenever a `LogoutContext` exists for that context token, the subscriber replaces the JSON response with a 302 to the IdP, which breaks headless and PWA clients.
  - `$pendingLogoutUrl` is **request state held in a shared service**. Under FrankenPHP, RoadRunner or Swoole workers, a URL set in one request and never flushed (for example on a sub-request or exception path) is emitted on the **next user's** response.
- **Fix:** only rewrite when `$request->attributes->get('_route') === 'frontend.account.logout.page'`. Store the pending URL on the `Request` attributes instead of the service. Implement `Symfony\Contracts\Service\ResetInterface` on every stateful service (see M20).

---

## Medium

### M1. Login CSRF and session fixation: `state` and the admin nonce aren't bound to the browser
- **Where:** `Service/Security/OidcSecurityHelper.php:29-52`, `Service/AdminAuth/AdminLoginNonceService.php:24-40`
- **What's wrong:** `state` is only a server-side cache key, with no cookie binding. An attacker can finish their own IdP login up to the callback and send the victim `/sw6oidc/callback?code=…&state=…`, which logs the victim into the attacker's customer account (they then save addresses or card data into it). Similarly, `/admin#/login?sw6oidc_nonce=…` logs a victim into the attacker's admin session.
- **Fix:** set an `HttpOnly; Secure; SameSite=Lax` cookie with `hash(state)` when the flow starts and compare it in the callback. Do the same for the admin nonce (set it in the callback response and check it in `exchangeNonce`).

### M2. Userinfo claims override ID token claims without a `sub` check, and `id_token` is optional
- **Where:** `Service/Oidc/OidcCallbackProcessor.php:80-102`
- **What's wrong:** OIDC Core §5.3.2 says the `sub` in the UserInfo response **MUST** be checked against the ID token's `sub`. It isn't, and `array_merge` lets userinfo overwrite **`email`** from the signed token. When the token response has no `id_token`, the flow goes ahead with no signature, no nonce and no audience check at all. That means a misconfigured `scope` (without `openid`) or a malicious or mixed-up token endpoint silently downgrades the protocol to plain OAuth2.
- **Fix:** require `id_token` whenever `scope` contains `openid`, with no silent fallback. If userinfo is used, assert `userinfo.sub === idToken.sub` and let the **ID token win** for `sub`, `email` and `email_verified`.

### M3. A retried POST replays the single-use authorization code
- **Where:** `Service/Http/OidcHttpClient.php:104, 132-143`
- **What's wrong:** the retry fires on a transport exception. The first POST may already have reached the IdP (timeout on read). The retry then re-submits the same `code`, and RFC 6749 §4.1.2 tells the IdP to **revoke every token issued for it** (Keycloak does). `usleep(500_000)` also blocks a PHP-FPM worker.
- **Fix:** never retry non-idempotent POSTs. Keep retries for GETs (JWKS, discovery, userinfo) only.

### M4. The default "atomic" cache isn't atomic, and flows disappear on `cache:clear`
- **Where:** `Service/Cache/CachePoolAtomicCache.php:28-40`, `Resources/config/services.xml:42-45`
- **What's wrong:** get-then-delete on `cache.app` is racy even on a single node with multiple FPM workers, so two parallel callbacks can redeem one `state` or admin nonce. `cache.app` is also the pool that `cache:clear`, deployments and cache invalidation wipe, which kills in-flight logins, passkey ceremonies and all 24h `LogoutContext`s. `RedisAtomicCache` exists but is never wired, and its `fallback` path (`RedisAtomicCache.php:48-52`) reads from a different store, so it can never find the value.
- **Fix:** use a dedicated pool (`framework.cache.pools.sw6oidc.tokens`, adapter configurable, Redis in production), or a small DB table with `DELETE … WHERE key = ? AND expires > NOW()` and check affected rows = 1. That delete is truly atomic on every setup and survives cache clears. Wire the Redis implementation with a factory and drop the bogus fallback.

### M5. Unauthenticated endpoints write cache entries with no rate limiting
- **Where:** `GET /sw6oidc/login`, `GET /api/sw6oidc/admin/login`, `POST /sw6oidc/passkey/login-options`, `POST /api/sw6oidc/admin/passkey/login-options`, `POST /api/sw6oidc/admin/token`, both `login-verify` endpoints
- **What's wrong:** each anonymous request stores a 300–600 s entry. A simple loop fills `cache.app` or Redis, which evicts Shopware's own cache and hurts the whole shop. Nothing is throttled, so nonce and assertion attempts can be hammered.
- **Fix:** use Shopware's `RateLimiter` (`shopware.api.rate_limiter`, the same one as `login`/`oauth`). Register `sw6oidc_login` and `sw6oidc_passkey` policies keyed by IP and apply them in every anonymous action.

### M6. Account and feature enumeration on the anonymous admin endpoints
- **Where:** `Controller/Api/PasskeyAdminController.php:146-158, 270-293`, `Controller/Api/OidcAdminAuthController.php:75-94`
- **What's wrong:** `login-options` with `email=` returns a non-empty `allowCredentials` exactly when that admin email exists **and** has passkeys, so an attacker can list admin emails by probing. `login-options` also tells anonymous visitors that admin passkeys exist and lists every admin-scoped IdP.
- **Fix:** always use discoverable (usernameless) credentials on the admin side too (`allowCredentials = []`). Registration already enforces `residentKey=required`, so the email step is pointless.

### M7. Raw exception messages go back to anonymous clients
- **Where:** `Storefront/Controller/PasskeyController.php:91, 162`, `Controller/Api/PasskeyAdminController.php:88, 193, 197`, `Controller/Api/OidcAdminAuthController.php:246, 318`
- **What's wrong:** `$exception->getMessage()` from DBAL, webauthn-lib, League or JSON parsing is returned verbatim. That leaks internals (SQL fragments, library versions, whether a credential exists).
- **Fix:** return a fixed error code (`passkey_login_failed`) and log the detail server-side with a correlation id.

### M8. Customer passkey login looks up by email and may log in a different customer
- **Where:** `Storefront/Controller/PasskeyController.php:129-138`, `Storefront/Service/OidcCustomerLoginRoute.php:44-86`
- **What's wrong:** the passkey resolves customer **A by id**, then the code calls `loginRoute->login(['email' => A.email])`, which returns the **first** non-guest customer with that email whose `boundSalesChannelId` is null or matches. If customer A is bound to another sales channel and an unbound customer B shares the email, **B** gets logged in. The passkey owner's own sales-channel binding is never checked.
- **Fix:** give `OidcCustomerLoginRoute` a `loginByCustomerId(string $customerId, SalesChannelContext)` method, check `boundSalesChannelId` on that entity, and use it for both the OIDC and passkey paths. The OIDC path already has the customer id from provisioning.

### M9. The birthday claim can break login or be accepted as nonsense
- **Where:** `Service/Provisioning/CustomerProvisioningService.php:162, 238`
- **What's wrong:** `new \DateTimeImmutable($profile->birthday)` accepts `"tomorrow"`, `"now"` and `"+1 week"`, and throws on `"0000-12-24"` (valid OIDC `birthdate` with the year withheld) or other garbage. The exception kills the whole login; the claim is controlled by the IdP or user.
- **Fix:** `DateTimeImmutable::createFromFormat('!Y-m-d', $v)`, reject years below 1900 and future dates, and on failure log and skip the field.

### M10. `claim_encoding = base64` corrupts plain claims
- **Where:** `Service/Oidc/ClaimsNormalizer.php:60-73`
- **What's wrong:** every string claim that happens to be valid base64 **and** decodes to valid UTF-8 gets replaced: `sub`, `locale` values, short names, and so on. In practice many short ASCII strings decode to valid UTF-8. The result is silent data corruption, and a possible identity mix-up once `sub` is used (C2).
- **Fix:** decode only the claims listed per provider (for example Zitadel's `urn:zitadel:iam:user:metadata:*`), never globally.

### M11. JWKS handling can cause outages and ignores `kid`
- **Where:** `Service/Jwt/JwtVerifier.php:48-55, 89-121, 123-144`
- **What's wrong:**
  - When the IdP rotates keys, verification fails for up to `jwks_cache_ttl` (default **86400 s**), because an unknown `kid` never triggers a refetch. That is a full SSO outage.
  - It verifies against every key in the set instead of selecting by `kid`/`alg`/`use=sig`.
  - No clock-skew leeway on `exp`/`nbf`, so logins fail when server clocks drift.
  - No `iat` sanity check; `azp` is not checked when `aud` has several values.
  - It uses the raw `HttpClientInterface` instead of `OidcHttpClient`, so timeouts and logging are inconsistent and an exception on non-2xx is unhandled.
- **Fix:** select the key by `kid`. On a miss or signature failure, refetch once (rate-limited, for example once a minute). Allow 60 s leeway, check `azp` when `aud` is an array, and route the fetch through `OidcHttpClient`.

### M12. SSRF protection is partial
- **Where:** `Service/Oidc/DiscoveryUrlValidator.php:62`, `Controller/Api/OidcProviderAdminController.php:96-114`, runtime fetches in `TokenExchangeService`, `UserInfoService`, `JwtVerifier`, `RpInitiatedLogoutService`
- **What's wrong:** only the discovery URL and the JWKS URL in the connection test are validated. The token, userinfo, revocation and runtime JWKS URLs, and every URL returned **inside** the discovery document, are fetched unchecked. Symfony HttpClient follows redirects by default, so a 302 to `169.254.169.254` bypasses the validator. `FILTER_FLAG_NO_RES_RANGE` does not cover `100.64.0.0/10` (CGNAT), and IPv4-mapped IPv6 handling is unclear. DNS rebinding is documented but unfixed.
- **Fix:** wrap the plugin's HTTP client in `Symfony\Component\HttpClient\NoPrivateNetworkHttpClient`, as Shopware core does for media. It checks the resolved IP on every connection and redirect, which also fixes rebinding. Set `max_redirects: 0` for the token and userinfo calls. Delete `DiscoveryUrlValidator`'s DNS logic after that.

### M13. Live-test claims (personal data) are stored and shown to viewers
- **Where:** `Controller/Api/OidcProviderAdminController.php:116-141, 233-252`, `Core/Content/Provider/Sw6OidcProviderDefinition.php:90`
- **What's wrong:** the flattened claims of whoever completes the test login (email, name, address, phone, groups) are stored in `sw6oidc_provider.last_test_claims` indefinitely. They are readable by anyone with `sw6oidc_provider:read`. Starting a test needs only `viewer`.
- **Fix:** store claim **keys** only, or keys plus redacted sample values. Require `editor` to start a test.

### M14. RP-initiated logout is half-implemented
- **Where:** `Storefront/EventSubscriber/CustomerLogoutSubscriber.php:75, 97-100`, `Service/Oidc/RpInitiatedLogoutService.php:40`
- **What's wrong:**
  - `revokeToken($provider, null)` always passes `null`, so RFC 7009 revocation never runs. The code path is dead but reads as working.
  - `post_logout_redirect_uri` is built from `getenv('APP_URL')`, not the current sales-channel domain, which is wrong for multi-domain shops, language prefixes (`/de/account/login`) and shops where `APP_URL` is an internal host.
  - A random `state` is sent but never verified.
  - The `id_token` is kept for 24 h in the shared cache.
- **Fix:** store the access and refresh tokens in the logout context, encrypted, or drop the revocation feature. Build the redirect with `$this->router->generate('frontend.account.login.page', [], ABSOLUTE_URL)` against the request's `SalesChannelContext` domain. Drop `state` or verify it.

### M15. Passkeys and caches are not cleaned up when a user or customer is deleted
- **Where:** `Subscriber/UserProviderCleanupSubscriber.php:25-40`
- **What's wrong:** only `sw6oidc_user_provider` rows are removed. `sw6oidc_passkey_credential` rows stay behind, and together with H1 a deleted admin's passkey still mints tokens. Revocation paths (deactivate, delete) never clear login nonces or tracker entries.
- **Fix:** delete passkey credentials in the same subscriber. Also subscribe to `user.written` with `active = false` and revoke the user's refresh tokens (`oauth_refresh_token`/`user_access_key` handling, as core `SsoService::revokeUserTokens()` does).

### M16. JIT customer creation bypasses Shopware's registration pipeline
- **Where:** `Service/Provisioning/CustomerProvisioningService.php:108-198`
- **What's wrong:**
  - A raw `customerRepository->create()` skips `CustomerRegisterEvent`/`CustomerDoubleOptInRegistrationEvent`. **Flow Builder** "customer registered" flows (welcome mail, CRM sync, tagging), newsletter handling, `DataValidator` rules (address field lengths, required salutation and country states), and bound-sales-channel settings (`core.systemWideLoginRegistration`) never run.
  - `'defaultPaymentMethodId'` (line 153) **no longer exists** on `customer` in Shopware 6.7; `CustomerDefinition` only has `lastPaymentMethodId`. The field is silently dropped, so it's dead code that looks meaningful.
  - The `'-'` placeholders for street, zipcode and city create addresses that fail checkout validation later, so the customer hits an error at checkout instead of being asked for an address.
- **Fix:** dispatch `CustomerRegisterEvent` (and respect `core.loginRegistration.*` config), or call a decorated `AbstractRegisterRoute` with a prepared `RequestDataBag`. Remove `defaultPaymentMethodId`. For missing addresses, create no address and redirect to an address-completion page, or use Shopware 6.7's customer data requirements handling, instead of writing `-`.

### M17. Passkey RP ID is wrong on multi-domain shops and trusts the Host header in admin
- **Where:** `Storefront/Controller/PasskeyController.php:166-171`, `Controller/Api/PasskeyAdminController.php:66, 155`
- **What's wrong:** the storefront always uses the **first** sales-channel domain, so passkeys fail on every other domain of the same channel (`shop.de` vs `shop.at`). The admin uses `$request->getHost()`, which follows the `Host` header unless `trusted_hosts` is configured.
- **Fix:** use `$context->getDomainId()` / `$request->attributes->get(SalesChannelRequest::ATTRIBUTE_DOMAIN_ID)`, resolve that domain's host, and allow a configured list of related origins. In admin, derive the RP ID from `APP_URL` or config, never from the request.

### M18. `league/oauth2-server: "*"` is unconstrained
- **Where:** `composer.json`
- **What's wrong:** `AdminOidcGrant` extends `AbstractGrant` and relies on its internal behaviour (`issueRefreshToken`, `refreshTokenTTL`, `validateClient` semantics). A major version bump will break admin login silently.
- **Fix:** pin to the range Shopware 6.7 ships (`^8.5 || ^9.0`, check `vendor/league/oauth2-server`). Better: don't require it and rely on `shopware/core`'s own constraint.

### M19. The avatar is re-downloaded and re-imported on every login
- **Where:** `Service/Provisioning/AdminProvisioningService.php:215-224, 253-300`
- **What's wrong:** with `sync_admin_profile_on_sso`, every login makes an outbound HTTP request, writes a temp file, overwrites the media file and runs thumbnail generation. That adds latency and wasted storage, and it is an outbound request triggered by anyone who can log in. SSRF safety depends entirely on core config (`core.media.enableUrlValidation`), which admins can switch off.
- **Fix:** store `sha256(pictureUrl)` (or the ETag) in the user's `customFields` and skip the import when it hasn't changed. Force URL validation regardless of shop config by using `NoPrivateNetworkHttpClient` (M12).

### M20. Shared services hold mutable per-request state
- **Where:** `Storefront/EventSubscriber/CustomerLogoutSubscriber.php:38`, `Service/Passkey/PasskeyCredentialRepository.php:41-53` (`$context` plus a public `setContext()` that is never called), `Service/Provisioning/CountryResolver.php` (memo arrays)
- **What's wrong:** in long-running workers this leaks across requests and users. `PasskeyCredentialRepository` also builds `Context::createDefaultContext()` in its constructor, which is `@internal` and ignores the caller's context.
- **Fix:** pass `Context` as a method argument. Implement `ResetInterface` (Symfony calls `reset()` between requests via `kernel.reset`) on the resolver and the subscriber.

### M21. Races on first login
- **Where:** `Service/Provisioning/UserProviderBindingService.php:48-61`, `Service/Provisioning/AdminProvisioningService.php:302-324`
- **What's wrong:** two parallel first logins (double click, two tabs) both pass `getBoundProviderId() === null` and hit the unique key → 500. `resolveUniqueUsername()` has the same check-then-insert race.
- **Fix:** `INSERT … ON DUPLICATE KEY UPDATE id = id` (or catch `UniqueConstraintViolationException` and re-read). Retry username creation on a unique violation.

### M22. The log file grows without limit, and the log toggle does nothing
- **Where:** `Resources/config/services.xml:65-68`, `Resources/config/config.xml:57`
- **What's wrong:** `StreamHandler` has no rotation, so debug logging of every IdP request fills the disk. `debugLoggingEnabled` in the plugin config does nothing.
- **Fix:** use Shopware's Monolog config: register a `sw6oidc` channel through `monolog.channels` in `Resources/config/packages/monolog.yaml` with a `rotating_file` handler. Either wire the toggle through a `ProcessorInterface`/`ActivationStrategy` that reads the system config, or delete it.

---

## Low

### L1. Deprecated route attribute namespace
- **Where:** 8 controllers use `Symfony\Component\Routing\Annotation\Route`.
- **Fix:** use `Symfony\Component\Routing\Attribute\Route` (Symfony 7 / Shopware 6.7; the `Annotation` alias goes away in Symfony 8).

### L2. Deprecated exception
- **Where:** `Storefront/Service/OidcCustomerLoginRoute.php:85`
- **Fix:** replace `new BadCredentialsException()` with `CustomerException::badCredentials()`.

### L3. Unused and dead code (delete it or wire it)
| Symbol | Where | Status |
|---|---|---|
| `ClaimsNormalizer::extractEmail()` | `Service/Oidc/ClaimsNormalizer.php:109` | No caller. Its "first string that looks like an email" fallback would be a security bug if it were ever wired. **Delete.** |
| `TokenExchangeService::refreshAccessToken()` | `Service/Oidc/TokenExchangeService.php:59` | No caller. |
| `ProviderResolver::hasVisibleProvider()` | `Service/Provider/ProviderResolver.php:75` | No caller. |
| `PasskeyCredentialRepository::setContext()` | `Service/Passkey/PasskeyCredentialRepository.php:50` | No caller (see M20). |
| `RedisAtomicCache`, `RedisConnectionFactory` | `Service/Cache/` + `services.xml:47` | Factory registered but never used (M4). |
| `sw6oidc_attribute_mapping.sync_on_sso`, `transform_function`, `transform_params` | Migration + entity | Schema-only. |
| `disable_non_oidc_*_login` | Migration, entity, admin form | No reader (H8). |
| `button_label`, `button_color` | Migration, definition, entity | No reader in JS, Twig or PHP. |
| `debugLoggingEnabled` | `config.xml:57` | No reader (M22). |
| `ES512`, `RS512` in the COSE manager | `WebauthnCeremonyFactory.php:80-85` | Registered but never offered in `credentialParameters()`. EdDSA (Ed25519), which some authenticators use, is missing. |

### L4. Leftover "temporary diagnostic aid" code
- **Where:** `Service/Oidc/OidcCallbackProcessor.php:43-52`, `Service/Oidc/AuthorizationRequestBuilder.php:50-60`
- **Fix:** remove before release. This ties in with H9.

### L5. The live-test pipeline duplicates the callback pipeline
- **Where:** `Service/Oidc/OidcLiveLoginTestService.php` vs `OidcCallbackProcessor.php`
- **What's wrong:** the copy has already drifted: no group normalisation, and `ClaimsTooComplexException` from `flatten()` (line 96) is uncaught, so the popup shows a 500.
- **Fix:** refactor `OidcCallbackProcessor` into steps that report their results, and have the test service decorate or observe those steps.

### L6. `normalizeGroups()` drops a group named `"0"`
- **Where:** `Service/Oidc/ClaimsNormalizer.php:96`
- **Fix:** `array_filter()` without a callback drops `"0"`. Use `static fn ($g) => $g !== null && $g !== ''`.

### L7. Schema sizing
- **Where:** `Migration/Migration1730000001CreateOidcSchema.php:133` and the `user_handle` column
- **What's wrong:** WebAuthn credential IDs can be up to 1023 bytes, which is about 1364 base64 characters, but the column is `VARCHAR(255)`. There is also no index on `user_handle`, which `findAllForUserEntity()` filters on.
- **Fix:** widen `credential_id` to `VARCHAR(1400)` (or store `sha256(credential_id)` as the indexed lookup key) and add an index on `user_handle`.

### L8. Several small queries per login
- **Where:** `resolveUniqueUsername()` (one query per suffix), `resolveLocaleId()` (up to 3), `resolveSalutationId()` (2), plus `loadClaimKeys()` and the role-mapping search on every login
- **What's wrong:** not a real N+1, but 8–15 queries per login that could be about 3.
- **Fix:** fetch usernames with `PrefixFilter` once, and cache the mapping rows per provider (invalidate on `sw6oidc_*.written`).

### L9. The email address is used as a first name
- **Where:** `AdminProvisioningService.php:119`, `CustomerProvisioningService.php:138, 156, 178`
- **What's wrong:** the email then ends up on invoices, delivery notes and admin UIs.
- **Fix:** fall back to the local part of the email or to an empty value, and let Shopware validation decide.

### L10. `AuthorizationFlowContext::fromArray()` trusts the cache blindly
- **Where:** `Service/Security/AuthorizationFlowContext.php:45-55`
- **Fix:** validate the keys, or use `unserialize` with an allowed-classes list.

### L11. Magic strings instead of constants
- **Where:** `'admin'`, `'customer'`, `'test'`, `'both'` are repeated across ~15 files, even though `Sw6OidcUserProviderEntity::USER_TYPE_*` exists.
- **Fix:** introduce a backed `enum LoginType: string`.

### L12. `XmlHttpRequest => true` on full-page GET routes
- **Where:** `SendAuthorizationRequestController.php:33`, `OidcCallbackController.php` (callback route)
- **What's wrong:** these are top-level navigations, and the flag misrepresents that.
- **Fix:** drop the flag.

### L13. Manual DI wiring
- **Where:** `Resources/config/services.xml` (400 lines)
- **What's wrong:** Shopware 6.7 plugins can use `autowire`/`autoconfigure` defaults plus `#[Autowire]` for the few scalar arguments. The manual wiring causes most of the churn in this file.
- **Fix:** switch to autowiring, keeping explicit definitions only for the League server, the logger channel and the repositories.

### L14. Docblocks full of project history
- **What's wrong:** many comments refer to "the plan", "Phase 1" or "the Magento module", or retell debugging history ("confirmed via a real cache:clear failure"). For a reader of the code they are noise, and they will rot.
- **Fix:** keep the *why*; move the history to ADRs or commit messages.

### L15. Test coverage misses the security-critical classes
- **What's wrong:** none of the following has a single unit test: `OidcSecurityHelper`, `JwtVerifier`, `AdminOidcGrant`, `OidcCallbackProcessor`, `Customer/AdminProvisioningService`, `GroupMappingResolver`, `UserProviderBindingService`, `resolveSafeRelayState`, and the WebAuthn ceremonies. Every Critical and High finding above would have been caught by a test.
- **Fix:** see "Future improvements".

### L16. Uninstall leaves data behind
- **Where:** `Sw6Oidc.php:13-28`
- **What's wrong:** system config keys (`Sw6Oidc.config.*`) and cache entries stay. The multi-statement `executeStatement` depends on PDO emulation.
- **Fix:** issue one `DROP` per statement. Delete from `system_config WHERE configuration_key LIKE 'Sw6Oidc.config.%'`.

### L17. Customer lookup ignores `core.systemWideLoginRegistration`
- **Where:** `CustomerProvisioningService::findByEmail()`, `OidcCustomerLoginRoute::getCustomerByEmail()`
- **What's wrong:** both copy core's bound-sales-channel logic by hand. It will drift from core behaviour.
- **Fix:** reuse `AccountService::getCustomerByLogin()`-style logic, or share one resolver between both classes.

### L18. `last_test_status` column is too short
- **Where:** `last_test_status VARCHAR(16)`
- **What's wrong:** statuses are free strings (`'pass'`, `'fail'`), with no enum or validation on write.
- **Fix:** add a `Choice` constraint in the definition or a check in the controller.

---

## Frontend (Administration and Storefront JS/Twig)

Paths below are relative to `src/Resources/`. Core behaviour was checked against `vendor/shopware/administration` 6.7.

### Critical

#### F-C1. The `isSso()` decorator switches the whole Users & Permissions module into "native SSO mode" without asking for a password
- **Where:** `app/administration/src/extension/sw-profile/index.js:78-88` (helper at `91-132`)
- **What's wrong:** the comment claims the change affects only the profile save. It doesn't. Core calls `ssoSettingsService.isSso()` in:
  - `sw-users-permissions-user-listing/index.js:31`: SSO/invite UI and SSO detail route, and user deletion skips `verifyUserToken`.
  - `sw-users-permissions-role-detail/index.js:172`: roles are saved **without** the password modal.
  - `sw-users-permissions-role-listing/index.js:132`.
  - the profile general tab: the password card is hidden.

  Every call also hits `/api/sw6oidc/admin/verify-session` (H2) and swaps the session token for a `user-verified` token pair with no re-authentication. So any open SSO admin session can edit roles and delete users without proving who is at the keyboard. Parallel calls (listing and role listing load together) race on `setBearerAuthentication()`. Side effect: OIDC admins are routed to `sw.users.permissions.user.sso.detail`, where the plugin's own "OIDC Provider" row never renders.
- **Fix:** do not decorate `isSso`. Override only `sw-profile-index`'s save handler. Mint `user-verified` only after a real step-up (a WebAuthn assertion with `userVerification: required`, or an OIDC re-auth with `prompt=login&max_age=0`). This is the same fix as H2.

### High

#### F-H1. The storefront build isn't committed
- **Where:** `app/storefront/dist/`
- **What's wrong:** `git status` shows `?? src/Resources/app/storefront/dist/`. The file exists locally (built today), but a git or composer install ships **no storefront JS**, so the passkey buttons silently do nothing. The admin build (`public/`) is git-ignored. When it is missing, `Twig/AdminEntrypointsExtension.php` returns `[]` and the SSO and passkey buttons vanish from the admin login without any error.
- **Fix:** commit `dist/` (the Shopware store convention for storefront JS) or build it in the release pipeline. Add a CI check that the build output is up to date.

#### F-H2. `redirectTo` is read the wrong way, so checkout redirects are lost or 404
- **Where:** `views/storefront/component/account/login.html.twig:43`
- **What's wrong:** it uses `app.request.get('redirectTo')`. In core, `redirectTo` is a **route name** plus `redirectParameters`, passed as include variables. From checkout the target is lost. With `?redirectTo=frontend.account.order.page`, the relay state becomes that literal string, which `resolveSafeRelayState()` then returns as a relative path, giving a 404. Combined with H4, this is also the entry point for the open redirect.
- **Fix:** pass the Twig `redirectTo` and `redirectParameters` variables. On the server, resolve the route name with `generateUrl()`, or allow only safe relative paths (H4).

#### F-H3. Hard-coded storefront fetch URLs break sales channels with a path prefix
- **Where:** `app/storefront/src/passkey/passkey-login.plugin.js:27,37`, `passkey-registration.plugin.js:30,48`
- **What's wrong:** `/sw6oidc/passkey/...` is absolute from the root. On `shop.tld/de` the request hits the root domain's sales channel and context.
- **Fix:** render `{{ path('frontend.sw6oidc.passkey.login-options') }}` and friends into the plugin's `data-*-options` (the standard `static options` pattern).

#### F-H4. Admin code uses raw `fetch('/api/...')` instead of Shopware's HTTP client
- **Where:** `service/user-provider-api.js:7-16`, `module/sw6oidc-provider/page/sw6oidc-provider-detail/index.js:411-422`, `module/sw6oidc-passkey/page/sw6oidc-passkey-list/index.js:168-179`, `extension/sw-profile/page/sw6oidc-profile-passkey/index.js:158-172`, `extension/sw-profile/index.js:109`, `extension/sw-login/index.js:122,149,175,196,260`, `extension/sw-inactivity-login/index.js:46,70,86`
- **What's wrong:** this breaks sub-folder installs. It also bypasses core's automatic token refresh and 401 handling, so requests with an expired `getToken()` just fail.
- **Fix:** add one `Sw6OidcApiService extends ApiService` registered via `Shopware.Service().register(...)` and use `this.httpClient`. Use `Shopware.Context.api.apiPath` for full-page redirects.

#### F-H5. No ACL privilege mapping, so the modules can't be granted to non-admin roles
- **Where:** `module/sw6oidc-provider/index.js:8-10` (the comment wrongly says `entity` is enough), `module/sw6oidc-passkey/index.js`
- **Fix:** add `acl/index.js` with `Shopware.Service('privileges').addPrivilegeMappingEntry({ category: 'permissions', parent: 'settings', key: 'sw6oidc_provider', roles: { viewer, editor, creator, deleter } })`. Do the same for `sw6oidc_passkey_credential`, including the dependent `user:read`/`customer:read`. Without these, only superadmins can use the modules.

#### F-H6. The inactivity re-login accepts **any** admin's passkey
- **Where:** `extension/sw-inactivity-login/index.js:70-74`
- **What's wrong:** it sends `email: this.lastKnownUser`, but core stores the **username** there (`core/service/login.service.ts:706`). `allowCredentialsForEmail()` finds nothing and falls back to discoverable credentials, so a different admin's passkey resumes the locked session. Nothing checks that the resolved user equals `lastKnownUser`.
- **Fix:** send the username, and have the backend reject an assertion whose user doesn't match the expected one.

### Medium

| # | Where | Issue | Fix |
|---|---|---|---|
| F-M1 | `extension/sw-inactivity-login/index.js:101-113` | Never calls `userActivityService.updateLastUserActivity()` (which `sw-login/index.js:211-218` says causes a forced logout) and ignores `rememberMe` | Share one login-completion helper with `sw-login` |
| F-M2 | `extension/sw-inactivity-login/sw-inactivity-login.html.twig:11-75` | Copies core's whole `sw_inactivity_login` block, so any core change silently diverges | Override a narrower block with `{% parent %}` |
| F-M3 | `passkey-list/index.js:122-166` ≈ `profile-passkey/index.js:73-117`; `sw-login/index.js:165-234` ≈ `sw-inactivity-login/index.js:60-121`; `service/webauthn-codec.js` ≡ `storefront/src/passkey/webauthn-codec.js` | Registration and login ceremonies and the codec are duplicated | One `passkey-ceremony.service.js`, a shared codec (or native `PublicKeyCredential.parseCreationOptionsFromJSON()`/`toJSON()`) |
| F-M4 | `provider-list.html.twig:23` + `index.js:49`; `passkey-list.html.twig:24` + `index.js:84` | `:disable-data-fetching="true"` with no `@page-change`/`@column-sort` handlers, so pagination and sorting are dead and rows past 25 can't be reached. After a delete, `ownerNames` isn't recomputed and raw ids show | Use the `listing` mixin, or drop `disable-data-fetching` |
| F-M5 | `passkey-list/index.js:87-105`, `provider-list/index.js:52-55`, `provider-detail/index.js:232-242` | No `.catch`/`finally`, so a missing `customer:read` leaves `isLoading` stuck on true | `try/finally`, `Promise.allSettled` for owner names |
| F-M6 | `passkey-list.html.twig:5-12`, `provider-detail.html.twig:8-15, 73-79` | Register, delete, save and test controls are shown to viewers (`acl` is injected but unused) | `:disabled="!acl.can('…editor')"`, `:allow-delete="acl.can('…deleter')"` |
| F-M7 | `provider-detail/index.js:249-263, 329-333` | Every save re-runs discovery and overwrites manually edited endpoints. `discoverAndApply` copies **any** key from the response onto the entity (mass assignment) | Allow-list endpoint keys; re-discover only when the URL changed or the user confirms |
| F-M8 | `provider-detail/index.js:373-380` | `window.open` runs after an `await`, outside the user gesture, so Safari and Firefox block the test popup | Open `about:blank` synchronously, then set `popup.location` |
| F-M9 | `provider-detail/index.js:405-407` | The `postMessage` handler calls `loadEntity()` and throws away unsaved edits. It doesn't check `event.source` | Reload only `lastTest*`; check `event.source === this.testPopup` |
| F-M10 | `provider-detail/index.js:269-272` | After the first save, the router push may reuse the component instance with a stale `isNew()` entity, so a second save creates a duplicate (**unverified**) | Watch `$route.params.id` |
| F-M11 | `provider-detail/index.js:321-323, 374-376`, `passkey-list/index.js:151`, `profile-passkey/index.js:102,129`, `passkey-registration.plugin.js:58-60`, `passkey-login.plugin.js:31,45` | `response.json()` is called before checking `response.ok` (the login plugin never checks it), so an HTML 500 surfaces as a `SyntaxError` | Check `ok` first |
| F-M12 | `app/storefront/src/passkey/passkey-login.plugin.js:63-66` | `showError()` is empty, so a failed storefront passkey login gives no feedback | Render an alert |
| F-M13 | `extension/sw-login/sw-login.html.twig:28-30` vs `66-71` | The SSO error block sits inside `v-if="providers.length \|\| passkeyAvailable"`, so `provider_unavailable` is never shown | Move the error out of the wrapper |
| F-M14 | `views/administration/index.html.twig:43-51`, `service/defer-module-register.js:13-28` | The "login-only" bundle runs on **every** admin page, which is the root cause of the lazy-factory and 5 s polling workarounds (the polling uses the deprecated `Shopware.State`) | Build a separate tiny pre-auth Vite entry for the `sw-login` override |
| F-M15 | `views/storefront/component/account/login.html.twig:45` | `{{ label\|sw_sanitize }}` renders admin-controlled `displayName` as purified HTML | Use plain autoescaped `{{ label }}`; keep `sw_sanitize` for snippets |

### Low
- `Twig/AdminEntrypointsExtension.php:59`: `JSON_THROW_ON_ERROR` on a malformed `entrypoints.json` makes the **admin login page return 500**. Catch it and return `[]`, or reuse `ViteFileAccessorDecorator::getBundleData()` instead of the hard-coded path and entry name (lines 22, 53).
- `Twig/StorefrontLoginOptionsExtension.php:52-69`: two uncached queries on every render of the login component (login page and checkout). Memoise per request.
- `extension/sw-users-permissions-user-listing/index.js:33-51`: fast paging lets an older bindings response overwrite a newer one. Use a request counter.
- `extension/sw-profile/index.js:79`: `{...ssoSettingsService}` drops prototype methods. It becomes moot once F-C1 is fixed.
- Raw ISO dates (`provider-detail.html.twig:210`, passkey `createdAt`) and untranslated `userType`/`loginType` values. Use the `date` filter and snippets.
- `provider-detail.html.twig:88, 222`: snippet keys built from server ids show the raw key for any new id. Add a fallback.
- Hard-coded strings: `component/sw6oidc-rp-id-field/index.js:70` ("Defaults to:"), and a hand-maintained German dictionary in `extension/sw-login/index.js:31-41, 77-87`.
- Deprecated `sw-button`/`sw-card`/`sw-text-field`/`sw-switch-field`/`sw-label` are mixed with `mt-*`. Migrate consistently before 6.8. Move the inline `style="…"` attributes in `provider-detail.html.twig` (64, 84, 187, 205, 217, 348, 390, 467) to SCSS.
- Dead code: `provider-list/index.js:58-60` `onChangeLanguage()`. `acl` is injected but unused in `passkey-list`.
- `views/storefront/page/account/sidebar.html.twig:16-21`: the "Passkeys" link shows even when passkeys are disabled for the sales channel.
- `views/storefront/component/account/customer-overview-personal-company.html.twig` changes the core account overview for **every** customer and duplicates core's VAT condition. It is out of scope for an SSO plugin; remove it or document it.
- `app/storefront/src/main.js:6-8`: synchronous `PluginManager.register`. Since 6.6 the recommended form is `() => import('./…')` for lazy loading.
- `window.prompt`/`alert`/`confirm` are used for nickname and delete flows. Replace them with `sw-modal` / Bootstrap modals and snippet text.

---

## Edge cases that break the plugin today

| Input or situation | Result |
|---|---|
| IdP returns `email_verified: false` | Accepted, and pre-existing accounts are linked (C2) |
| Customer-scoped provider id passed to the admin login | Admin login (C1) |
| `redirectTo=/\evil.tld` | Redirect off-site after login (H4) |
| `birthdate: "0000-05-01"` or `"tomorrow"` | Login crashes / birthday set to tomorrow (M9) |
| Name claim `"Test"` with `claim_encoding=base64` | Stored as mojibake (M10) |
| IdP rotates signing keys | SSO outage for up to 24 h (M11) |
| `cache:clear` or a deployment during a login | "Unknown, expired, or already-used OAuth state" (M4) |
| Admin deactivated in Shopware | Can still log in via SSO or passkey (H1) |
| Admin demoted at the IdP | Keeps the old role (H3) |
| Two tabs finishing the first login at once | 500 on a unique key (M21) |
| Passkey credential id > 255 bytes base64 | Insert fails (L7) |
| Shop with two storefront domains | Passkeys fail on the second domain (M17) |
| Headless client calling `/store-api/account/logout` after an SSO login | Gets a 302 instead of JSON (H11) |
| Group named `"0"` | Silently ignored (L6) |
| Transport timeout on token exchange | Retry burns the code; the IdP may revoke the session (M3) |

---

## Future improvements (optimisation and hardening roadmap)

1. **Identity model:** bind on `(provider_id, iss, sub)`, and make account linking an explicit, user-confirmed action. This one change removes C2 and most of the email-related edge cases.
2. **Build the protocol layer on a maintained library.** Use `facile-it/php-openid-client` or `jumbojett/openid-connect-php` (or at least `web-token/jwt-library` instead of the full `jwt-framework`) for discovery, JWKS rotation, `sub` matching and clock skew, instead of hand-rolling them.
3. **Evaluate Shopware 6.7's native Administration SSO** (`Shopware\Core\Framework\Sso`, `ShopwareGrantType`, `SsoService::revokeUserTokens()`). It is `@internal` today, but it is the direction core is going. The admin half of this plugin should either build on it or deliberately diverge, with the reasons documented.
4. **Back-channel logout plus token revocation on deactivation.** Implement OIDC Back-Channel Logout (a `/sw6oidc/backchannel-logout` endpoint that validates the logout token and revokes Shopware refresh tokens by user). Add a `user.written` subscriber that revokes tokens when `active` flips to false.
5. **Rate limiting** through Shopware's `RateLimiter` on every anonymous endpoint (M5).
6. **Dedicated token store:** a small `sw6oidc_one_time_token` table (or a dedicated Redis pool) instead of `cache.app` (M4).
7. **Secrets:** encrypt `client_secret` at rest, make it write-only through the API, and support `private_key_jwt` client authentication (RFC 7523) so no shared secret needs storing at all.
8. **Tests:**
   - Unit-test every security predicate (relay-state validation, JWT claims, flow `loginType`, grant user validation, role replacement).
   - Add Shopware `IntegrationTestBehaviour` tests for provisioning against a real DAL.
   - Run an end-to-end Playwright suite against Keycloak in Docker (`quay.io/keycloak/keycloak --import-realm`) covering the storefront login, admin login, logout and passkey (virtual authenticator) flows.
   - Add a mutation-testing gate (Infection) on `Service/Security` and `Service/AdminAuth`.
9. **Static analysis:** raise PHPStan from level 5 to 8, add `phpstan/phpstan-strict-rules` and the Shopware PHPStan extension (`shopware/core` ships rules for `@internal` usage and DAL misuse), and turn on Psalm `findUnusedCode` so L3 cannot come back.
10. **Performance:** cache provider plus mappings per request (one query instead of 4–6), skip unchanged avatars (M19), and move provisioning side effects (avatar import, sync) to the message queue (`AsyncMessageInterface`) so login latency doesn't depend on the IdP's CDN.
11. **Observability:** emit domain events (`Sw6OidcCustomerProvisionedEvent`, `Sw6OidcLoginFailedEvent`) so the shop can plug in Flow Builder actions and audit logging, and write security-relevant events to Shopware's `log_entry` table so they are visible in the Administration.
12. **webauthn-lib 5.x** (already in `TODO.md`): do it before the 1.0 release, since 4.x only gets security fixes for a limited time. Add EdDSA, use `userVerification=required`, and consider attestation `direct` with an allow-list (FIDO MDS) for admin credentials.
13. **Multi-domain and headless:** a Store API route (`/store-api/sw6oidc/*`) so PWA/Composable Frontends can use SSO and passkeys, and per-domain RP IDs with WebAuthn related origins.
14. **Configuration hygiene:** move `SW6OIDC_LOG_LEVEL`/`SW6OIDC_REDIS_DSN` into documented `config/packages/sw6oidc.yaml` bundle config with a real `Configuration` tree, so wrong values fail at container compile time instead of at runtime.
15. **Release engineering:** commit a `CHANGELOG.md` and never edit a shipped migration (`Migration1730000001…` has clearly been edited, which is why `Migration1789470000AddUserProviderUpdatedAt` exists). Add `shopware-cli extension validate` / `extension zip` to CI so the store-review checks run on every PR.

---

## Suggested fix order

1. C1, C2, H1: identity and account-state checks. One PR, with tests.
2. H2 + F-C1, H6, H7, F-H6: re-authentication and passkey policy.
3. F-H1, F-H5: commit the storefront build and add ACL privilege mappings (both are quick).
4. H3, H4 + F-H2, H5, H9: privilege revocation, redirect, secret exposure, logging.
5. H8, H10, H11: misleading toggles, TTLs, logout scope.
6. M1–M5, F-H3, F-H4: CSRF binding, `sub` check, retry, token store, rate limits, URL handling.
7. Everything else, then the roadmap.

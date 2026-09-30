# TODO — Feature parity with magento2-oidc-sso

Remaining roadmap to bring `shopware6-oidc-sso` to feature parity with its
sibling `magento2-oidc-sso` module. Each phase below is intended to be one
shippable PR. Phase numbers are kept from the original plan so history and
commit messages stay traceable.

## Already shipped (see CHANGELOG.md)

Phases 0 (webauthn-lib 5.x), 1 (Redis + JWKS circuit breaker), 2 (encrypted
`client_secret`), 3 (SSRF validation + lockout guard, plus enforcement of the
`disable_non_oidc_*_login` flags), 4 (attribute transforms; per-attribute
`sync_on_sso` dropped), 5 (provisioning domain events), 9 (CLI export/import),
12 (CSP), and the unit-test part of 13. Deviations from the original plan,
recorded so nobody "fixes" them back:

- **Phase 1:** no `Sw6OidcCachePass` compiler pass. `RedisAtomicCache` is always
  wired and picks Redis vs. the cache-pool fallback **at runtime** — a
  compile-time choice would be frozen into Shopware's cached container. Also
  added: one rate-limited JWKS refetch on signature failure (key rotation).
- **Phase 2:** the key is derived from `APP_SECRET` only (no dedicated env var),
  and `client_secret` is write-only over the Admin API (no `ApiAware`).
- **Phase 3:** SSRF is also enforced at request time (`NoPrivateNetworkHttpClient`),
  with `SW6OIDC_ALLOW_INSECURE_IDP_URLS=1` as the dev escape hatch; the
  disable-password-login flags are now actually enforced
  (`SW6OIDC_ALLOW_PASSWORD_LOGIN=1` break-glass). The subscriber lives in
  `src/Subscriber/`, matching the existing code layout.
- **Phase 4:** the per-attribute `sync_on_sso` column was dropped instead of
  wired (`mapForSync()` not built) — provider-level toggles are the only re-sync
  gate.
- **Phase 9:** the export omits the client secret by default
  (`--keep-encrypted` / `--plaintext` opt in); ACL roles/customer groups are
  resolved on import by id, then unique name.
- **Phase 8c (minimal, shipped ahead of 7/8):** admin RP-initiated logout
  works without the session registry — `LogoutContextStore` keys the admin
  logout context by user id (last login wins across concurrent sessions), and
  the interception point is the `sw-admin-menu` `onLogoutUser()` override. Still
  open: `post_logout_url` override column and the shared `postlogout` landing
  action (see Phase 8c below).
- **Phase 6:** matching is list-aware (`groups` matches `groups.0`, `groups.1`, …; Zitadel role-object names count as entries), case-insensitive, and treats `true`/`1`/`false`/`0` as booleans; unknown operators fail closed. The admin callback passes the denial message through a one-time error ticket (`AdminLoginErrorTicketStore`) instead of the URL. Rules are part of config export/import (not in the original plan).
- **Phase 7:** the registry also indexes by local account (`resolveByUser()`, needed for admin logout once `jti`s rotate). Admin session destruction ends **all** of that admin's sessions (refresh tokens revoked + `last_updated_password_at` bumped): Shopware access tokens are stateless and `revokeAccessToken()` is a no-op, so a single admin session can't be targeted. Customer destruction is exact (`SalesChannelContextPersister::delete()`).
- **Phase 12:** Shopware 6.7 has no CSP collector API — implemented as a
  `kernel.response` subscriber that only appends IdP origins to directives an
  existing policy already declares.

## Dependency graph (remaining)

```
Phase 6  Claims-based access-control rules engine     (shipped)
Phase 7  Session/subject registry (foundational)      (shipped)
Phase 8  Rate limiting + Back-Channel Logout          (needs 7)
Phase 8b Front-Channel Logout                         (needs 7, 8)
Phase 8c Admin-side RP-initiated logout               (needs 7, 8)
Phase 10 Audit/session-activity log + admin UI         (needs 7, 8, 8b, 8c)
Phase 11 Health-check/diagnostics + alerting           (deps 2, 3 shipped — ready)
Phase 13 Integration test harness (Dex)                (stretch goal)
Phase 14 Setup guides                                  (optional)
```

---

## Phase 6 — Claims-based access-control rules engine (shipped)

- [x] New migration `Migration<ts>CreateAccessControlRuleSchema` — table
      `sw6oidc_access_control_rule`: `id`, `provider_id` (FK, cascade),
      `claim_key`, `operator` (`eq`/`neq`/`contains`/`not_contains`/`exists`/
      `not_exists`), `value` (nullable), `error_message` (nullable),
      `sort_order`.
- [x] New `Sw6OidcAccessControlRuleDefinition`/`Entity`/`Collection` triad.
- [x] New `src/Service/Security/Sw6OidcAccessControlEvaluator.php` —
      `evaluate(providerId, flattenedClaims, Context)`, AND-combines rules
      ordered by `sort_order`, throws `AccessControlDeniedException` carrying
      the configured message.
- [x] Hook into `OidcCallbackProcessor::process()` right after
      `ClaimsNormalizer::flatten()` and before `AttributeMapper::map()`.
- [x] Controllers (`OidcCallbackController`, `OidcAdminAuthController::callback`)
      catch `AccessControlDeniedException` to surface the configured message.
- [x] Admin Vue — new nested one-to-many editor on `sw6oidc-provider-detail`,
      following the existing `attributeMappings`/`roleMappings` pattern.
- [x] Tests: `Sw6OidcAccessControlEvaluatorTest.php` (all 6 operators,
      AND-combination, first-failure-wins), `OidcCallbackProcessorAccessControlTest.php`.
- [x] Docs: `README.md`/`CLAUDE.md` — new "Claims-based access control" section.

---

## Phase 7 — Session/subject registry (foundational) (shipped)

Ships no user-visible behavior by itself — pure plumbing that Phases
8/8b/8c/10 build on.

- [x] New `src/Service/Session/Sw6OidcSessionRegistry.php` —
      `register(sub, sid, sessionKey, userType, userId, ttl=86400)`,
      `resolve()`, `resolveBySid()`, `revoke()`, `revokeBySid()`. Backed by
      plain `cache.app` (not `AtomicCacheInterface`). `sessionKey` = sales-
      channel context token (Storefront) or admin access-token `jti` (Admin).
- [x] New `src/Service/Session/Sw6OidcSessionDestructionService.php` —
      Storefront: invalidate the sales-channel-context token via Shopware
      core's context-persister delete. *(verify exact class/method against the
      installed 6.7 version and that it forces re-auth)* Admin: revoke access +
      refresh token via the already-wired `AccessTokenRepository`/
      `RefreshTokenRepository` using the stored `jti`.
- [x] `AdminLoginNonceService::createNonce()` — extend signature to carry
      `providerId`/`idToken` forward so the registry entry can be written once
      the minted token's `jti` is known in `exchangeNonce()`.
- [x] `OidcAdminAuthController::exchangeNonce()` and `OidcCallbackController` —
      each register a session-registry entry alongside existing logout-
      context/nonce bookkeeping.
- [x] Tests: `Sw6OidcSessionRegistryTest.php`, `Sw6OidcSessionDestructionServiceTest.php`,
      `AdminLoginNonceServiceTest.php` (extended payload).
- [x] Docs: `CLAUDE.md` — new "Session/subject registry" subsection,
      documenting the storefront-vs-admin destruction asymmetry.

---

## Phase 8 — Rate limiting + Back-Channel Logout

**Rate limiting:**
- [ ] New `src/Service/Security/Sw6OidcRateLimiter.php` — wraps Symfony's
      `RateLimiterFactory`, constructed directly in `services.xml` (a plugin
      cannot register into Shopware core's own `shopware.api.rate_limiter`
      registry). Fixed-window, 10 req/60s, storage via
      `Symfony\Component\RateLimiter\Storage\CacheStorage`. *(verify whether
      `cache.rate_limiter` pool id exists in 6.7; fall back to `cache.app` if
      not)*
- [ ] Apply to the new logout endpoints and, cheaply, the existing callback
      controllers too.

**Back-Channel Logout:**
- [ ] New `src/Controller/Oidc/BackChannelLogoutController.php`
      (`POST /sw6oidc/backchannel-logout`, unauthenticated).
- [ ] `JwtVerifier::decodeUnverified()` — new method, decode without verify
      (to read `iss` before a provider is known).
- [ ] `ProviderResolver::findByIssuer()` — new method.
- [ ] Reuse `JwtVerifier::verify()` (passing `expectedNonce = null`) for
      signature checking; validate `events` claim + `aud` + presence of
      `sub`/`sid`; rate-limit by IP; resolve + revoke via the Phase 7 registry;
      destroy via the destruction service. Return bare 200 on success
      (including "already logged out"), 400 on validation failure.
- [ ] Tests: full negative/positive matrix for the controller, plus
      `decodeUnverified`/`findByIssuer` unit tests. Highest-value candidate
      for an early Dex-backed integration test (unauthenticated,
      JWT-signature-gated).
- [ ] Docs: `CLAUDE.md` — new "Back-Channel Logout" subsection; remove the
      corresponding gap bullet.

---

## Phase 8b — Front-Channel Logout

- [ ] New `src/Controller/Oidc/FrontChannelLogoutController.php`
      (`GET /sw6oidc/frontchannel-logout`, reads `sid`, rate-limited,
      resolves/destroys/revokes via Phase 7's registry). **Always** returns a
      1×1 GIF (HTTP 200) regardless of outcome.
- [ ] Tests: valid sid destroys session; unknown sid and rate-limit-exceeded
      both still return a 200 GIF.
- [ ] Docs: `CLAUDE.md` subsection alongside Back-Channel Logout.

---

## Phase 8c — Admin-side RP-initiated logout

- [ ] New migration adding `post_logout_url` (nullable string, 1024) to
      `sw6oidc_provider` — per-provider landing-page override.
- [x] `OidcAdminAuthController::logout()` action (`POST /api/sw6oidc/admin/logout`,
      authenticated — route-level override of the controller's class-level
      `auth_required: false`), returning `{"logoutUrl": ...}` as JSON. Uses
      `LogoutContextStore::consumeForAdmin(userId)` for now; switch to the
      Phase 7 session registry once it exists.
- [ ] Shared `postlogout` landing action mirroring Magento's unified
      controller, for IdPs with a single registered redirect URI.
- [x] Admin Vue — `extension/sw-admin-menu` overrides `onLogoutUser()` to call
      the new endpoint before falling through to normal local logout.
- [x] Tests: context hit vs miss, inactive provider (`OidcAdminAuthControllerLogoutTest`).
- [x] Docs: `CLAUDE.md` Logout section — update the "gaps" bullet; `README.md`.

---

## Phase 10 — Audit/session-activity log + admin UI

**Depends on Phases 7, 8, 8b, 8c.** Decision: add a **new** table rather than
repurposing `sw6oidc_user_provider` (permanent one-row-per-account binding,
structurally incompatible with "one row per login").

- [ ] New migration — `sw6oidc_session_activity`: `id`, `provider_id`
      (FK, `SET NULL` on delete), `user_type`, `user_id`, `sub`, `sid`,
      `login_method` (`oidc`/`passkey`), `ip_address`, `user_agent`,
      `logged_in_at`, `logged_out_at`, `logout_reason`.
- [ ] New `Sw6OidcSessionActivityDefinition`/`Entity`/`Collection` triad.
- [ ] New `src/Service/Session/Sw6OidcSessionActivityRecorder.php` —
      `recordLogin()` called from all four login-completing controllers,
      `recordLogout()` called from the three logout controllers.
- [ ] New admin module `module/sw6oidc-sessions/` mirroring the existing
      `module/sw6oidc-passkey` structure (`entity`-driven auto-ACL grid,
      "Force logout" row action via a new `SessionActivityController::forceLogout()`
      endpoint).
- [ ] Tests: recorder tests, force-logout controller test.
- [ ] Docs: `CLAUDE.md` — new module reference entry; `README.md` — new admin
      screen mention.

---

## Phase 11 — Health-check/diagnostics + proactive alerting

**Depends on Phases 2 (webhook URL reuses the encrypted-field infra) and 3
(SSRF re-validation).**

- [ ] New migration adding to `sw6oidc_provider`: `health_alert_webhook_url`
      (`Sw6OidcEncryptedField`), `health_alert_failure_threshold` (int,
      default 0 = opt-out), `health_alert_notify_on_recovery` (bool), plus
      cron-owned runtime state: `health_alert_consecutive_failures`,
      `health_alert_last_status`, `health_alert_first_failure_at`,
      `health_alert_last_notified_at`.
- [ ] New `src/Controller/HealthCheckController.php` (`GET /sw6oidc/health`,
      unauthenticated, config-completeness-only — **no outbound HTTP calls**).
- [ ] New `src/Service/Health/ProviderReachabilityChecker.php` — JWKS `keys`
      field check, fallback to discovery doc; re-validates SSRF immediately
      before every fetch.
- [ ] New `src/Controller/Api/OidcDiagnosticsController.php` — authenticated
      on-demand probe.
- [ ] New `src/ScheduledTask/HealthCheckAlertTask.php` +
      `HealthCheckAlertTaskHandler.php` (Shopware's `ScheduledTask`/
      `ScheduledTaskHandler`, tagged `messenger.message_handler`) — queries
      providers with threshold+webhook configured, probes, posts JSON alert
      once per outage + optional recovery notice. *(verify whether a
      `ScheduledTask` needs a corresponding `scheduled_task` table row beyond
      the messenger tag)*
- [ ] New `src/Service/Health/WebhookNotifier.php` — thin `OidcHttpClient`
      wrapper.
- [ ] Admin Vue — diagnostics panel + new threshold/webhook form fields on
      the provider detail page.
- [ ] Tests: reachability checker, threshold-crossing/recovery/no-re-fire-on-
      edit-mid-outage, health endpoint makes zero outbound calls.
- [ ] Docs: `CLAUDE.md` — new "Health checks & alerting" section; `README.md`.

---

## Phase 13 — Integration test harness (remaining part)

The unit suite is in place (OIDC core, provisioning, WebAuthn ceremonies,
every security component). Still open:

- [ ] Integration test harness against a real IdP (Dex, docker-compose-based,
      matching Magento's approach) — stretch goal, not a hard gate; requires a
      full Shopware kernel-bootstrap test skeleton that doesn't exist yet.
      Priority order: (1) Back-Channel Logout (once Phase 8 exists), (2) full
      Storefront OIDC login E2E, (3) full Admin OIDC login E2E, (4) access-control
      rules engine against real claims (once Phase 6 exists).
- [ ] CI: add a 5th job + `phpunit.xml.dist` `integration` testsuite split if
      the Dex harness lands.

---

## Phase 14 — Documentation (remaining part)

- [ ] Optional: `Docs/authelia-sw6oidc-setup.md` / `Docs/zitadel-sw6oidc-setup.md`,
      mirroring the Magento sibling's setup guides.
- [ ] Keep `CHANGELOG.md`, `README.md` "Known Limitations" and `CLAUDE.md`
      "Known gaps" in sync as each phase above ships.

---

## Verification / testing (apply per phase)

- After each phase: `composer ci` (cs-check → phpstan → psalm → rector →
  test) must stay green.
- Schema-adding phases: run `bin/console database:migrate Sw6Oidc --all`
  against a real Shopware 6.7 install and confirm the new tables/columns,
  then exercise the affected admin UI screen manually.
- Security-critical phases (6 access control, 8 back-channel logout) should
  get a manual end-to-end pass against a real IdP (Authelia or Keycloak) in
  addition to unit tests.
- After deploying to a running shop, reset PHP-FPM opcache in addition to
  `cache:clear` — stale opcache served the removed webauthn 4.x interface
  during the 5.x migration.

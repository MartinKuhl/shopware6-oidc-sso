# TODO — Feature parity with magento2-oidc-sso

This file tracks the full roadmap to bring `shopware6-oidc-sso` to feature parity
with its sibling `magento2-oidc-sso` module. Each phase below is intended to be
one shippable PR. Phases 1–3 have no interdependency and can be done in any
order; everything from Phase 7 onward is a hard dependency chain (see the
dependency graph). Check off items as they ship.

## Dependency graph

```
Phase 0  Migrate web-auth/webauthn-lib 4.x → 5.x      (no deps, independent of everything else)
Phase 1  Redis wiring + JWKS circuit breaker          (no deps)
Phase 2  Encrypt client_secret at rest                (no deps)
Phase 3  SSRF validation + lockout guard              (no deps)
Phase 4  Sync-on-SSO toggles + transform functions    (no deps)
Phase 5  Domain events for JIT provisioning           (needs 4 — same classes)
Phase 6  Claims-based access-control rules engine     (no deps, own entity)
Phase 7  Session/subject registry (foundational)      (no deps, but nothing consumes it until 8)
Phase 8  Rate limiting + Back-Channel Logout          (needs 7)
Phase 8b Front-Channel Logout                         (needs 7, 8)
Phase 8c Admin-side RP-initiated logout               (needs 7, 8)
Phase 9  CLI config export/import                     (needs 2, 3)
Phase 10 Audit/session-activity log + admin UI         (needs 7, 8, 8b, 8c)
Phase 11 Health-check/diagnostics + alerting           (needs 2, 3)
Phase 12 CSP integration                               (no deps; unconfirmed API)
Phase 13 Test coverage + CI hardening                  (incremental throughout, formalized here)
Phase 14 Documentation sweep                           (incremental throughout, formalized here)
```

---

## Phase 0 — Migrate `web-auth/webauthn-lib` from `^4.7` to `5.x`

Currently pinned to `^4.7` because the Passkey ceremony code is written
against the 4.x API. 5.x removed that API entirely:

- `Webauthn\PublicKeyCredentialSourceRepository` (the interface
  `PasskeyCredentialRepository` implements) no longer exists in 5.x.
- `AuthenticatorAttestationResponseValidator` / `AuthenticatorAssertionResponseValidator`
  no longer take a repository in their constructor. Instead, the caller looks
  up the credential itself and passes it directly into `check()`.
- The credential model moved from `Webauthn\PublicKeyCredentialSource` to
  `Webauthn\CredentialRecord`.
- `check()`'s signature also changed: it now takes a plain `$host` string
  instead of a PSR-7 `ServerRequestInterface`.

Why bother: 4.x is in maintenance mode; 5.x is where active development and
security fixes are happening. Magento's sibling module is already on `^5.3`
with no migration pending — this is a real parity gap, not just tech debt.

- [ ] `src/Service/Passkey/PasskeyCredentialRepository.php` — stop implementing
      the removed interface; expose plain lookup methods returning
      `CredentialRecord` instead of `PublicKeyCredentialSource`.
- [ ] `src/Service/Passkey/WebauthnCeremonyFactory.php` — update the
      `AuthenticatorAttestationResponseValidator`/`AuthenticatorAssertionResponseValidator`
      construction to the no-repository-argument constructors.
- [ ] `src/Service/Passkey/PasskeyRegistrationService.php` and
      `PasskeyAuthenticationService.php` — do the credential lookup themselves
      before calling `check()`, and pass a host string instead of the PSR-7
      request.
- [ ] `composer.json` — bump `web-auth/webauthn-lib` to `^5.3` once the above
      compiles and passes tests.
- [ ] Verify whether the documented webauthn-lib 4.9.3 bug this plugin works
      around in `PasskeyRegistrationService::buildCreationOptions()` (url-safe
      vs standard base64 mismatch between `jsonSerialize()`/`createFromArray()`)
      still exists in 5.x; simplify the workaround if not, keep it if so.
- [ ] Tests: extend `AdminPasskeyLoginTokenTrackerTest.php`/`PasskeyConfigTest.php`
      if their fixtures reference removed 4.x types; add coverage for the new
      lookup-then-`check()` flow in both registration and authentication
      services (currently untested).
- [ ] Docs: `CLAUDE.md` Passkey architecture section — update version
      reference, drop the "why it's deferred" framing; `README.md` — update
      the dependency version and drop the pin caveat in Known Limitations.

---

## Phase 1 — Redis atomic cache wiring + JWKS circuit breaker

**Redis wiring:** `RedisAtomicCache`/`RedisConnectionFactory` already exist in
`src/Service/Cache/` but aren't wired into `services.xml` — only
`CachePoolAtomicCache` (single-node) is aliased today. Aliasing must happen at
container-compile time, not via a runtime `%env()%` default in plain XML.

- [ ] New `src/DependencyInjection/Compiler/Sw6OidcCachePass.php`
      (`CompilerPassInterface`) — checks whether `SW6OIDC_REDIS_DSN` is set and
      swaps the `AtomicCacheInterface` alias target from `CachePoolAtomicCache`
      to `RedisAtomicCache` accordingly. *(verify exact Shopware/Symfony
      pattern for reading an env var inside a compiler pass at compile time)*
- [ ] `src/Sw6Oidc.php` — override `build(ContainerBuilder $container)` to
      register the compiler pass.
- [ ] `services.xml` — add the missing `RedisAtomicCache` service definition
      (constructor: `RedisConnectionFactory`, fallback `CachePoolAtomicCache`,
      logger).

**JWKS circuit breaker:** `JwtVerifier::getJwks()` does a synchronous blocking
HTTP call on every cache miss with no failure-counting.

- [ ] Modify `src/Service/Jwt/JwtVerifier.php::getJwks()` (or extract
      `src/Service/Jwt/JwksCircuitBreaker.php`) — store a failure flag in the
      existing `cache.app` pool (key `sw6oidc_jwks_fail_<sha256(jwksEndpoint)>`,
      60s TTL) after a failed fetch; short-circuit further fetches while set.
- [ ] Tests: `RedisAtomicCacheTest.php`, `RedisConnectionFactoryTest.php`,
      `Sw6OidcCachePassTest.php`, `JwtVerifierCircuitBreakerTest.php`.
- [ ] Docs: `CLAUDE.md` "Known gaps" — remove the Redis/circuit-breaker
      bullets; `README.md` — mention `SW6OIDC_REDIS_DSN` auto-wiring.

---

## Phase 2 — Encrypt `client_secret` at rest

`client_secret` is currently plaintext on `sw6oidc_provider` (the entity has a
`// TODO(later phase): encrypt at rest` comment). Magento's sibling module
encrypts secrets at rest today — this closes that gap.

- [ ] New `src/Service/Security/Sw6OidcEncryptor.php` — wraps
      `sodium_crypto_secretbox`, key derived from `APP_SECRET` via
      `sodium_crypto_generichash(...)`. `encrypt()`/`decrypt()` with a
      `sw6oidc_v1:` envelope prefix; decrypt returns input unchanged + logs a
      warning on a non-matching/corrupt value. Constructible with just a
      string (no DI container) so a `Migration` can instantiate it directly.
- [ ] New `src/Core/Content/Provider/Field/Sw6OidcEncryptedField.php` (marker
      subclass of `StringField`) + `Sw6OidcEncryptedFieldSerializer.php`
      (tagged `shopware.field_serializer`) — encrypts on write, decrypts on
      read, fully transparent to `Sw6OidcProviderEntity::getClientSecret()`.
- [ ] `Sw6OidcProviderDefinition.php` — change `client_secret` from
      `StringField` to `Sw6OidcEncryptedField('client_secret', 'clientSecret', 1024)`.
- [ ] New `src/Migration/Migration<ts>EncryptExistingProviderClientSecrets.php`
      — data-only migration, idempotent (skips already-`sw6oidc_v1:`-prefixed
      rows).
- [ ] `services.xml` — register `Sw6OidcEncryptor` (`%env(APP_SECRET)%`) and
      the field serializer.
- [ ] Tests: `Sw6OidcEncryptorTest.php` (round-trip, garbage passthrough),
      `Sw6OidcEncryptedFieldSerializerTest.php`, migration backfill test.
- [ ] Docs: `CLAUDE.md` — remove the plaintext-secret gap bullet, add an
      "Encryption" subsection; `README.md` — drop the plaintext caveat.

---

## Phase 3 — SSRF validation + provider save-time lockout guard

- [ ] New `src/Service/Security/SsrfUrlValidator.php` — HTTPS-only, rejects
      loopback/RFC-1918 hosts (port of Magento's validator).
- [ ] New `src/EventSubscriber/Provider/Sw6OidcProviderWriteGuardSubscriber.php`
      — subscribes to `PreWriteValidationEvent` for every DAL write to
      `sw6oidc_provider`:
  - [ ] SSRF-validates every endpoint URL field, attaching a field-scoped
        constraint violation on failure. *(verify exact Shopware 6.7 API for
        attaching violations to this event)*
  - [ ] Lockout guard: when a write sets `disable_non_oidc_admin_login`/
        `disable_non_oidc_customer_login` to true, checks
        `sw6oidc_user_provider` for at least one bound account; reject the
        write with a clear violation message if none exist. *(verify whether
        silent payload mutation is supported here before considering it —
        default to reject-with-violation)*
- [ ] `services.xml` — register `SsrfUrlValidator` and the subscriber.
- [ ] Admin Vue — surface violation messages via existing DAL-error-to-
      notification plumbing; add a snippet key for the lockout message.
- [ ] Tests: `SsrfUrlValidatorTest.php`, `Sw6OidcProviderWriteGuardSubscriberTest.php`.
- [ ] Docs: `CLAUDE.md` — new "Provider save-time validation" subsection; note
      here that Phases 9/11 must re-validate SSRF immediately before every
      outbound call, not just rely on save-time checks.

---

## Phase 4 — Sync-on-SSO toggles + attribute transform functions

No schema changes — purely wiring up existing dead columns
(`sync_customer_profile_on_sso`, `sync_customer_address_on_sso`,
`sync_customer_group_on_sso`, `sync_admin_profile_on_sso`, and per-attribute
`sync_on_sso`/`transform_function`/`transform_params` on
`sw6oidc_attribute_mapping`).

- [ ] New `src/Service/Provisioning/AttributeTransformer.php` — `concat`,
      `split`, `prefix`, `regex_replace` (length-capped at 4096 bytes), never
      throws (logs + passthrough on error).
- [ ] Hook into `AttributeMapper::map()`'s claim-read closure to call
      `apply($mapping->getTransformFunction(), $mapping->getTransformParams() ?? [], $rawValue, $claims)`.
- [ ] Add `AttributeMapper::mapForSync()` — maps only rows where per-attribute
      `sync_on_sso` is true, for use by the new sync methods below (distinct
      from creation-time `map()`).
- [ ] `CustomerProvisioningService` — add `syncProfile()`/`syncAddress()`/
      `syncGroup()`, gated on `isSyncCustomerProfileOnSso()`/
      `isSyncCustomerAddressOnSso()`/`isSyncCustomerGroupOnSso()`, mirroring
      `AdminProvisioningService::syncRole()`'s existing pattern.
- [ ] `AdminProvisioningService` — add `syncProfile()`, gated on
      `isSyncAdminProfileOnSso()`.
- [ ] `services.xml` — new `AttributeTransformer` service (dep: `OidcLogger`);
      `AttributeMapper` gains it as a constructor arg.
- [ ] Tests: `AttributeTransformerTest.php`, `AttributeMapperTransformTest.php`,
      `CustomerProvisioningServiceSyncTest.php`, `AdminProvisioningServiceSyncTest.php`.
- [ ] Docs: `CLAUDE.md` — remove both "known gaps" bullets; add sync-on-SSO +
      transform subsections.

---

## Phase 5 — Domain events for JIT provisioning

- [ ] New `src/Event/CustomerBeforeCreateEvent.php`, `CustomerAfterCreateEvent.php`,
      `AdminBeforeCreateEvent.php`, `AdminAfterCreateEvent.php`,
      `AttributeMappingCompletedEvent.php` (extend
      `Shopware\Core\Framework\Event\ShopwareEvent`). "Before" events mutable,
      "after" events read-only snapshots.
- [ ] `CustomerProvisioningService`/`AdminProvisioningService`/`AttributeMapper`
      constructors gain `EventDispatcherInterface $eventDispatcher` (Shopware's
      `event_dispatcher` service); dispatch at the equivalent creation points.
- [ ] `services.xml` — add `event_dispatcher` argument to the three services.
- [ ] Tests: dispatch-order tests per service, asserting a before-event
      listener's mutation is honored.
- [ ] Docs: `CLAUDE.md` — new "Extension points / events" subsection.

---

## Phase 6 — Claims-based access-control rules engine

- [ ] New migration `Migration<ts>CreateAccessControlRuleSchema` — table
      `sw6oidc_access_control_rule`: `id`, `provider_id` (FK, cascade),
      `claim_key`, `operator` (`eq`/`neq`/`contains`/`not_contains`/`exists`/
      `not_exists`), `value` (nullable), `error_message` (nullable),
      `sort_order`.
- [ ] New `Sw6OidcAccessControlRuleDefinition`/`Entity`/`Collection` triad.
- [ ] New `src/Service/Security/Sw6OidcAccessControlEvaluator.php` —
      `evaluate(providerId, flattenedClaims, Context)`, AND-combines rules
      ordered by `sort_order`, throws `AccessControlDeniedException` carrying
      the configured message.
- [ ] Hook into `OidcCallbackProcessor::process()` right after
      `ClaimsNormalizer::flatten()` and before `AttributeMapper::map()`.
- [ ] Controllers (`OidcCallbackController`, `OidcAdminAuthController::callback`)
      catch `AccessControlDeniedException` to surface the configured message.
- [ ] Admin Vue — new nested one-to-many editor on `sw6oidc-provider-detail`,
      following the existing `attributeMappings`/`roleMappings` pattern.
- [ ] Tests: `Sw6OidcAccessControlEvaluatorTest.php` (all 6 operators,
      AND-combination, first-failure-wins), `OidcCallbackProcessorAccessControlTest.php`.
- [ ] Docs: `README.md`/`CLAUDE.md` — new "Claims-based access control" section.

---

## Phase 7 — Session/subject registry (foundational)

Ships no user-visible behavior by itself — pure plumbing that Phases
8/8b/8c/10 build on.

- [ ] New `src/Service/Session/Sw6OidcSessionRegistry.php` —
      `register(sub, sid, sessionKey, userType, userId, ttl=86400)`,
      `resolve()`, `resolveBySid()`, `revoke()`, `revokeBySid()`. Backed by
      plain `cache.app` (not `AtomicCacheInterface`). `sessionKey` = sales-
      channel context token (Storefront) or admin access-token `jti` (Admin).
- [ ] New `src/Service/Session/Sw6OidcSessionDestructionService.php` —
      Storefront: invalidate the sales-channel-context token via Shopware
      core's context-persister delete. *(verify exact class/method against the
      installed 6.7 version and that it forces re-auth)* Admin: revoke access +
      refresh token via the already-wired `AccessTokenRepository`/
      `RefreshTokenRepository` using the stored `jti`.
- [ ] `AdminLoginNonceService::createNonce()` — extend signature to carry
      `providerId`/`idToken` forward so the registry entry can be written once
      the minted token's `jti` is known in `exchangeNonce()`.
- [ ] `OidcAdminAuthController::exchangeNonce()` and `OidcCallbackController` —
      each register a session-registry entry alongside existing logout-
      context/nonce bookkeeping.
- [ ] Tests: `Sw6OidcSessionRegistryTest.php`, `Sw6OidcSessionDestructionServiceTest.php`,
      `AdminLoginNonceServiceTest.php` (extended payload).
- [ ] Docs: `CLAUDE.md` — new "Session/subject registry" subsection,
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
- [ ] `OidcAdminAuthController::logout()` action (`POST /api/sw6oidc/admin/logout`,
      authenticated — route-level override of the controller's class-level
      `auth_required: false`). Extract caller's token `jti`, look up the
      session registry for `providerId`/`idToken`, destroy the session, call
      the existing `RpInitiatedLogoutService::buildLogoutUrl()`/`revokeToken()`,
      return `{"logoutUrl": ...}` as JSON (not a redirect).
- [ ] Shared `postlogout` landing action mirroring Magento's unified
      controller, for IdPs with a single registered redirect URI.
- [ ] Admin Vue — extend `sw-login` (or find the correct logout-interception
      point — *verify during implementation*) to call the new endpoint before
      falling through to normal local logout.
- [ ] Tests: registry-hit vs registry-miss logout, revoke-token failure never
      blocks logout.
- [ ] Docs: `CLAUDE.md` Logout section — update the "gaps" bullet; `README.md`.

---

## Phase 9 — CLI config export/import

**Depends on Phases 2 and 3.**

- [ ] New `src/Console/ExportOidcConfigCommand.php`
      (`sw6oidc:config:export [--provider-id=] [--output=]`) — keeps
      `client_secret` in its encrypted envelope by default; `--plaintext`
      opt-out for portability testing, documented as insecure.
- [ ] New `src/Console/ImportOidcConfigCommand.php`
      (`sw6oidc:config:import --input= [--dry-run] [--overwrite]`) —
      encrypts plaintext secrets on import; validation is largely free via
      Phase 3's `PreWriteValidationEvent` subscriber as long as the import
      goes through the repository.
- [ ] `services.xml` — register both via `console.command` tag.
- [ ] Tests: round-trip export→import reproduces original config; secret
      stays encrypted at every step; `--overwrite`/`--dry-run` semantics.
- [ ] Docs: `CLAUDE.md` "Development commands" — add the two new commands.

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

## Phase 12 — CSP integration

**Built against an unconfirmed API** — whether Shopware 6.7 exposes a clean
CSP-contribution extension point (like Magento's `PolicyCollectorInterface`)
or only a fixed core-owned header.

- [ ] Investigate: does a collector/tagged-service extension point exist?
  - [ ] If yes — implement against it directly (dedupe HTTPS hosts from all
        active providers' endpoints, contribute to `form-action`/`connect-src`/
        `frame-src`/`img-src`).
  - [ ] If no — a `KernelEvents::RESPONSE` subscriber with lower priority than
        core's `CoreSubscriber`, string-parsing and re-setting the header.
- [ ] New `src/Service/Security/Sw6OidcCspHostCollector.php` — host-collection
      logic, independent of which wiring path is chosen.
- [ ] Tests: `Sw6OidcCspHostCollectorTest.php` (dedup, HTTPS-only, no-active-
      providers → empty).
- [ ] Docs: `CLAUDE.md` — document which path was actually taken and why.

---

## Phase 13 — Test coverage + CI hardening

Every phase above already specifies its own unit tests as it ships — continue
that incremental approach rather than deferring to one big testing phase.

- [ ] Close remaining gaps on pre-existing, untested code: `OidcSecurityHelper`
      (state/PKCE/nonce), `JwtVerifier::verify()`, `ClaimsNormalizer`, the core
      (non-sync) creation paths in both provisioning services.
- [ ] Integration test harness against a real IdP (Dex, docker-compose-based,
      matching Magento's approach) — stretch goal, not a hard gate; requires a
      full Shopware kernel-bootstrap test skeleton that doesn't exist yet. If
      pursued, priority order: (1) Back-Channel Logout, (2) full Storefront
      OIDC login E2E, (3) full Admin OIDC login E2E, (4) access-control rules
      engine against real claims.
- [ ] CI: add a 5th job + `phpunit.xml.dist` `integration` testsuite split if
      the Dex harness lands.
- [ ] Docs: `CLAUDE.md` — remove "test coverage is thin" once genuinely closed.

---

## Phase 14 — Documentation sweep

- [ ] `README.md` — remove/adjust every "Known Limitations" bullet closed by
      the phases above.
- [ ] `CLAUDE.md` — final consistency read-through.
- [ ] `TODO.md` (this file) — remove completed phases, reconcile any deferred
      items (CSP path decision, integration-harness stretch goal).
- [ ] New `CHANGELOG.md` (Keep-a-Changelog format, backfilled per phase —
      `LICENSE.txt` already exists, only the changelog is genuinely missing).
- [ ] Optional: `Docs/authelia-sw6oidc-setup.md` / `Docs/zitadel-sw6oidc-setup.md`,
      mirroring the Magento sibling's setup guides.

---

## Summary table

| Phase | New migration? | New admin UI? | Depends on |
|---|---|---|---|
| 0 | No | No | — |
| 1 | No | No | — |
| 2 | Yes (data-only) | No | — |
| 3 | No | No (error surfacing only) | — |
| 4 | No | No | — |
| 5 | No | No | 4 |
| 6 | Yes | Yes (rule editor) | — |
| 7 | No | No | — |
| 8 | No | No | 7 |
| 8b | No | No | 7, 8 |
| 8c | Yes | Yes (logout override) | 7, 8 |
| 9 | No | Optional | 2, 3 |
| 10 | Yes | Yes (sessions module) | 7, 8, 8b, 8c |
| 11 | Yes | Yes (diagnostics panel) | 2, 3 |
| 12 | No | No | — |
| 13 | — | — | incremental throughout |
| 14 | — | — | incremental throughout |

## Verification / testing (apply per phase)

- After each phase: `composer ci` (cs-check → phpstan → psalm → rector →
  test) must stay green.
- Schema-adding phases: run `bin/console database:migrate Sw6Oidc --all`
  against a real Shopware 6.7 install and confirm the new tables/columns,
  then exercise the affected admin UI screen manually.
- Security-critical phases (2 encryption, 3 SSRF/lockout, 8 back-channel
  logout) should get a manual end-to-end pass against a real IdP (Authelia or
  Keycloak) in addition to unit tests.
- Phase 12 (CSP) and the `ScheduledTask` registration detail in Phase 11 both
  need their "verify during implementation" flags resolved against the actual
  installed Shopware version before considering the phase done.

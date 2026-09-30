# TODO — Feature parity with magento2-oidc-sso

Every phase of the parity roadmap has shipped (see [CHANGELOG.md](CHANGELOG.md)
and the architecture sections in [CLAUDE.md](CLAUDE.md)). What's left is at the
bottom. Phase numbers are kept from the original plan so history and commit
messages stay traceable.

## Shipped

Phases 0 (webauthn-lib 5.x), 1 (Redis + JWKS circuit breaker), 2 (encrypted
`client_secret`), 3 (SSRF validation + lockout guard + enforcement of the
`disable_non_oidc_*_login` flags), 4 (attribute transforms), 5 (provisioning
domain events), 6 (claims-based access control), 7 (session/subject registry),
8 (rate limiting + Back-Channel Logout), 8b (Front-Channel Logout), 8c
(Administration RP-initiated logout, post-logout URL, shared landing), 9 (CLI
export/import), 10 (session activity log + admin module), 11 (health checks,
diagnostics, webhook alerting), 12 (CSP), 13 (unit suite; integration harness
written) and 14 (Authelia, ZITADEL and Dex setup guides).

## Deviations from the original plan

Recorded so nobody "fixes" them back:

- **Phase 1:** no `Sw6OidcCachePass` compiler pass. `RedisAtomicCache` is always
  wired and picks Redis vs. the cache-pool fallback **at runtime**, because a
  compile-time choice would be frozen into Shopware's cached container. Also
  added: one rate-limited JWKS refetch on signature failure (key rotation).
- **Phase 2:** the key is derived from `APP_SECRET` only (no dedicated env var),
  and `client_secret` is write-only over the Admin API (no `ApiAware`).
- **Phase 3:** SSRF is also enforced at request time (`NoPrivateNetworkHttpClient`),
  with `SW6OIDC_ALLOW_INSECURE_IDP_URLS=1` as the dev escape hatch. The
  disable-password-login flags are enforced, with `SW6OIDC_ALLOW_PASSWORD_LOGIN=1`
  as break-glass. The subscriber lives in `src/Subscriber/`.
- **Phase 4:** the per-attribute `sync_on_sso` column was dropped instead of
  wired. The provider-level toggles are the only re-sync gate.
- **Phase 6:** matching is list-aware (`groups` matches `groups.0`, `groups.1`, …;
  Zitadel role-object names count as entries), case-insensitive, and treats
  `true`/`1`/`false`/`0` as booleans. Unknown operators fail closed. The admin
  callback passes the denial message through a one-time error ticket
  (`AdminLoginErrorTicketStore`) instead of the URL. Rules are part of config
  export/import.
- **Phase 7:** the registry also indexes by local account (`resolveByUser()`).
  Admin session destruction ends **all** of that admin's sessions (refresh
  tokens revoked, `last_updated_password_at` bumped), because Shopware access
  tokens are stateless and `revokeAccessToken()` is a no-op. Customer
  destruction is exact (`SalesChannelContextPersister::delete()`).
- **Phase 8:** the rate limiter uses a *penalty model*: only failed requests
  consume the 10/60s budget, so a busy IdP or a NAT'd office is never
  throttled. It is applied to the back-channel endpoint and both callbacks.
  `cache.rate_limiter` exists on 6.7 (`on-invalid="null"` falls back to
  `cache.app`). Logout tokens are checked by a dedicated
  `JwtVerifier::verifyLogoutToken()` rather than `verify(expectedNonce: null)`,
  which would have accepted id_tokens. `findByIssuer()` returns a list, and
  providers sharing an IdP are told apart by `aud`. `jti` replay protection
  was added.
- **Phase 8b:** `iss` and `sid` are both required. There is no cookie
  fallback, because SameSite cookies aren't sent in a cross-site iframe. The
  GIF sets `Content-Security-Policy: frame-ancestors *`.
- **Phase 8c:** the minimal version shipped ahead of Phase 7 (`LogoutContextStore`
  keyed by admin user id, `sw-admin-menu` `onLogoutUser()` override). Admin
  logout now reads the registry first, with that store as fallback.
  `post_logout_url` *replaces* the default post-logout redirect URI, so the
  shared `/sw6oidc/postlogout` landing is opt-in. It picks its target from an
  HMAC-signed `state` (`PostLogoutState`); Authelia's `rd` gets the state
  appended.
- **Phase 9:** the export omits the client secret by default
  (`--keep-encrypted` / `--plaintext` opt in). ACL roles and customer groups
  are resolved on import by id, then by unique name. The health-alert webhook
  URL and state are never exported.
- **Phase 10:** two internal columns were added beyond the plan:
  `session_key_hash` (sha256; raw context tokens and jtis are credentials and
  never stored) and `registry_session_id`. Force logout is exact only for
  customer OIDC sessions still in the registry; passkey logins and admins end
  all sessions of the account. There is a daily retention task
  (`SW6OIDC_SESSION_ACTIVITY_RETENTION_DAYS`, default 90). Scheduled tasks need
  no extra `scheduled_task` row handling, because core's
  `PluginLifecycleSubscriber` registers tagged tasks on install/update.
- **Phase 11:** the alert state columns are `WriteProtected` and written via
  DBAL, which guarantees there is no re-fire on an edit mid-outage.
  `health_alert_last_checked_at` was added. `/sw6oidc/health` also reports the
  last scheduled probe (still no outbound calls) and returns only counts.
  Undeliverable alerts are retried. `WebhookNotifier` uses the SSRF-guarded
  client directly, because `OidcHttpClient` rejects non-JSON responses.
- **Phase 12:** Shopware 6.7 has no CSP collector API, so this is a
  `kernel.response` subscriber that only appends IdP origins to directives an
  existing policy already declares.
- **Phase 13:** the integration suite has its own `phpunit.integration.xml.dist`
  instead of a second suite in `phpunit.xml.dist`, because it needs a different
  bootstrap (Shopware's `TestBootstrapper`). It must run with the shop's
  PHPUnit. The Back-Channel Logout test needs no Dex (the JWKS is seeded into
  `cache.app`).

## Still open

- [ ] Manual end-to-end pass of the security-critical flows (access-control
      rules, Back-/Front-Channel Logout, forced logout) against a real IdP that
      supports them (Keycloak or ZITADEL; Authelia and Dex support neither
      Back- nor Front-Channel Logout).
- [ ] Optional: a Keycloak setup guide (`Docs/keycloak-sw6oidc-setup.md`).
- [ ] Keep `CHANGELOG.md`, `README.md` (Known Limitations) and `CLAUDE.md`
      (Known gaps) in sync as things change.

## Verification checklist (per change)

- `composer ci` (cs-check → phpstan → psalm → rector → test) stays green.
- Schema changes: `bin/console database:migrate Sw6Oidc --all` against a real
  Shopware 6.7 install, then exercise the affected admin screen.
- Admin JS changes: rebuild (`bin/build-administration.sh`, or with
  `SHOPWARE_ADMIN_BUILD_ONLY_EXTENSIONS=1`) before testing in the browser.
- After deploying to a running shop, reset PHP-FPM opcache in addition to
  `cache:clear`: stale opcache served the removed webauthn 4.x interface
  during the 5.x migration, and served old routes (404) during this roadmap.

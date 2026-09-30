# Test Plan: `feature/todo-parity`

**Branch:** `feature/todo-parity`, 12 commits on top of `main` (`0d7316c`), 100 files changed.
**Shopware:** 6.7.x, with the plugin installed from this branch.
**Goal:** check every functional change on the branch before it is merged into `main`, and confirm that the existing SSO and passkey flows still work.

Each section says **what changed**, then gives test cases with **steps** and the **expected outcome**. Tick the cases off as you go. Section 1 is the upgrade and Section 14 is the regression smoke test; run both even if time is short.

---

## 0. Preparation

| Item | Details |
|---|---|
| Two environments | **A) Upgrade:** a shop running `main` with at least one configured provider (confidential client, secret set), existing attribute mappings, one admin and one customer who already signed in through SSO, and one passkey registered under the old plugin version. **B) Fresh install** of the branch. |
| Identity provider (IdP) | A reachable HTTPS IdP (Authelia or Keycloak) with a confidential client, and test users that have groups. A second, deliberately broken configuration for negative tests. |
| DB access | For checking stored values (`sw6oidc_provider`, `sw6oidc_attribute_mapping`, `sw6oidc_user_provider`, `sw6oidc_passkey_credential`). |
| Log file | `var/log/sw6oidc-<env>.log` (plugin channel). |
| Build | `bin/build-administration.sh` and `bin/build-storefront.sh`, then `bin/console cache:clear`. |
| PHP OPcache | If `opcache.validate_timestamps=0`, reload PHP-FPM after every code or `.env` change (`kill -USR2 <fpm-master-pid>`), or old code keeps running. |
| Env vars (new) | `SW6OIDC_ALLOW_INSECURE_IDP_URLS` (default 0), `SW6OIDC_ALLOW_PASSWORD_LOGIN` (default 0), `SW6OIDC_REDIS_DSN` (optional). Set them in `.env.local`, then clear the cache and reload PHP-FPM. |

**Automated checks (run once per environment):**

| ID | Steps | Expected outcome |
|---|---|---|
| AUTO-1 | `composer install`, then `composer ci` in the plugin directory | PHPCS, PHPStan, Psalm and Rector pass without errors, and PHPUnit is green, including the new tests (`JwtVerifierTest`, `JwtVerifierCircuitBreakerTest`, `OidcSecurityHelperTest`, `ClaimsNormalizerTest`, `Customer-`/`AdminProvisioningServiceTest`, `GroupMappingResolverTest`, `UserProviderBindingServiceTest`, `PasskeyCeremonyTest`, `PasskeyCredentialRepositoryTest`, `AttributeTransformerTest`, `SsrfUrlValidatorTest`, `Sw6OidcEncryptorTest`, `Sw6OidcEncryptedFieldSerializerTest`, `Migration1790685361EncryptProviderClientSecretsTest`, `Sw6OidcProviderWriteGuardSubscriberTest`, `PasswordLoginEnforcementTest`, `RedisAtomicCacheTest`, `RedisConnectionFactoryTest`, `OidcConfigTransferTest`, `Sw6OidcCspTest`, `TestResultTranslatorTest`) |
| AUTO-2 | GitHub Actions on the PR | All 4 jobs (lint, static-analysis, rector, tests on PHP 8.2–8.5) are green |

---

## 1. Upgrade and migrations

**What changed:**
- `Migration1790685361EncryptProviderClientSecrets` widens `client_secret` and encrypts existing values.
- `Migration1790686535DropAttributeMappingSyncOnSso` drops `sw6oidc_attribute_mapping.sync_on_sso` in the **destructive** step only.
- `web-auth/webauthn-lib` goes from 4.x to 5.3.

| ID | Steps | Expected outcome |
|---|---|---|
| UPG-1 | Env A: check out the branch, `composer install` (root), `bin/console plugin:update Sw6Oidc`, `cache:clear` | The update succeeds and no error appears in `var/log`. |
| UPG-2 | `SELECT client_secret FROM sw6oidc_provider` | Every non-empty secret starts with `sw6oidc_v1:`. No plaintext secret is left. |
| UPG-3 | Run `plugin:update` / `database:migrate Sw6Oidc --all` a second time | No-op: secrets are **not** encrypted twice (the prefix appears exactly once). |
| UPG-4 | Before the destructive step: `SHOW COLUMNS FROM sw6oidc_attribute_mapping` | `sync_on_sso` still exists, so the non-destructive update is backwards compatible. |
| UPG-5 | `bin/console database:migrate-destructive Sw6Oidc --all`, then check again | `sync_on_sso` is gone. The attribute mapping grid still loads and saves. |
| UPG-6 | Customer SSO login with the provider that existed before (Env A) | Login works, which proves the migrated, encrypted secret is decrypted correctly for the token exchange. |
| UPG-7 | Log in with a passkey that was **registered under 4.x** (Env A, customer and admin) | Login succeeds. `sign_count` in `sw6oidc_passkey_credential` increases or stays the same, with no error. |
| UPG-8 | Env B: `plugin:install --activate Sw6Oidc` on a fresh shop | All tables are created and both new migrations run without errors. |

---

## 2. Client secret: encryption and write-only API

**What changed:** `client_secret` is encrypted at rest (libsodium, key derived from `APP_SECRET`) and is no longer returned by the Admin API. An empty field on save keeps the stored secret. If the secret can't be decrypted, the login fails with a clear error.

| ID | Steps | Expected outcome |
|---|---|---|
| SEC-1 | Admin: create a provider with a client secret and save | Saving succeeds. In the DB the value starts with `sw6oidc_v1:`. |
| SEC-2 | Reopen the provider | The secret field is **empty**, with the placeholder "Gespeichert – leer lassen zum Beibehalten" / "Stored – leave empty to keep it". |
| SEC-3 | Change another field, leave the secret empty, save; then log in via SSO | Saving succeeds, the stored secret is kept (DB value unchanged), and login works. |
| SEC-4 | Enter a new secret and save | The DB value changes (new envelope), and login uses the new secret. |
| SEC-5 | `GET /api/sw6oidc-provider/<id>` and `POST /api/search/sw6oidc-provider` with an admin token | The response has **no** `clientSecret` field. |
| SEC-6 | "Test connection" with the secret field empty on an existing provider | The "Client credentials" check passes, because the stored secret is used. |
| SEC-7 | Change `APP_SECRET` in `.env.local`, clear the cache, reload FPM, try a customer SSO login | Login fails with the normal SSO error message. The plugin log contains "The client secret of OIDC provider … cannot be decrypted (was APP_SECRET changed?). Re-enter the client secret…". **No** ciphertext is sent to the IdP (check the IdP log). |
| SEC-8 | After SEC-7: enter the secret again in the admin and save | Login works again. |
| SEC-9 | Public client (`publicClient = true`) without a secret | Saving and login work, and no secret is required. |

---

## 3. SSRF protection for provider URLs

**What changed:** `SsrfUrlValidator` replaces `DiscoveryUrlValidator`. It allows **HTTPS only**, and every resolved IP must be public. The check runs **on save** for all 7 URL fields (well-known, authorize, token, userinfo, JWKS, end-session, revocation) and on **every request and redirect** at runtime (`NoPrivateNetworkHttpClient`). `SW6OIDC_ALLOW_INSECURE_IDP_URLS=1` relaxes both checks for development.

| ID | Steps | Expected outcome |
|---|---|---|
| SSRF-1 | Save a provider with `http://idp.example.com/...` as the token endpoint | Saving is rejected. The field shows the error "Only https URLs are allowed (set SW6OIDC_ALLOW_INSECURE_IDP_URLS=1 …)". |
| SSRF-2 | URLs pointing at `https://127.0.0.1/`, `https://10.0.0.5/`, `https://192.168.1.1/`, `https://169.254.169.254/`, `https://[::1]/`, or a hostname that resolves to a private IP | Each is rejected: "This URL resolves to a private, loopback, or otherwise non-public address …". The error shows **on the affected field**. |
| SSRF-3 | `ftp://…` or `file:///etc/passwd` | Rejected: "Unsupported URL scheme …". |
| SSRF-4 | A hostname that doesn't resolve | Rejected: "The host of this URL could not be resolved." |
| SSRF-5 | Auto-discovery with a blocked well-known URL | Discovery is refused with the same message, and no outbound request is made. |
| SSRF-6 | Runtime redirect: the token endpoint (public HTTPS) answers with a `302` to `http://169.254.169.254/` or a private IP | The login fails and the redirect is **not** followed (the plugin log shows a transport error). |
| SSRF-7 | Set `SW6OIDC_ALLOW_INSECURE_IDP_URLS=1` (cache clear + FPM reload), repeat SSRF-1 and SSRF-2 | Saving succeeds, with only a warning about plain HTTP or a private address. A local IdP (for example `http://localhost:9091`) works end to end. |
| SSRF-8 | Import via CLI (see Section 8) with a blocked URL | The provider is rejected with the same message and nothing is written. |

---

## 4. "Disable non-OIDC login" is now enforced

**What changed:** the flags used to be stored but ignored. Now they apply:
- **Storefront and Store API:** a `LoginRoute` decorator rejects password login.
- **Administration:** the `password` grant on `/api/oauth/token` is rejected with 403 and `SW6OIDC_PASSWORD_LOGIN_DISABLED`.

Both login screens hide the password form. **Lockout guard:** a flag can only be switched on once at least one account of that type is bound to the provider. `SW6OIDC_ALLOW_PASSWORD_LOGIN=1` is the break-glass override.

| ID | Steps | Expected outcome |
|---|---|---|
| PWD-1 | New provider with no bound admin account: switch on "Disable non-OIDC admin login" and save | Saving is rejected. The field shows "Password login for admin accounts can only be disabled once at least one admin account has signed in through this provider …". |
| PWD-2 | Same as PWD-1 for the customer flag | Rejected with the equivalent customer message. |
| PWD-3 | Log in once as an admin via SSO, then switch on the admin flag and save | Saving succeeds. |
| PWD-4 | Log out and open the Administration login page | The username/password form is **hidden**, and only the SSO and passkey buttons are shown. |
| PWD-5 | `curl -X POST /api/oauth/token -d 'grant_type=password&client_id=administration&username=…&password=…&scopes=write'` | HTTP **403**, `error: access_denied`, `errors[0].code = SW6OIDC_PASSWORD_LOGIN_DISABLED`. |
| PWD-6 | Admin SSO login and admin passkey login while the flag is on | Both still work. |
| PWD-7 | Integration token: `grant_type=client_credentials` with an integration's access key | Still works; only the `password` grant is blocked. |
| PWD-8 | Token refresh in a running admin session (wait more than 10 minutes, or trigger a refresh) | Works; `refresh_token` is not affected. |
| PWD-9 | Customer flag on (after a customer SSO login): open the storefront login page | The email/password form is replaced by an info alert: "Die Anmeldung mit Passwort ist für diesen Shop deaktiviert. Bitte melden Sie sich mit Single Sign-on oder einem Passkey an." The SSO and passkey buttons are still there. |
| PWD-10 | Store API: `POST /store-api/account/login` with email and password | HTTP 403 with error code `SW6OIDC_PASSWORD_LOGIN_DISABLED`. |
| PWD-11 | Force a storefront password POST (`/account/login` with credentials, for example via devtools) | Rejected, and the same message is shown. The customer is **not** logged in. |
| PWD-12 | The checkout login box and the registration page with the flag on | No password login is possible. Registration: document what you observe; it is not part of this change. |
| PWD-13 | Break-glass: `SW6OIDC_ALLOW_PASSWORD_LOGIN=1` (cache clear + FPM reload) | The password form is visible again and password login works for admin, Storefront and Store API. |
| PWD-14 | Flag on, provider set to **inactive** | Password login is possible again, because only active providers count. |
| PWD-15 | Flag on for customers only | Admin password login is unaffected, and the other way round. |

---

## 5. Attribute transforms

**What changed:** each attribute mapping row can have one transform: `concat`, `split`, `prefix` or `regex_replace` (regex capped at 4096 bytes). Transforms are applied on every login. A misconfigured transform logs a warning and passes the value through unchanged. The admin grid has a function select plus per-function parameter inputs.

| ID | Steps | Expected outcome |
|---|---|---|
| TRF-1 | Provider detail → attribute mapping: pick a row and open the "Transformation" select | The options are "Keine / Claims verketten / Aufteilen / Präfix / Regex-Ersetzung", with parameter fields that depend on the choice. Saving and reloading keeps the values. |
| TRF-2 | `lastname` = `family_name` with `prefix`, value `Dr. `; new customer via SSO | The customer's last name is "Dr. <family_name>". |
| TRF-3 | `firstname` = `name` ("Anna Maria Muster") with `split`, separator ` `, index `0` | First name "Anna". With index `-1`: "Muster". |
| TRF-4 | `lastname` = `given_name` with `concat`, claims `[family_name]`, separator ` ` | The value is "<given_name> <family_name>". |
| TRF-5 | `concat` on a row whose base claim is **missing** | The value is built from the additional claims only, with no error. |
| TRF-6 | `regex_replace`, pattern `/\s+/`, replacement `-` on the phone number | Whitespace is replaced by `-`. |
| TRF-7 | Invalid regex (for example `/(/`) or `split` with an empty separator | **Login still works.** The value is left untransformed and the plugin log shows the warning "attribute transform failed; using the untransformed value". |
| TRF-8 | Save a `transform_function` that isn't in the list (via the API) | Rejected by a Choice constraint. |
| TRF-9 | Transform on an existing customer with profile sync on | The transformed value is applied on sync too. |

---

## 6. JWKS circuit breaker and key rotation

**What changed:**
- **Circuit breaker:** a failed JWKS fetch (HTTP error, unparsable or empty key set) pauses further fetches for 60 s (`sw6oidc_jwks_fail_*`).
- **Key rotation:** a signature that fails against the cached key set triggers **one** forced refetch, at most once every 60 s per endpoint.
- The JWKS response is only cached once it has parsed.

| ID | Steps | Expected outcome |
|---|---|---|
| JWKS-1 | Rotate the IdP's signing key (Keycloak: add a new key and set it active, remove the old one), then SSO login immediately, **without** a cache clear | Login succeeds straight away (the refetch picks up the new key). Before this change it failed until the JWKS cache TTL ran out. |
| JWKS-2 | Make the JWKS endpoint return 500 (or block it), then log in | Login fails with a clear log entry. A second attempt within 60 s makes **no** new JWKS request (visible in the IdP/proxy log). After 60 s it tries again. |
| JWKS-3 | JWKS endpoint returns `{"keys": []}` or invalid JSON | The same as JWKS-2. The broken response is **not** cached: once the endpoint is fixed, login works after at most 60 s. |
| JWKS-4 | Several logins with a token that is genuinely invalid (wrong key) | At most one refetch every 60 s, not one per login. |

---

## 7. Redis atomic cache (optional)

**What changed:** `RedisAtomicCache` is always wired and chosen **at runtime**: Redis `GETDEL` when `SW6OIDC_REDIS_DSN` is set (`redis://` or `rediss://`), otherwise the cache pool. Bug fix: when a save to Redis failed, the token is now found through the fallback store.

| ID | Steps | Expected outcome |
|---|---|---|
| RED-1 | Without `SW6OIDC_REDIS_DSN`: SSO login (customer and admin) and a passkey login | Everything works through the cache pool. |
| RED-2 | Set `SW6OIDC_REDIS_DSN=redis://<host>:6379/0`, cache clear + FPM reload; start a login and run `redis-cli KEYS 'sw6oidc:*'` during the IdP redirect | A key exists. After the callback it has been consumed (deleted). |
| RED-3 | Replay: call the same callback URL (with the same `state`) a second time | Rejected ("Unknown, expired, or already-used OAuth state"). The customer ends up on the login page with an error message. |
| RED-4 | Stop Redis while a DSN is set, then log in | Login still works via the fallback, and there is a warning in the log. |
| RED-5 | Invalid DSN (for example `foo://x`) | Falls back to the cache pool with a log warning, and the shop is not affected. |

---

## 8. CLI: `sw6oidc:config:export` / `sw6oidc:config:import`

**What changed:** new commands that move provider configuration between shops as versioned JSON (`version: 1`) with attribute and role mappings. Role and customer-group references are exported as `{id, name}`. The client secret is omitted by default. Imports go through the repository, so encryption, SSRF checks and the lockout guard all apply.

| ID | Steps | Expected outcome |
|---|---|---|
| CLI-1 | `bin/console sw6oidc:config:export -o /tmp/oidc.json` | The file contains all providers with their mappings and **no** `clientSecret`. `last_test_*` and timestamps are not included. |
| CLI-2 | `… --provider-id=<id>` | Only that provider is exported. |
| CLI-3 | `… --plaintext` | The secret is in plain text, and a warning is printed to stderr. |
| CLI-4 | `… --keep-encrypted` | The secret is included as a `sw6oidc_v1:` envelope. |
| CLI-5 | `--plaintext --keep-encrypted` together | Error "mutually exclusive" and a non-zero exit code. |
| CLI-6 | `sw6oidc:config:import -i /tmp/oidc.json --dry-run` on the target shop | The output shows what would be created or skipped. **Nothing** is written to the DB. |
| CLI-7 | Import without `--overwrite` when the provider id already exists | The provider is skipped and the existing one is unchanged. |
| CLI-8 | Import with `--overwrite` | The provider is replaced, and its attribute and role mappings are **replaced**, not duplicated. |
| CLI-9 | Import a **new** confidential provider without a secret | That provider fails with an error message. Others in the same file still get imported (one transaction per provider). |
| CLI-10 | Export with `--keep-encrypted`, import into a shop with a **different** `APP_SECRET` | The import is rejected, because foreign envelopes are not accepted. |
| CLI-11 | Referenced ACL role / customer group missing on the target, but a role with the **same name** exists | Resolved by name, and the mapping points to the local role. |
| CLI-12 | Reference can't be resolved; once without and once with `--skip-unresolved` | Without: the provider fails. With: the reference or row is dropped and the rest is imported. |
| CLI-13 | `-i -` (stdin): `cat /tmp/oidc.json \| bin/console sw6oidc:config:import -i -` | Works the same as reading from a file. |
| CLI-14 | Import with an http or private URL, or with a lockout flag set but no bound account | Rejected (SSRF / lockout guard), with a readable message. |

---

## 9. Domain events around JIT provisioning (developer test)

**What changed:** five new events.
- `AttributeMappingCompletedEvent` lets listeners replace the profile; the email is validated again afterwards.
- `CustomerBeforeCreateEvent` / `AdminBeforeCreateEvent` let listeners change the payload; the id stays fixed.
- `CustomerAfterCreateEvent` / `AdminAfterCreateEvent` are read-only.

| ID | Steps | Expected outcome |
|---|---|---|
| EVT-1 | Temporary test subscriber that logs all 5 events; SSO login with a **new** customer | Order in the log: `AttributeMappingCompleted` → `CustomerBeforeCreate` → `CustomerAfterCreate`. |
| EVT-2 | Same for a new admin | `AttributeMappingCompleted` → `AdminBeforeCreate` → `AdminAfterCreate`. |
| EVT-3 | Login of an **existing** account | Only `AttributeMappingCompleted`, with no create events. |
| EVT-4 | In `CustomerBeforeCreateEvent`, change `lastName` in the payload | The customer is created with the changed last name. |
| EVT-5 | In `…BeforeCreateEvent`, try to change the `id` | The id stays unchanged. |
| EVT-6 | In `AttributeMappingCompletedEvent`, replace the profile with an invalid email | Login is refused (missing/invalid email), and no account is created. |

---

## 10. Content-Security-Policy integration

**What changed:** `Sw6OidcCspSubscriber` adds the HTTPS origins of all **active** providers to `form-action`, `connect-src`, `frame-src` and `img-src`. It only does this when the directive **already exists** and isn't `'none'`. It never adds a directive. With Shopware's stock templates it therefore changes nothing.

| ID | Steps | Expected outcome |
|---|---|---|
| CSP-1 | Stock configuration: look at the response headers of the storefront and admin | No changes to the CSP header (or no header at all). |
| CSP-2 | In `config/packages/shopware.yaml`, set a storefront CSP template with `form-action 'self';` (cache clear) | The storefront `Content-Security-Policy` header contains `form-action 'self' https://<idp-host>`. |
| CSP-3 | Template with `connect-src 'none'` | This directive stays exactly `'none'` and is not relaxed. |
| CSP-4 | Template without `frame-src` | No `frame-src` is added. |
| CSP-5 | Set a provider to inactive / delete it | Its origin disappears from the header on the next request (cache invalidation). |
| CSP-6 | SSO login with the custom CSP from CSP-2 | The redirect to the IdP is not blocked by CSP. |

---

## 11. Administration: provider detail fix and field errors

**What changed:**
- **Crash fix:** opening the provider settings failed with `TypeError: J is not a function`.
- **Field-level errors:** error messages from the SSRF and lockout guards now show on the affected fields.

| ID | Steps | Expected outcome |
|---|---|---|
| ADM-1 | Settings → OIDC providers → open an existing provider (hard reload with Ctrl/Cmd+Shift+R) | The page loads with no error in the browser console. |
| ADM-2 | "Add provider" (create a new one) | The form loads with the defaults (`openid profile email`, S256, claim encoding none). |
| ADM-3 | Reload directly on `#/sw6oidc/provider/detail/<id>` | The page loads. |
| ADM-4 | Trigger SSRF-1 / PWD-1 | The error shows in red **on the field** (not just as a toast), and it disappears once the value is corrected. |
| ADM-5 | Log in to the Administration fresh (login screen → dashboard) | No console errors; the SSO and passkey buttons behave as before. |

---

## 12. Administration: test status wording, style and live-test popup

**What changed:**
- **Provider list:** the "Test status" column uses the medium pill style from the detail page.
- **Status wording:** aligned to "Erfolgreich / Fehlgeschlagen / Warnung / Übersprungen" (EN: "Passed / Failed / Warning / Skipped").
- **Popup:** translated into the admin's UI language and styled like the detail page's result card.
- **Error steps:** the callback's error steps (invalid state, IdP error, missing code) carry message keys and are translated.

| ID | Steps | Expected outcome |
|---|---|---|
| UI-1 | Provider list with a successfully tested provider | "Teststatus" shows a **rounded** green pill "Erfolgreich", the same size and shape as on the detail page. |
| UI-2 | Provider with a failed test | Red pill "Fehlgeschlagen". Untested: plain text "Noch nicht getestet". |
| UI-3 | Detail page → "Live-Login-Test ausführen", successful login in the popup | Popup title "Ergebnisse des Live-Login-Tests", "Ergebnis: [Erfolgreich]", three steps with German messages and pills, a "Empfangene Claims" table (Claim / Wert), and a "Fenster schließen" button. The layout is card-style like the detail page. |
| UI-4 | After UI-3, look at the detail page | The result card shows the same steps and wording. "Letzter Test: <date> [Erfolgreich]" appears, and the list shows "Erfolgreich". |
| UI-5 | Switch the admin UI language to English (profile → language), repeat UI-3 | The popup is in English: "Live login test results", "Result: [Passed]", "Claims received", "Close window". |
| UI-6 | Provider without a userinfo endpoint | The step "Userinfo-Abruf: Kein Userinfo-Endpunkt konfiguriert." has a grey pill "Übersprungen". |
| UI-7 | Cancel the login at the IdP (or deny consent) | The popup shows "Autorisierung: Der Identity Provider hat die Anmeldung abgelehnt: <error>" [Fehlgeschlagen], translated on the detail page as well. |
| UI-8 | Open the test callback URL again from the browser history (state already used) | The popup shows "Rückruf: Unbekannter, abgelaufener oder bereits verwendeter Test-Durchlauf …" [Fehlgeschlagen], in the browser language (Accept-Language). |
| UI-9 | Connection test ("Verbindung testen") | The checks show pills with "Erfolgreich" / "Fehlgeschlagen" / "Warnung". |
| UI-10 | Browser console in the popup | No CSP violations; the "Close window" button works. |

---

## 13. Passkeys after the webauthn-lib 5.3 upgrade

**What changed:** the ceremony services were rewritten for the 5.x API. Credentials are loaded through their `CredentialRecord`, the host is passed into `check()`, and the updated counter is saved. This also fixes GHSA-gq4g-fpc9-vjfq.

| ID | Steps | Expected outcome |
|---|---|---|
| PK-1 | Storefront: My account → Passkeys → register (Chrome, Safari, Firefox; platform authenticator and security key) | Registration succeeds and the entry appears in the list with its nickname. |
| PK-2 | Log out → "Mit Passkey anmelden" | Logged in without entering a username. `sign_count` is updated. |
| PK-3 | Admin: profile → "My passkeys" → register; log out; admin login with passkey | Both work. |
| PK-4 | Register the same authenticator a second time | Rejected (exclude credentials); no duplicate entry. |
| PK-5 | Delete the passkey the current session was logged in with (storefront and admin) | Logged out immediately (the existing behaviour is kept). |
| PK-6 | Delete a passkey belonging to someone else (manipulate the id) | Rejected; ownership check. |
| PK-7 | Passkey on a different domain or host (rpId mismatch) | Fails with an error and no login. |
| PK-8 | Old 4.x passkey (see UPG-7) | Works. |

---

## 14. Regression smoke test (existing flows)

| ID | Steps | Expected outcome |
|---|---|---|
| REG-1 | Storefront SSO login, new customer (auto-create on) | Customer is created, bound, logged in, and redirected to the account page. |
| REG-2 | Storefront SSO login, existing customer | Login works; profile, address and group sync behave according to the toggles. |
| REG-3 | Admin SSO login, new admin with a group → ACL role mapping | Admin is created with the mapped role and lands on the dashboard. |
| REG-4 | Admin SSO login, superadmin group mapping (flag on) | `admin = true`. |
| REG-5 | Storefront logout after SSO login (IdP with `end_session_endpoint`) | Redirect to the IdP logout, then back to the shop. |
| REG-6 | Groups claim with a group literally named `"0"`, or integer group ids | The group is recognised and the mapping applies (fixed bug). |
| REG-7 | "OIDC Provider" row in the user listing, user detail and customer detail, plus "Unlink" | Shows the binding; unlinking removes it. |
| REG-8 | Delete a customer or user | The binding in `sw6oidc_user_provider` is removed too. |
| REG-9 | Auto-discovery via the well-known URL on a public HTTPS IdP | Endpoints are filled in. |
| REG-10 | Plugin log with the default log level | No secrets or tokens in plain text (masked by `SensitiveDataProcessor`). |

---

## 15. Documentation (review only)

| ID | Check | Expected outcome |
|---|---|---|
| DOC-1 | `CHANGELOG.md` → Unreleased | All the changes above are listed, and the breaking changes are called out. |
| DOC-2 | `README.md` | New sections: environment variables, command-line tools, extension points. |
| DOC-3 | `TODO.md` | Only open phases are listed. |

---

## Sign-off

| Area | Tester | Date | Result | Notes |
|---|---|---|---|---|
| 1 Upgrade/migrations | | | | |
| 2 Client secret | | | | |
| 3 SSRF | | | | |
| 4 Password login | | | | |
| 5 Transforms | | | | |
| 6 JWKS | | | | |
| 7 Redis | | | | |
| 8 CLI | | | | |
| 9 Events | | | | |
| 10 CSP | | | | |
| 11 Admin fix | | | | |
| 12 UI/Popup | | | | |
| 13 Passkeys | | | | |
| 14 Regression | | | | |

**Merge criteria:** every case in Sections 1–4, 11 and 14 passes, and there are no open Critical or High findings from the other sections.

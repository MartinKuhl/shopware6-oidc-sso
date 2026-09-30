# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Breaking changes

- **IdP URLs must be HTTPS on a public address.** Provider URLs over plain http or resolving to a private, loopback, link-local or similar address are rejected on save and on every outbound request. Set `SW6OIDC_ALLOW_INSECURE_IDP_URLS=1` for local development IdPs.
- **"Disable non-OIDC admin/customer login" is now enforced.** It used to be stored but ignored. It now really blocks password login (Storefront, Store API, Admin `password` grant). It can only be switched on once an account of that type has signed in through the provider. The break-glass override is `SW6OIDC_ALLOW_PASSWORD_LOGIN=1`.
- **The client secret is write-only in the Admin API.** It is no longer returned after saving. Leave the field empty to keep the stored secret.
- **Keep `APP_SECRET` stable.** Stored client secrets are encrypted with a key derived from it. After rotating it, every provider's secret must be entered again.
- **The per-attribute `sync_on_sso` column is removed** (in the destructive migration step). It was never read. Re-sync is controlled by the provider-level toggles.
- **`web-auth/webauthn-lib` is now `^5.3`** instead of `^4.7`. Passkeys stored by 4.x keep working.

### Added

- OIDC Back-Channel Logout (`POST /sw6oidc/backchannel-logout`): the IdP can end shop sessions server-to-server. Logout tokens are fully verified (signature, `iss`/`aud`/`exp`, `events`, no `nonce`, `jti` replay protection). Customers lose exactly the affected session; Administration users lose all their sessions (admin access tokens can't be revoked individually).
- Rate limiting for the unauthenticated endpoints (OIDC callbacks, Back-Channel Logout): 10 failed requests per minute per client address, after which the address is refused until the window ends. Successful requests never count.
- Session/subject registry: every OIDC login records which local session it created (Storefront context token / Administration access-token jti), indexed by the IdP subject, the IdP session id (`sid`) and the local account. Groundwork for Back-/Front-Channel Logout and forced logouts; no visible behavior on its own.
- Claims-based access control: per-provider rules (`eq`, `neq`, `contains`, `not_contains`, `exists`, `not_exists`) that all must pass before a login is accepted, evaluated before any account lookup or JIT provisioning. List claims are matched by entry, comparisons ignore case, and each rule carries its own denial message (shown on the Storefront and, via a one-time error ticket, on the Administration login screen). New table `sw6oidc_access_control_rule`, an **Access control** card on the provider detail page, and export/import support.
- `client_secret` is encrypted at rest (libsodium secretbox, `sw6oidc_v1:` envelope). A migration encrypts existing rows.
- Save-time SSRF validation for every fetched provider URL. A runtime `NoPrivateNetworkHttpClient` guard checks every connection and redirect.
- Enforcement of the password-login flags, with a lockout guard. The password form is hidden on the Storefront and Administration login screens while password login is disabled.
- Attribute value transforms per mapping: `concat`, `split`, `prefix`, `regex_replace`. They can be edited in the attribute mapping grid.
- Domain events around JIT provisioning: `AttributeMappingCompletedEvent`, `CustomerBeforeCreateEvent`/`CustomerAfterCreateEvent`, `AdminBeforeCreateEvent`/`AdminAfterCreateEvent`.
- CLI commands `sw6oidc:config:export` and `sw6oidc:config:import` (dry run, overwrite, reference resolution by id or name, secret omitted by default).
- A JWKS circuit breaker: a failed fetch pauses further fetches for 60 s. A token signed with an unknown key triggers one refetch, so IdP key rotation no longer locks users out until the cache TTL runs out.
- Redis-backed atomic one-time-token cache. It is selected at runtime whenever `SW6OIDC_REDIS_DSN` is set (`redis://` or `rediss://`). No DI override is needed any more.
- The IdP origins are added to directives an existing Content-Security-Policy already declares.
- Field-level error messages in the provider form for rejected URLs and the lockout guard.
- Unit tests for the OIDC core (state/PKCE/nonce, JWT verification, claims normalization), both provisioning services, group mapping, bindings, the WebAuthn ceremonies (real 5.x validators) and every new component.

### Changed

- The live login test popup is translated (German/English, following the Administration UI language) and styled like the provider detail page's result card. It reads the Administration snippet files, so both use the same wording.
- Test status labels are aligned across the plugin ("Erfolgreich"/"Fehlgeschlagen", "Passed"/"Failed"), and the provider list's "Test status" column uses the same pill style as the detail page.

### Fixed

- Opening the OIDC provider settings failed with `TypeError: J is not a function`.
- A group literally named `"0"` was dropped from a groups claim. Integer group ids are now kept.
- The flattened-claims key limit accepted one key more than intended.
- A failed Redis save made the one-time token unreachable, because the fallback store was never consulted on read.
- The JWKS response was cached before it was known to parse.
- Upgrading to webauthn-lib 5.x also fixes advisory GHSA-gq4g-fpc9-vjfq, which affects 4.9.x.

## [0.1.0]

- Initial release: multi-provider OIDC SSO for the Storefront and Administration with JIT provisioning and group/role mapping, plus Passkey (WebAuthn) login.

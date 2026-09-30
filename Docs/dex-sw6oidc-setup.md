# Setting up Dex for the Shopware OIDC plugin

[Dex](https://dexidp.io/) is a federating OIDC provider: it puts LDAP, GitHub, Google, SAML and other connectors behind one OIDC interface. This guide connects it to the Shopware OIDC & Passkey SSO plugin. Replace `https://shop.example.com` with your shop's URL and `https://dex.example.com/dex` with your Dex issuer.

The plugin's own integration tests run against Dex. `tests/Integration/dex/config.yaml` is a minimal working configuration (plain HTTP on loopback, test-only).

## 1. Register the client in Dex

In Dex's configuration file:

```yaml
issuer: https://dex.example.com/dex

oauth2:
  responseTypes: ["code"]
  skipApprovalScreen: true      # otherwise users confirm the scopes on every login

staticClients:
  - id: shopware
    name: Shopware
    secret: <a long random secret>
    redirectURIs:
      - https://shop.example.com/sw6oidc/callback                       # Storefront login
      - https://shop.example.com/api/sw6oidc/admin/callback             # Administration login
      - https://shop.example.com/api/sw6oidc/provider/test-callback     # "Run live login test" button

connectors:
  # Your upstream identity source(s): ldap, github, oidc, saml, …
```

Dex authenticates confidential clients with HTTP Basic (`client_secret_basic`), which is what the plugin uses. PKCE (S256) is supported and always used by the plugin.

## 2. Create the provider in Shopware

*Settings → Plugins → OIDC Providers → Add provider*:

| Field | Value |
|---|---|
| Client ID / Client secret | `shopware` / the secret from step 1 |
| Well-known configuration URL | `https://dex.example.com/dex/.well-known/openid-configuration`, then **Load configuration** |
| Scope | `openid profile email groups` (`groups` only if your connector provides groups) |
| PKCE flow | `S256` |
| Group attribute | `groups` |

Save, then use **Test connection** and **Run live login test**. Dex sends `sub`, `email`, `email_verified`, `name`, `preferred_username` and, with the `groups` scope and a connector that supports it, `groups`.

Dex's `sub` is an encoded combination of the connector and the upstream user id. It stays stable for a user as long as the connector id doesn't change.

## 3. Groups and access control

- **Group/Role mapping**: map Dex group names to customer groups or ACL roles. For GitHub these look like `my-org:my-team`; for LDAP they come from the connector's `groupSearch`.
- **Access control**: `email_verified` *equals* `true`, or `groups` *contains* `my-org:shop-admins`.

## 4. Logout

Dex doesn't support RP-initiated logout (there is no `end_session_endpoint`), Back-Channel Logout or Front-Channel Logout. Logging out of the shop ends only the shop session. The Dex session (and the upstream login) stays, so the next SSO login may succeed without a password prompt. Leave the provider's **End-session endpoint** empty.

## 5. Troubleshooting

- **"Unregistered redirect_uri"**: the URI must match a `redirectURIs` entry exactly.
- **No `groups` claim**: request the `groups` scope, and check that the connector returns groups (for LDAP: `groupSearch`; for GitHub: `loadAllGroups` or `orgs`).
- **Dex on a private network or plain HTTP (testing only)**: set `SW6OIDC_ALLOW_INSECURE_IDP_URLS=1`, and never in production.

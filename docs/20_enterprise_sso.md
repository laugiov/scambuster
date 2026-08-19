# Enterprise SSO (OIDC) -- optional

ScamBuster supports **optional** Single Sign-On via any OpenID Connect–compliant
identity provider (Keycloak, Microsoft Entra ID, Okta, Google Workspace, Auth0, …).

SSO is **off by default**. Password login is the default and is never disturbed --
the OIDC endpoints return `404` until you explicitly enable the feature. Turning
SSO on is purely additive: it gives operators a "Log in with SSO" path that mints
the *same* local session (JWT + refresh token) a password login produces, so
everything downstream is identical.

## How it works

Standard OIDC **Authorization Code flow with PKCE**:

1. `GET /api/v1/auth/oidc/login` → ScamBuster mints per-login secrets (`state`,
   `nonce`, PKCE `code_verifier`), stores them in a short-lived, HMAC-signed,
   HttpOnly cookie, and redirects the browser to your IdP.
2. The user authenticates at the IdP and is redirected back to
   `GET /api/v1/auth/oidc/callback`.
3. ScamBuster verifies `state`, exchanges the code at the token endpoint over a
   TLS back-channel (authenticated with the client secret + PKCE verifier),
   validates the ID token (`iss` / `aud` / `exp` / `nonce`), and independently
   confirms the access token by calling the **UserInfo** endpoint and requiring
   its `sub` to match the ID token.
4. The verified email is mapped to a local user; ScamBuster issues its normal
   session tokens (returned as JSON, or redirected to your frontend with the
   tokens in the URL fragment if `OIDC_SUCCESS_REDIRECT` is set).

### Trust model

The ID token is obtained directly from the token endpoint over a TLS back-channel
authenticated with the client secret, so per [OIDC Core §3.1.3.7](https://openid.net/specs/openid-connect-core-1_0.html#CodeIDToken)
ID-token *signature* validation may be omitted. ScamBuster still validates the
`iss`/`aud`/`exp`/`nonce` claims **and** cross-checks the access token against the
UserInfo endpoint. Adding JWKS signature verification of the ID token is a
reasonable extra hardening step and is tracked as a roadmap item.

## Enable it

Set these environment variables (see the annotated block in [`.env.dist`](../.env.dist)).
Every variable is optional; leaving `OIDC_ENABLED=false` keeps SSO disabled.

| Variable | Required when enabled | Description |
|----------|:---------------------:|-------------|
| `OIDC_ENABLED` | — | `true` to enable SSO (default `false`) |
| `OIDC_DISCOVERY_URL` | ✅ | Provider `.well-known/openid-configuration` URL |
| `OIDC_CLIENT_ID` | ✅ | Client ID registered at the IdP |
| `OIDC_CLIENT_SECRET` | ✅ | Client secret (store in `.env.local`/secret manager, never commit) |
| `OIDC_REDIRECT_URI` | ✅ | Must equal `https://<host>/api/v1/auth/oidc/callback` and be registered at the IdP |
| `OIDC_SCOPES` | — | Space-separated; default `openid email profile` |
| `OIDC_AUTO_PROVISION` | — | `true` to create a local account on first login; `false` (default) requires a pre-created account |
| `OIDC_ALLOWED_EMAIL_DOMAINS` | — | Comma-separated allow-list of email domains (empty = any) |
| `OIDC_DEFAULT_ROLES` | — | Roles for auto-provisioned users (default `ROLE_USER`) |
| `OIDC_SUCCESS_REDIRECT` | — | Frontend URL to return to with tokens in the fragment; empty = return JSON |

### Example: Keycloak

1. In your realm, create a **confidential** client (e.g. `scambuster`), enable
   *Standard flow* (Authorization Code), and set the redirect URI to
   `https://<your-host>/api/v1/auth/oidc/callback`.
2. Copy the client secret from the client's *Credentials* tab.
3. Configure ScamBuster (in `.env.local` or your secret manager):

   ```dotenv
   OIDC_ENABLED=true
   OIDC_DISCOVERY_URL=https://keycloak.example.com/realms/<realm>/.well-known/openid-configuration
   OIDC_CLIENT_ID=scambuster
   OIDC_CLIENT_SECRET=<from Keycloak credentials tab>
   OIDC_REDIRECT_URI=https://scambuster.example.com/api/v1/auth/oidc/callback
   OIDC_AUTO_PROVISION=false
   OIDC_ALLOWED_EMAIL_DOMAINS=example.com
   ```

4. Restart the backend so the new environment is loaded, then browse to
   `/api/v1/auth/oidc/login`.

Any other OIDC provider works the same way -- only the discovery URL and client
registration differ.

## Security notes

- **HTTPS is required.** The state cookie is `Secure` + `HttpOnly` + `SameSite=Lax`
  and short-lived (10 min). Run behind TLS.
- **Least privilege.** Auto-provisioning is off by default -- unknown SSO identities
  are refused until an admin creates the local account. Turn it on only with an
  `OIDC_ALLOWED_EMAIL_DOMAINS` allow-list.
- **Secrets.** `OIDC_CLIENT_SECRET` is a secret: keep it in `.env.local` or your
  secret manager, never in committed config.
- **CSRF / replay.** `state` (CSRF) and `nonce` (replay) are validated on every
  callback; the state cookie is HMAC-signed with the app secret and expires quickly.
- **Auditing.** Successful and failed SSO logins are written to the audit log
  (`AUTH_SUCCESS` / `AUTH_FAILURE`, `action=oidc_login`).

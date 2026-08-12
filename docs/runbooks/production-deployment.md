# Production deployment

This runbook stands up a **real** ScamBuster instance from the production compose
file — a self-contained application image (nginx + php-fpm, serving the API **and**
the built React frontend on one port) plus PostgreSQL, Redis, n8n, and a scheduler.

It is a separate, standalone path from the developer quickstart:

| | Developer quickstart (`make quickstart`) | Production (`docker-compose.prod.yml`) |
|---|---|---|
| App server | `php -S` (dev), Vite dev server (`:3002`) | nginx + php-fpm via supervisord, one port |
| Frontend | separate dev server | built once, served same-origin by nginx |
| Data | loads a **demo dataset** | **no** demo data — your data only |
| Reference/lookup data | fixtures | migrations + an idempotent SQL seed |
| Secrets | bind-mounted `.env` | real environment variables, no secrets in the image |
| `APP_ENV` | `dev` | `prod` (OPcache on, debug off) |
| Migrations | run by `make quickstart` | run **automatically** on container boot |

> It was validated end to end on one environment (Linux, Docker Compose v2, x86):
> clean build from an empty database, migrations, reference seed, login, email
> ingestion + LLM classification + IOC extraction, scheduler, and DB backup. As with
> any single-environment test, treat the Troubleshooting section as the known-gotcha
> list, not a proof it works everywhere.

---

## Prerequisites

- **Docker** + **Docker Compose v2** (`docker compose version`).
- **git**, **openssl**.
- ~**4 GB free disk** and ~**4 GB RAM**. The first build compiles PHP extensions,
  builds the frontend, and pulls the n8n image — allow 5–15 min on a cold cache.
- A **honeypot mailbox** (IMAP host + address + app password) and an **SMTP DSN**
  for sending replies.
- An **LLM API key** (OpenAI by default), or `LLM_PROVIDER=mock` to run without one.
- A host you control, ideally behind a **TLS-terminating reverse proxy**
  (Caddy / nginx / Traefik). The app listens on plain HTTP inside the network;
  terminate TLS in front of it.

---

## 1. Clone and create the environment file

```bash
git clone <repo-url> scambuster && cd scambuster
cp .env.dist .env
```

Everything the stack needs is read from `.env`. It is git-ignored and is **never**
baked into the image (a `.dockerignore` keeps `.env` and the JWT keys out of the
build context); the container writes its runtime `.env` from the environment at boot.

## 2. Generate the crypto secrets

Write them straight into `.env` — do not echo the values:

```bash
sed -i "s|^APP_SECRET=.*|APP_SECRET=$(openssl rand -hex 16)|"            .env
sed -i "s|^JWT_PASSPHRASE=.*|JWT_PASSPHRASE=$(openssl rand -hex 16)|"    .env
sed -i "s|^TOTP_ENCRYPTION_KEY=.*|TOTP_ENCRYPTION_KEY=$(openssl rand -hex 32)|" .env
sed -i "s|^AUDIT_HMAC_KEY=.*|AUDIT_HMAC_KEY=$(openssl rand -hex 32)|"    .env
sed -i "s|^N8N_ENCRYPTION_KEY=.*|N8N_ENCRYPTION_KEY=$(openssl rand -hex 16)|"   .env
sed -i "s|^POSTGRES_PASSWORD=.*|POSTGRES_PASSWORD=$(openssl rand -hex 16)|"     .env
```

The container **fails fast** at boot if `APP_SECRET`, `TOTP_ENCRYPTION_KEY`,
`AUDIT_HMAC_KEY`, or `JWT_PASSPHRASE` are missing — it never boots half-configured.
`TOTP_ENCRYPTION_KEY` and `AUDIT_HMAC_KEY` **must** be 64 hex chars (32 bytes) each.

> The JWT signing keypair (RS256) is **generated inside the container on first boot**
> from `JWT_PASSPHRASE` and stored on a volume; you do not create `.pem` files by hand.

## 3. Fill in the operator's secrets

Edit `.env` and set — **in the file, not on a command line**:

- `HONEYPOT_IMAP_HOST` / `HONEYPOT_IMAP_PORT` (Gmail: `imap.gmail.com` / `993`)
- `HONEYPOT_IMAP_USER` — the honeypot mailbox address
- `HONEYPOT_IMAP_PASSWORD` — its IMAP app password
- `MAILER_DSN` — e.g. `smtps://user:app-password@smtp.gmail.com:465`
- `LLM_API_KEY` — the OpenAI key (leave `LLM_PROVIDER=openai`, `LLM_MODEL=gpt-4o-mini`)
- `INGEST_LOGIN` / `INGEST_PASSWORD` — credentials n8n uses to post to the API
- `CORS_ALLOW_ORIGIN` — the public origin you will serve from
  (e.g. `https://scambuster.example.com`)
- `N8N_DEFAULT_USER_EMAIL` / `N8N_DEFAULT_USER_PASSWORD` — the n8n admin login

Optional ports (default shown): `APP_PORT=8080`, `N8N_HTTP_PORT=5678`.

## 4. Build and start

```bash
docker compose -f docker-compose.prod.yml up -d --build
```

On first boot the app container automatically, in order:

1. verifies the required secrets are present,
2. waits for PostgreSQL,
3. generates the RS256 JWT keypair (once),
4. runs all database migrations,
5. seeds reference/lookup data — channels, directions, the 14 scam types, the
   scam-type→persona links, and a default admin — with an **idempotent, insert-only**
   seed (safe to re-run; it never updates or deletes),
6. registers the honeypot mailbox from `.env`,
7. starts nginx + php-fpm.

Watch it come up:

```bash
docker compose -f docker-compose.prod.yml logs -f app
# look for: "reference data ready: 14 scam types ..." then "supervisord started"
```

## 5. Verify

```bash
# health (also what the container healthcheck uses)
curl -s http://localhost:8080/healthz            # -> {"status":"ok"}

# login with the seeded admin
curl -s -X POST http://localhost:8080/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"user@example.com","password":"Un1que$trongPassword2024"}'
```

- App (dashboard + API, same origin): <http://localhost:8080>
- API docs: <http://localhost:8080/api/doc>
- n8n: <http://localhost:5678>

## 6. Change the default admin password — IMMEDIATELY

The seed creates `user@example.com` with a **public, documented** password
(`Un1que$trongPassword2024`) so you can log in the first time. **Change it before the
instance is reachable by anyone else**, with the built-in CLI (interactive, hidden
prompt — the password is never shown or stored in shell history):

```bash
docker compose -f docker-compose.prod.yml exec app bin/console app:user:set-password user@example.com
```

Or create your own admin and stop using the default one:

```bash
docker compose -f docker-compose.prod.yml exec app \
  bin/console app:user:create --email=you@example.com --admin --generate
```

Then confirm you can log in with the new credentials and that the old ones are rejected.
See [User management](#user-management) for the full command set.

## 7. Put TLS in front

The app speaks plain HTTP on `:8080`. Terminate TLS with a reverse proxy and forward
to it. Keep n8n (`:5678`) firewalled or behind the proxy with its own auth — do not
expose it publicly. Minimal Caddy example:

```
scambuster.example.com {
    reverse_proxy 127.0.0.1:8080
}
```

---

## 8. Network zoning and egress

The production compose splits the stack into two Docker networks:

| Network | `internal` | Members | Purpose |
|---------|-----------|---------|---------|
| `data`  | **yes**   | postgres, redis, app, scheduler | Data plane. No route to the internet; not reachable from n8n. |
| `edge`  | no        | app, scheduler, n8n | Engagement/egress plane. Outbound access + n8n ↔ app. |

The engagement component (**n8n**, which executes arbitrary workflow JS and holds
the mailbox credentials) sits on `edge` only, so a compromised n8n **cannot reach
PostgreSQL or Redis directly**. The app and scheduler are dual-homed: they reach
the data store over `data` and keep the outbound access they need over `edge`.

**Legitimate egress** (from `app` / `scheduler` on `edge`) — build your host
firewall / egress allowlist from this list:

| Destination | Purpose | When |
|-------------|---------|------|
| LLM API (`LLM_API_URL`, e.g. `api.openai.com`) | Reply generation, guards, enrichment | Per reply/enrichment (skip entirely with `LLM_PROVIDER=ollama` on the same host) |
| SMTP host (`MAILER_DSN`) | Sending replies | Per reply sent |
| IMAP host (`HONEYPOT_IMAP_*`, via n8n) | Mailbox polling | Continuous |
| Enrichment providers (VirusTotal / urlscan, if configured) | IOC scoring | Per IOC |

Nothing else needs egress. For a fully self-contained deployment, run a local LLM
(`LLM_PROVIDER=ollama`) and no external enrichment — then only SMTP/IMAP leave the
host, and `data` stays internal as shipped.

---

## Day-2 operations

### User management

All commands run inside the `app` container and hash passwords with the
application's configured hasher (never plaintext). Prefer `--generate` (prints a
strong random password once) or the interactive hidden prompt over `--password`,
which is visible in shell history and the process list. Every create / password-reset
/ role change is written to the tamper-evident `audit_log` (event types
`USER_CREATED`, `USER_PASSWORD_RESET`, `USER_ROLE_CHANGED`).

```bash
C="docker compose -f docker-compose.prod.yml exec app bin/console"

$C app:user:create --email=analyst@example.com            # prompts (hidden) for a password
$C app:user:create --email=admin@example.com --admin --generate
$C app:user:set-password analyst@example.com --generate   # rotate a password
$C app:user:promote analyst@example.com                   # grant ROLE_ADMIN
$C app:user:promote analyst@example.com --demote          # revoke ROLE_ADMIN
$C app:user:list                                          # email / roles / permission count
```

Emails are normalized (trimmed + lowercased) so accounts resolve regardless of the
casing typed, and case-variant duplicates cannot be created.

### Updating to a new version
```bash
git pull
docker compose -f docker-compose.prod.yml up -d --build
```
Migrations run automatically on boot; the reference seed is idempotent, so an
update never duplicates or clobbers data.

### Backups
The `scheduler` container runs a `pg_dump` **daily at ~02:00 UTC** into the
`prod-backups` volume (7-day retention). Take an on-demand backup:

```bash
docker compose -f docker-compose.prod.yml exec -T postgres \
  sh -c 'pg_dump -U scambuster scambuster | gzip' > scambuster-$(date +%F).sql.gz
```

Restore into a fresh database:
```bash
gunzip -c scambuster-YYYY-MM-DD.sql.gz | \
  docker compose -f docker-compose.prod.yml exec -T postgres psql -U scambuster -d scambuster
```

### Scheduled intelligence tasks
The `scheduler` container also runs clustering backfill, actor/psychological
profiling, embeddings, IOC context enrichment, prompt-injection detection, budget
checks, and stale-conversation closure on a ~30-minute loop. Watch it with:
```bash
docker compose -f docker-compose.prod.yml logs -f scheduler
```

---

## Troubleshooting

- **`FATAL: APP_SECRET too short` / `TOTP_ENCRYPTION_KEY and AUDIT_HMAC_KEY are
  required`.** The fail-fast checks fired. Set the missing secret in `.env`
  (`TOTP_ENCRYPTION_KEY`/`AUDIT_HMAC_KEY` must be 64 hex chars) and recreate the app
  container.
- **Migration fails with "requires TOTP_ENCRYPTION_KEY".** The key is missing or not
  64 hex chars. It must be exactly `openssl rand -hex 32`.
- **Login returns 401 right after install.** Confirm you are using the seeded
  `user@example.com` credentials, and that boot logs show
  `reference data ready` and the default-admin security banner.
- **Inbound email never becomes a conversation.** Check that the honeypot mailbox
  values in `.env` are real (not placeholders), that n8n imported and activated the
  intake workflow (`docker compose -f docker-compose.prod.yml logs n8n`), and that
  the IMAP app password is valid.
- **Daily backup file is tiny / empty.** Fixed in this compose: the backup strips the
  Doctrine `?serverVersion=...` query string that `pg_dump` rejects, and rejects a
  backup smaller than a real dump. If you customized `DATABASE_URL`, keep the
  credentials valid.

---

## Follow-ups (known gaps)

- **Base image CVEs.** CI scans both the dev and production images with Trivy on every
  push and publishes an SBOM for each. Rebuild periodically with `--pull`
  (`docker compose -f docker-compose.prod.yml build --pull`) to pick up patched
  `php:8.3-fpm` / `node:20-alpine` bases.
- **No web UI for user management.** Accounts are managed with the `app:user:*` CLI
  (see [User management](#user-management)); there is no self-service password-change
  screen in the dashboard yet.

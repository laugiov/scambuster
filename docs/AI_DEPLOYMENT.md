# Deploying ScamBuster with an AI agent

This runbook lets an AI coding agent (Claude Code, Cursor, Copilot, …) — or a
human — install and run ScamBuster end to end. It was written against a real,
verified deployment. Follow it top to bottom.

> **Single source of truth**: the exact commands live in [`docs/QUICKSTART.md`](QUICKSTART.md).
> This file adds the ordering, the secret handling, and the guardrails an agent needs.

---

## Step 0 — Ask the operator for their details FIRST (before anything else)

**The `.env` must be fully filled in BEFORE you run `make quickstart`.** The install
configures the honeypot mailbox and the n8n IMAP credential *from `.env` during the
install itself* — so if the mailbox / SMTP / LLM values are still placeholders when
you run `make quickstart`, live email capture and replies will silently not work,
even though the dashboard looks alive (it shows demo data).

So, as the very first action — **before cloning** — an AI agent must ask the operator
to gather and provide these (some take a few minutes to obtain):

- **Honeypot mailbox**: IMAP host + address + an **app password**
  (Gmail: enable 2FA, then create an App Password — this can take a few minutes).
- **SMTP DSN** for sending replies (e.g. `smtps://user:app-password@smtp.gmail.com:465`).
- **LLM API key** (OpenAI by default), or the decision to run demo-only with
  `LLM_PROVIDER=mock`.

Do not start the clone/install until you either have these values or the operator has
explicitly chosen demo-only mode.

---

## Guardrails (read first — do not skip)

1. **`make quickstart` runs `docker compose down -v`, which DELETES the project's
   volumes.** Only run it on a **fresh/empty** environment. Before running it,
   confirm there is no existing ScamBuster data you care about
   (`docker volume ls | grep scambuster`). Never run it against a running instance.
   The preflight now lists any existing volumes and requires a typed `yes`; an agent
   running non-interactively gets an abort instead, and **must ask the operator**
   before re-running with `FORCE=1`. Back up first if there is any doubt:
   `docker compose exec -T postgres pg_dump -U postgres -d scambuster > backup.sql`.
2. **Secrets never go into the chat, the terminal echo, or git.** Generate keys with
   `openssl`, write them straight into `.env` (which is git-ignored), and let the
   human paste API keys / mailbox passwords into the file themselves. Do not print
   secret values back.
3. **Do not commit `.env`.** It is git-ignored by design; keep it that way.
4. This runbook was validated on **one environment** (Linux, Docker 28, x86). It is
   not proof it works everywhere — see Troubleshooting for the known gotchas.

---

## Prerequisites

- **Docker** + **Docker Compose v2** (`docker compose version`).
- **git**, **openssl**, **curl**.
- ~**4 GB free disk** and ~**4 GB free RAM** for the stack (first build compiles PHP
  extensions and pulls the n8n image — allow 5–15 min on a cold cache).
- A **mailbox** to use as the honeypot: IMAP host + address + an **app password**
  (for Gmail: enable 2FA, then create an App Password), and an **SMTP DSN** for
  sending replies.
- An **LLM API key** (OpenAI by default). The stack also runs against a mock
  provider with no key (`LLM_PROVIDER=mock`) if you only want to see it boot.

---

## Steps

### 1. Clone and enter the repo
```bash
git clone <repo-url> scambuster && cd scambuster
```

### 2. Create the environment file
```bash
cp .env.dist .env
```

### 3. Generate the crypto keys (agent does this — no human secret needed)
Write them straight into `.env`; do not echo the values:
```bash
sed -i "s|^APP_SECRET=.*|APP_SECRET=$(openssl rand -hex 16)|" .env
sed -i "s|^JWT_PASSPHRASE=.*|JWT_PASSPHRASE=$(openssl rand -hex 16)|" .env
sed -i "s|^TOTP_ENCRYPTION_KEY=.*|TOTP_ENCRYPTION_KEY=$(openssl rand -hex 32)|" .env
sed -i "s|^AUDIT_HMAC_KEY=.*|AUDIT_HMAC_KEY=$(openssl rand -hex 32)|" .env
```

### 4. Fill in the operator's secrets (human edits the file)
Ask the human to edit `.env` and set these — **in the file, not in the chat**:
- `HONEYPOT_IMAP_HOST` / `HONEYPOT_IMAP_PORT` (Gmail: `imap.gmail.com` / `993`)
- `HONEYPOT_IMAP_USER` — the honeypot mailbox address
- `HONEYPOT_IMAP_PASSWORD` — its IMAP app password
- `MAILER_DSN` — e.g. `smtps://user:app-password@smtp.gmail.com:465`
- `LLM_API_KEY` — the OpenAI key (leave `LLM_PROVIDER=openai`, `LLM_MODEL=gpt-4o-mini`)

### 5. Install (one command)
```bash
make quickstart
```
It starts with a preflight (host ports, existing volumes) that runs **before** the
destructive `down -v`, then builds the images, starts the stack, runs migrations +
fixtures, generates JWT keys, loads a demo dataset, **auto-configures n8n** (admin
account, workflow import + activation, and the IMAP credential — all derived from
`.env`), and finishes by running `make doctor`. No manual n8n UI step is needed for
the default mailbox.

If the final `doctor` reports failures, the stack is up but **not usable for live
mail** — fix `.env`, reload it with
`docker compose up -d --force-recreate backend-dev scheduler`, and re-run `make doctor`.
Do not report the deployment as working until doctor passes.

### 6. Verify it is up
```bash
make doctor
curl -s -o /dev/null -w "login %{http_code}\n" -X POST http://localhost:8081/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"user@example.com","password":"Un1que$trongPassword2024"}'
```
- Dashboard: <http://localhost:3002>
- Backend/API docs: <http://localhost:8081/api/doc>
- n8n: <http://localhost:5678>

### 7. See the pipeline work
1. Send a test email to the honeypot address (or wait for real inbound).
2. Within a minute or two, a **conversation appears** in the dashboard, with
   extracted IOCs and a scam classification.
3. A persona **reply is generated** automatically. To send it immediately instead of
   waiting for the scheduler, call the API:
   ```bash
   # msgId = the outbound (draft) message id for the conversation
   curl -X POST "http://localhost:8081/api/v1/communication/reply/$msgId/send-email" \
     -H "Authorization: Bearer $TOKEN"
   # -> {"success":true,"message_id":"...","ts_sent":"..."}
   ```
4. **Scheduled tasks** (clustering, actor/psych profiles, daily DB backup, …) run
   automatically via the `scheduler` container.

---

## Troubleshooting (observed gotchas)

- **Checking backend health.** The Docker healthcheck curls the public `/healthz`
  endpoint, which returns 200, so a healthy container reports `healthy`. If it does
  not, verify with the login `curl` above and check the container logs.
- **The stack builds but nothing is reachable on `localhost` (HTTP 000).** Docker's
  default `172.x` network pool can be exhausted on a machine with many leftover
  networks; new networks then fall back to a `192.168.x` range that may not route.
  Fix: `docker network prune` (removes only unused networks), then
  `docker compose down && docker compose up -d`.
- **Running a second instance on the same host fails with a container-name conflict.**
  `docker-compose.yml` pins fixed `container_name`s, so two instances collide. For a
  normal single install this never happens; to co-locate two, override the
  `container_name`s in a `docker-compose.override.yml`.
- **`make quickstart` aborts with `Bind for 0.0.0.0:<port> failed: port is already
  allocated`.** The default stack publishes **8081** (backend), **3002** (frontend)
  and **5678** (n8n) — **8082** (backend-preprod) and **5433** (postgres-preprod)
  only with the `preprod` Compose profile active. An unrelated container on the host
  holding any of them stops the install. `make quickstart` checks the published
  ports up front — before its destructive `down -v` — and names the
  offending container. Fix by stopping that container, or remap the port in
  `docker-compose.override.yml`:

  ```yaml
  services:
    postgres-preprod:
      ports: !override      # without !override Compose APPENDS, keeping the old port
        - "5434:5432"
  ```

  The `!override` tag is required: Compose merges list-valued keys by appending, so
  a plain `ports:` entry leaves the conflicting mapping in place and the bind fails
  again.
- **`app:audit:verify-chain` reports mismatches right after install.** The seeded demo
  rows were signed with a different `AUDIT_HMAC_KEY` than the one you just generated;
  this is expected on demo data, not tampering. Your own captured data verifies fine.
- **Replies don't send automatically.** Generation and sending are separate: the
  reply is drafted immediately, then the scheduled `WF-REPLY-SEND` workflow emails it.
  Use the `send-email` API call above to send on demand.

---

## Production deployment (self-contained image)

Everything above uses the **developer** stack (`make quickstart`: `php -S`, a Vite
dev server, and a demo dataset). For a **real** deployment, use the production compose
file instead — one self-contained app image (nginx + php-fpm serving the API and the
built frontend on a single port) plus PostgreSQL, Redis, n8n, and a scheduler.

> **Single source of truth for prod:** [`docs/runbooks/production-deployment.md`](runbooks/production-deployment.md).
> Follow it top to bottom. This section adds only the ordering and guardrails an agent
> needs.

### What differs from the quickstart (an agent must internalize this)

- **Command:** `docker compose -f docker-compose.prod.yml up -d --build` — **not**
  `make quickstart`.
- **It does NOT run `docker compose down -v`.** Unlike quickstart, the prod path never
  deletes volumes. Still: never run `down -v` against a prod stack you care about.
- **Migrations run automatically on container boot**, followed by an idempotent,
  **insert-only** reference seed (channels, directions, 14 scam types, persona links,
  default admin). Re-running is safe — it never updates or deletes rows.
- **No demo data.** The instance starts empty except for reference/lookup data.
- **Secrets are real environment variables**, provided via `.env`, and are **not**
  baked into the image (a root `.dockerignore` excludes `.env` and the JWT keys from
  the build context). The JWT RS256 keypair is generated inside the
  container on first boot from `JWT_PASSPHRASE`.

### Agent guardrails specific to prod

1. **Generate all crypto secrets** (`APP_SECRET`, `JWT_PASSPHRASE`,
   `TOTP_ENCRYPTION_KEY`, `AUDIT_HMAC_KEY`, `N8N_ENCRYPTION_KEY`,
   `POSTGRES_PASSWORD`) with `openssl`, write them straight into `.env`, never echo
   them. `TOTP_ENCRYPTION_KEY` and `AUDIT_HMAC_KEY` **must** be `openssl rand -hex 32`
   (64 hex chars) — the container fails fast otherwise.
2. **Use `JWT_PASSPHRASE`, not `JWT_SECRET`.** Prod signs JWTs with RS256; the old
   `JWT_SECRET` HS256 value is not used.
3. **The human provides** the honeypot mailbox, `MAILER_DSN`, and `LLM_API_KEY` — in
   the file, not the chat.
4. **Change the seeded admin password immediately.** The seed creates
   `user@example.com` with a public default password so the first login works; rotate
   it before the instance is reachable with
   `bin/console app:user:set-password user@example.com` (or create your own admin with
   `app:user:create --email=... --admin --generate` — see runbook §6 and User
   management). These commands hash via the app hasher and audit-log the change.
5. **TLS is the operator's job:** the app is plain HTTP on `:8080`; a reverse proxy
   terminates TLS in front of it, and n8n (`:5678`) stays firewalled.

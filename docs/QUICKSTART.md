# ScamBuster -- Quickstart Guide

Get ScamBuster running in under 5 minutes.

## Prerequisites

- **Docker** and **Docker Compose** v2+ installed
- An **email account** for the honeypot (Gmail, Outlook, Yahoo, or any IMAP provider)
- An **OpenAI API key** (get one at https://platform.openai.com/api-keys)
- For Gmail: an **App Password** (not your regular password)

### Gmail App Password (2 minutes)

1. Go to https://myaccount.google.com/security
2. Enable **2-Step Verification** if not already done
3. Go to https://myaccount.google.com/apppasswords
4. Create an App Password named "ScamBuster"
5. Copy the 16-character code (e.g., `abcd efgh ijkl mnop` -- remove spaces)

## Installation

### 1. Clone the repository

```bash
git clone https://github.com/laugiov/scambuster.git
cd scambuster
```

### 2. Configure your environment

```bash
cp .env.dist .env
```

Open `.env` in your editor and fill in these **4 values now -- before you run
`make quickstart`**. The install registers your honeypot mailbox and the n8n IMAP
credential *from `.env` during setup*, so filling them in afterwards means live email
capture won't work until you re-run. (If you leave the placeholders, `make quickstart`
warns you and boots in demo mode.)

```env
# 1. Your OpenAI API key (https://platform.openai.com/api-keys)
LLM_API_KEY=sk-proj-your-key-here

# 2. Your honeypot email (IMAP — for receiving scam emails)
HONEYPOT_IMAP_USER=your-honeypot@gmail.com

# 3. Your email App Password (NOT your regular password)
HONEYPOT_IMAP_PASSWORD=your-16-char-app-password

# 4. Your SMTP sending config (same email, same App Password)
#    Note: @ in email must be written as %40
MAILER_DSN=smtps://your-honeypot%40gmail.com:your-app-password@smtp.gmail.com:465
```

**Example** (Gmail with App Password `abcdefghijklmnop`):
```env
LLM_API_KEY=sk-proj-abc123...
HONEYPOT_IMAP_USER=my-honeypot@gmail.com
HONEYPOT_IMAP_PASSWORD=abcdefghijklmnop
MAILER_DSN=smtps://my-honeypot%40gmail.com:abcdefghijklmnop@smtp.gmail.com:465
```

**Provider-specific IMAP settings** (if not using Gmail):

| Provider | IMAP Host | SMTP DSN |
|----------|-----------|----------|
| Gmail | `imap.gmail.com` (default) | `smtps://user%40gmail.com:apppass@smtp.gmail.com:465` |
| Outlook | `outlook.office365.com` | `smtp://user%40outlook.com:pass@smtp.office365.com:587` |
| Yahoo | `imap.mail.yahoo.com` | `smtps://user%40yahoo.com:apppass@smtp.mail.yahoo.com:465` |

For Outlook/Yahoo, also update `HONEYPOT_IMAP_HOST` in `.env` (default is `imap.gmail.com`).

Everything else works out of the box with sensible defaults.

> **No OpenAI key?** Set `LLM_PROVIDER=mock` in `.env` to run without an API key (replies will be synthetic). You can load sample data with `make demo-load` after setup.

**LLM providers** (switch with one env var):

| Provider | `LLM_PROVIDER=` | Data Location | Best For |
|----------|-----------------|---------------|----------|
| **OpenAI** | `openai` | Cloud | Best quality (GPT-4o) |
| **Anthropic** | `anthropic` | Cloud | Alternative (Claude) |
| **Ollama** | `ollama` | **100% local** | Sovereign deployment |
| **Mock** | `mock` | Local | Demo (no API key, no cost) |

### 3. Launch ScamBuster

**Two ways to deploy** -- do it **by hand** with this guide, or hand it to an **AI agent**
(Claude Code, Cursor, Copilot, …) with [AI_DEPLOYMENT.md](AI_DEPLOYMENT.md), a
tool-agnostic runbook with the secret-handling and guardrails an agent needs.

```bash
make quickstart
```

This single command:
- Runs a preflight check (host ports free, existing volumes) **before** touching anything
- Builds and starts all Docker containers (backend, frontend, database, Redis, n8n)
- Installs backend dependencies
- Creates and migrates the database
- Loads fixture data (users, personas, scam types)
- Loads demo dataset (36 conversations, 482 IOCs) and seeds the demo TTP
  observations, so the TTP Explorer and review queue are populated too
- Generates JWT authentication keys
- Creates the n8n admin account automatically
- Imports and configures all n8n workflows
- Sets up IMAP email credentials
- Activates the email polling workflow
- Runs `make doctor` at the end and tells you if anything is actually broken

> **It deletes existing data.** Step 1 is `docker compose down -v`, which drops the
> project's volumes. If ScamBuster volumes already exist, the preflight lists them and
> asks you to type `yes`. Non-interactive runs abort instead -- pass `FORCE=1 make
> quickstart` to accept the loss, after backing up if needed:
> `docker compose exec -T postgres pg_dump -U postgres -d scambuster > backup.sql`

**Expected output** (last lines):

```
Step 6/6: Configuring n8n (admin account, workflows, credentials)...
  [n8n-init] Admin account created.
  [n8n-init] Authenticated successfully.
  [n8n-init] IMAP credential created.
  [n8n-init] Workflow import done: 4 imported, 0 skipped.
  [n8n-init] ═══ Init complete ═══

╔══════════════════════════════════════════════╗
║       ScamBuster is ready!                    ║
╚══════════════════════════════════════════════╝

Interfaces:
  Dashboard:  http://localhost:3002
  Backend:    http://localhost:8081/api/doc
  n8n:        http://localhost:5678
    Login:    admin@scambuster.local / (see .env)

Verifying the installation...

CREDENTIALS (live)
  ✅ IMAP — my-honeypot@gmail.com on imap.gmail.com:993 (0 unread)
  ✅ SMTP — authenticated on smtp.gmail.com:465
  ✅ LLM — openai key valid; model 'gpt-4o-mini' available

[ScamBuster Doctor] All required checks passed. System ready.

Send a test email to your honeypot address to start!
```

**Typical duration**: ~90 seconds (first run with Docker image cache).

### 4. Verify the installation

`make quickstart` already runs this at the end; re-run it any time with:

```bash
make doctor
```

All required checks should show ✅. Warnings (⚠️) on `N8N_ENCRYPTION_KEY` and `INGEST_LOGIN` are expected in dev -- change them for production.

The **CREDENTIALS (live)** section is the one that matters for real use: it logs into
your mailbox over IMAP, authenticates against your SMTP relay, and calls your LLM
provider. The other checks only prove a value is *present* in `.env` -- an expired API
key or a revoked app password looks perfectly valid to them, installs without a
warning, and only shows up later as mail that is captured but never classified or
answered. Add `DOCTOR_SKIP_LIVE=1` to skip these when running offline.

### 5. Test the pipeline

Send an email to your honeypot address from any other email account. Within 1-2 minutes:

1. The IMAP trigger captures the email
2. ScamBuster classifies it and extracts IOCs
3. An LLM generates a contextual reply
4. The reply is sent via SMTP with proper email threading

Check the results:
- **Dashboard**: http://localhost:3002 -- see conversations, IOCs, analytics
- **n8n**: http://localhost:5678 -- see workflow executions

## Interfaces

| Interface | URL | Purpose |
|-----------|-----|---------|
| **Dashboard** | http://localhost:3002 | Operations dashboard, conversations, IOC explorer, analytics |
| **n8n** | http://localhost:5678 | Workflow orchestrator (login: `admin@scambuster.local` / see `.env`) |
| **API docs** | http://localhost:8081/api/doc | Swagger/OpenAPI documentation |

## Default credentials

| Service | Login | Password | Role |
|---------|-------|----------|------|
| **n8n** | `admin@scambuster.local` | `Scambuster2026!` | n8n owner |
| **Backend API** | `user@example.com` | `Un1que$trongPassword2024` | `ROLE_USER` |
| **Backend API** | `admin@example.com` | `Un1que$trongPassword2024` | `ROLE_ADMIN` |
| **PostgreSQL** | `postgres` | `postgres` | — |

The two backend accounts are created by the fixtures `make quickstart` loads.

⚠️ **Change all default passwords before exposing ScamBuster to the internet.**

## Useful commands

```bash
make doctor          # Check environment and connectivity
make quickstart      # Full first-time setup (destructive — resets DB)
make upd             # Start containers in background
make down            # Stop containers
make test            # Run backend tests
make front-check     # Run frontend checks (lint, typecheck, build)
make validate-n8n    # Validate n8n workflow JSON files
```

## Troubleshooting

### Preflight says a port is already taken

The default stack publishes **8081** (backend), **3002** (frontend) and **5678**
(n8n). (**8082**/**5433** only appear if you activate the `preprod` Compose
profile.) The preflight names the container holding the port. Either stop it, or remap the port in
`docker-compose.override.yml`:

```yaml
services:
  postgres-preprod:
    ports: !override      # without !override Compose APPENDS, keeping the old port
      - "5434:5432"
```

The `!override` tag is not optional: Compose merges list-valued keys by appending, so
a plain `ports:` entry keeps the conflicting mapping and the bind fails again.

### `make quickstart` fails at "composer install"

The `vendor/` directory may have wrong permissions. Run:
```bash
chmod -R 777 backend-symfony/vendor backend-symfony/var
```

### My code change has no effect in the container

Two different causes, and they need different fixes.

**Application source** is bind-mounted (`./backend-symfony:/app`), so edits under
`src/` are live -- no rebuild, at most a container restart.

**Anything baked into the image** is not: `composer.lock`, the Dockerfile, PHP
extensions. The backend services share one image tag, `scambuster-backend:ci`,
and once it exists on your machine `docker compose up` reuses it rather than
rebuilding. Force it:

```bash
docker compose up -d --build backend-dev
```

The tag is shared by `backend-dev`, `backend-test`, `backend-e2e`,
`backend-preprod`, `scheduler` and `canary-worker`, so a stale image affects all
six at once -- including the containers your tests run in.

### n8n shows "Workflow does not exist" error

The workflow IDs were not injected properly. Restart n8n:
```bash
docker compose up -d --force-recreate n8n
```

### OpenAI API returns 401

Your API key is invalid or the backend hasn't reloaded it:
```bash
docker compose up -d --force-recreate backend-dev
```

### IMAP connection fails

- Verify your App Password (not your regular password)
- Gmail App Passwords are 16 lowercase characters with no spaces
- Make sure 2-Step Verification is enabled on your Google account
- Check `HONEYPOT_IMAP_HOST` matches your provider

### Emails are not being captured

- Check n8n workflow executions: http://localhost:5678
- Verify WF-INTAKE-EMAIL-V2 is Active (green toggle)
- Check `make doctor` for connectivity issues

## Production deployment

`make quickstart` is the local/developer path (dev server, demo data). For a real
deployment use the self-contained production image and compose file --
**[Production deployment runbook](runbooks/production-deployment.md)**
(`docker compose -f docker-compose.prod.yml up -d --build`: nginx + php-fpm, no demo
data, migrations auto-run). An agent can follow the Production section of
[AI_DEPLOYMENT.md](AI_DEPLOYMENT.md).

Before exposing anything, change `POSTGRES_PASSWORD` (and `DATABASE_URL` to match) and
`JWT_PASSPHRASE` (`openssl rand -hex 32`). In full, change these values in `.env`:

```env
# Generate unique values
APP_SECRET=$(php -r "echo bin2hex(random_bytes(16));")
JWT_PASSPHRASE=$(openssl rand -hex 32)
N8N_ENCRYPTION_KEY=$(openssl rand -hex 32)
LOGIN_HASH_SALT=$(openssl rand -hex 16)
POSTGRES_PASSWORD=a-strong-unique-password

# Update DATABASE_URL to match
DATABASE_URL="postgresql://postgres:a-strong-unique-password@postgres:5432/scambuster"

# Set proper n8n admin credentials
N8N_DEFAULT_USER_EMAIL=your-email@company.com
N8N_DEFAULT_USER_PASSWORD=a-strong-password
```

See [docs/17_email_provider_setup.md](17_email_provider_setup.md) for detailed email provider configuration.

# ScamBuster — Quickstart Guide

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
5. Copy the 16-character code (e.g., `abcd efgh ijkl mnop` — remove spaces)

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

Open `.env` in your editor and fill in **only these 3 values**:

```env
# Your OpenAI API key
LLM_API_KEY=sk-proj-your-key-here

# Your honeypot email (IMAP)
HONEYPOT_IMAP_USER=your-honeypot@gmail.com
HONEYPOT_IMAP_PASSWORD=your-16-char-app-password

# Your SMTP sending config (same email, same app password)
# Note: @ in email must be written as %40
MAILER_DSN=smtps://your-honeypot%40gmail.com:your-app-password@smtp.gmail.com:465
```

**Provider-specific IMAP settings** (if not using Gmail):

| Provider | IMAP Host | SMTP DSN |
|----------|-----------|----------|
| Gmail | `imap.gmail.com` (default) | `smtps://user%40gmail.com:apppass@smtp.gmail.com:465` |
| Outlook | `outlook.office365.com` | `smtp://user%40outlook.com:pass@smtp.office365.com:587` |
| Yahoo | `imap.mail.yahoo.com` | `smtps://user%40yahoo.com:apppass@smtp.mail.yahoo.com:465` |

For Outlook/Yahoo, also change `HONEYPOT_IMAP_HOST` in `.env`.

Everything else works out of the box with sensible defaults.

### 3. Launch ScamBuster

```bash
make quickstart
```

This single command:
- Builds and starts all Docker containers (backend, frontend, database, Redis, n8n)
- Installs backend dependencies
- Creates and migrates the database
- Loads fixture data (users, personas, scam types)
- Generates JWT authentication keys
- Creates the n8n admin account automatically
- Imports and configures all n8n workflows
- Sets up IMAP email credentials
- Activates the email polling workflow

**Expected output** (last lines):

```
╔══════════════════════════════════════════════╗
║       ScamBuster is ready!                    ║
╚══════════════════════════════════════════════╝

Interfaces:
  Dashboard:  http://localhost:3002
  Backend:    http://localhost:8081/api/doc
  n8n:        http://localhost:5678
    Login:    admin@scambuster.local / (see .env)
```

**Typical duration**: ~90 seconds (first run with Docker image cache).

### 4. Verify the installation

```bash
make doctor
```

All required checks should show ✅. Warnings (⚠️) on `N8N_ENCRYPTION_KEY` and `INGEST_LOGIN` are expected in dev — change them for production.

### 5. Test the pipeline

Send an email to your honeypot address from any other email account. Within 1-2 minutes:

1. The IMAP trigger captures the email
2. ScamBuster classifies it and extracts IOCs
3. An LLM generates a contextual reply
4. The reply is sent via SMTP with proper email threading

Check the results:
- **Dashboard**: http://localhost:3002 — see conversations, IOCs, analytics
- **n8n**: http://localhost:5678 — see workflow executions

## Interfaces

| Interface | URL | Purpose |
|-----------|-----|---------|
| **Dashboard** | http://localhost:3002 | Operations dashboard, conversations, IOC explorer, analytics |
| **n8n** | http://localhost:5678 | Workflow orchestrator (login: `admin@scambuster.local` / see `.env`) |
| **API docs** | http://localhost:8081/api/doc | Swagger/OpenAPI documentation |

## Default credentials

| Service | Login | Password |
|---------|-------|----------|
| **n8n** | `admin@scambuster.local` | `Scambuster2026!` |
| **Backend API** | `user@example.com` | `Un1que$trongPassword2024` |
| **PostgreSQL** | `postgres` | `postgres` |

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

### `make quickstart` fails at "composer install"

The `vendor/` directory may have wrong permissions. Run:
```bash
chmod -R 777 backend-symfony/vendor backend-symfony/var
```

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

For production, change these values in `.env`:

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

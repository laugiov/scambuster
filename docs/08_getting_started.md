# Getting Started

A step-by-step guide to set up, run, and test ScamBuster locally.

All commands use the project's **Makefile** to abstract Docker and Symfony internals. You rarely need to interact with Docker or Composer directly.

---

## Prerequisites

| Tool | Minimum version | Check |
|------|----------------|-------|
| Docker | 24+ | `docker --version` |
| Docker Compose | v2+ | `docker compose version` |
| Make | any | `make --version` |
| Git | any | `git --version` |
| jq | any (optional, for API exploration) | `jq --version` |

> PHP, Composer, and Node.js are **not** required on your host machine. Everything runs inside Docker containers.

---

## 1. Clone and Configure

```bash
# Clone the repository
git clone https://github.com/laugiov/scambuster.git
cd scambuster

# Create your local environment file
cp .env.dist .env
```

Edit `.env` and replace the `change-me` placeholders. Here are the **minimum required** changes:

| Variable | Purpose | How to set |
|----------|---------|------------|
| `POSTGRES_PASSWORD` | Database password | Choose a strong password |
| `DATABASE_URL` | Must match `POSTGRES_PASSWORD` | Update the password in the connection string |
| `JWT_PASSPHRASE` | JWT signing key | `openssl rand -base64 64` |
| `LLM_API_KEY` | OpenAI API key | From [platform.openai.com](https://platform.openai.com) |

The following have safe defaults for local development but should be changed in production:

| Variable | Purpose | How to generate |
|----------|---------|-----------------|
| `APP_SECRET` | Symfony secret | `php -r "echo bin2hex(random_bytes(16));"` |
| `HONEYPOT_IMAP_PASSWORD` | IMAP password | Set to your honeypot IMAP password |
| `LOGIN_HASH_SALT` | Salt for hashing | `openssl rand -hex 16` |
| `N8N_ENCRYPTION_KEY` | n8n encryption | `openssl rand -hex 32` |

> **Without an OpenAI API key**, the backend will still start and tests will pass (LLM calls are mocked in tests). However, reply generation and LLM-based features (scam classification, IOC extraction via LLM, prompt injection Layer 2) will fail at runtime.

---

## 2. Build and Start the Stack

```bash
# Build Docker images (required on first run)
make build

# Start all containers in background
make upd

# Verify containers are running
make ps
```

You should see the following services:

| Service | Port | Purpose |
|---------|------|---------|
| `backend-dev` | 8081 | Symfony API (development) |
| `postgres` | 5432 | PostgreSQL 15 database |
| `redis` | 6379 | Cache and locks |
| `frontend` | 3002 | React frontend |
| `n8n` | 5678 | Workflow automation |

Verify the backend is responding:

```bash
curl -s http://localhost:8081/healthz
# Expected: {"status":"ok"}
```

---

## 3. Install Dependencies and Set Up Database

```bash
# Install PHP dependencies
make composer-install

# Run database migrations (creates tables)
make migration

# Load fixtures (reference data + default users)
make fixtures-dev
```

> **What `make migration` does**: executes all Doctrine migrations to create the schema (tables, indexes, foreign keys, views).

> **What `make fixtures-dev` does**: seeds the development database with reference data (13 scam types, 27 personas across 7 archetypes, lookup tables for channels and directions) **and creates two default users** (see below).

### Default Users

The fixtures create the following accounts:

| Email | Password | Role |
|-------|----------|------|
| `user@example.com` | `Un1que$trongPassword2024` | `ROLE_USER` |
| `admin@example.com` | `Un1que$trongPassword2024` | `ROLE_ADMIN` |

### Demo Mode (No API Keys Required)

To explore ScamBuster without an OpenAI API key:

1. Set `LLM_PROVIDER=mock` in your `.env` (instead of `openai`)
2. Start the stack: `make upd`
3. Load demo data: `make demo-load`
4. Open http://localhost:3002 and login with `admin@example.com` / `Un1que$trongPassword2024`

The dashboard will show 123 synthetic conversations with realistic IOCs. All LLM calls return mock responses.

### LLM Provider Configuration

ScamBuster supports multiple LLM providers. Set `LLM_PROVIDER` in `.env`:

| Provider | `LLM_PROVIDER` | `LLM_MODEL` | Cost | Notes |
|----------|----------------|-------------|------|-------|
| OpenAI | `openai` | `gpt-4o / gpt-4o-mini` | ~$0.008/msg | Default. Requires `LLM_API_KEY` |
| Anthropic | `anthropic` | `claude-haiku-4-5-20251001` | ~$0.0003/msg | Requires `ANTHROPIC_API_KEY` |
| Ollama | `ollama` | `llama3` / `mistral` | Free | Local. Set `OLLAMA_BASE_URL` |
| Mock | `mock` | -- | Free | Static responses, no API calls |

### Verify Installation

After setup, run the validation script to check all services:

```bash
make validate
```

### Quick Smoke Test

```bash
# Authenticate and get a JWT token
TOKEN=$(curl -s -X POST http://localhost:8081/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"Un1que$trongPassword2024"}' \
  | jq -r '.access_token')

# Verify you got a token
echo "Token: ${TOKEN:0:50}..."

# List conversations (should return an empty or seeded list)
curl -s http://localhost:8081/api/v1/communication/conversation \
  -H "Authorization: Bearer $TOKEN" | jq '.conversations | length'
```

If you see a number (even `0`), the backend, database, auth, and API are all working.

---

## 4. Run the Tests

ScamBuster has a **comprehensive test suite** covering unit, integration, and E2E, organized in three suites.

### Unit + Integration Tests (recommended first run)

```bash
make test
```

This command:
1. Loads test fixtures into the test database
2. Runs unit and integration tests with `--testdox` output

### End-to-End Tests

```bash
make endToEndTest
```

This command:
1. Loads E2E fixtures into a separate E2E database
2. Clears the E2E cache
3. Runs the full E2E suite (real HTTP calls, real JWT authentication)

### Run a Single Test

```bash
# By test class or method name
make testOne q=ConversationServiceTest

# For E2E
make endToEndTestOne q=IngestControllerTest
```

### Static Analysis

```bash
# PHPStan (level max)
make stan

# PHP-CS-Fixer (code style)
make cs-fixer
```

---

## 5. Set Up n8n Workflows (Optional)

n8n handles email ingestion, reply generation, and IOC extraction workflows. If you want to process real emails, you need to import the workflows.

### Access n8n

Open [http://localhost:5678](http://localhost:5678) in your browser. On first access, create an n8n admin account.

### Import Workflows

1. In n8n, go to **Workflows** > **Import from file**
2. Import the JSON files from the `n8n/` directory:

| Workflow | Purpose |
|----------|---------|
| `WF-INTAKE-EMAIL-V2` | Poll Gmail > parse > classify > ingest |
| `WF-REPLY-GENERATE-V2` | Generate + validate LLM replies |
| `WF-REPLY-SEND-v1` | Send validated replies via email |
| `WF-EXTRACT-AND-ENRICH-IOC` | Extract and enrich IOCs from messages |

3. Configure the credentials in each workflow (API URL, JWT token)

> **Note**: n8n workflows connect to the backend at `http://backend-dev:8080` (internal Docker network). They authenticate using the same JWT mechanism as the API.

### Configure IMAP accounts

If you want n8n to poll real mailboxes, set the IMAP credentials via environment variables in `.env`:

```bash
HONEYPOT_IMAP_HOST=imap.gmail.com
HONEYPOT_IMAP_USER=your-honeypot@example.com
HONEYPOT_IMAP_PASSWORD=your-app-password
```

---

## 6. Multi-Mailbox Setup (optional)

ScamBuster supports operating multiple honeypot mailboxes simultaneously. Each mailbox can have its own SMTP credentials so outbound replies are properly DKIM/SPF-aligned with the `From:` domain.

### Single-mailbox install (default — no setup needed)

The default single-mailbox setup uses the global `MAILER_DSN` environment variable. No additional configuration is required.

### Adding additional mailboxes

For each additional mailbox, register it via the CLI:

```bash
docker compose exec backend-dev bin/console app:mail-account:add \
    --owner-id=<existing-owner-uuid> \
    --email=<reply-from-address> \
    --smtp-dsn='smtps://<user>:<password>@<smtp-host>:465' \
    --label="Optional internal name"
```

The command outputs the new `account_id` (UUID). Use it in the corresponding n8n workflow:

1. **Clone** `WF-INTAKE-EMAIL-V2` and rename it (one workflow per mailbox)
2. In the **IMAP Email Trigger** node, point to the credential of the new mailbox
3. In the **Prepare Payload** node, replace the `accountId` constant with the new UUID

The SMTP DSN is encrypted at rest using a key derived from `APP_SECRET`. It is never logged, never displayed by `app:mail-account:list`, and never returned by any API.

### Managing existing mailboxes

```bash
# List all mailboxes (does NOT reveal SMTP credentials)
docker compose exec backend-dev bin/console app:mail-account:list

# Rotate the SMTP DSN
docker compose exec backend-dev bin/console app:mail-account:rotate-smtp <account-id> \
    --smtp-dsn='smtps://<new-user>:<new-pass>@<smtp-host>:465'

# Soft-disable (sets is_active = false; can be re-enabled by SQL)
docker compose exec backend-dev bin/console app:mail-account:disable <account-id>
```

### How routing works

When ScamBuster sends a reply:
- If the conversation's account has a custom encrypted SMTP DSN → use that transport
- Otherwise → fall back to the global `MAILER_DSN`

This means single-mailbox installs continue to work unchanged, while multi-mailbox installs get per-account SMTP isolation automatically.

> **Important**: rotating `APP_SECRET` invalidates all encrypted SMTP DSNs. See [Key Management](14_key_management.md) for the impact and recovery procedure.

### Reliable IMAP polling for the n8n intake workflows

n8n's `IMAP Email Trigger` node defaults to a long-lived **IMAP IDLE** connection (push notifications from the server). Some IMAP providers enforce strict IDLE timeouts and close the connection after only a few minutes, which can leave the trigger stuck in a `drop → reactivate` loop and silently drop incoming emails.

To make the intake workflows reliable across providers, configure the IMAP trigger node as follows:

1. Open each `WF-INTAKE-EMAIL-*` workflow in n8n
2. Toggle the workflow **Inactive**
3. Open the `IMAP Email Trigger` node
4. In **Parameters → Options → Add option**, add:
   - **Force Reconnect Every Minutes** → `2`
   - **Fetch Only New Emails** → `false` (uncheck)
5. Keep the existing settings: `Action: Mark as Read`, `Custom Email Rules: ["UNSEEN"]`
6. Save and toggle the workflow **Active** again

**Why this matters**:

- `Force Reconnect Every Minutes = 2` instructs n8n to recycle the connection cleanly every 2 minutes, before any provider-side timeout has a chance to fire. The error path (`Connected closed unexpectedly` → reactivation) is never triggered.
- `Fetch Only New Emails = false` disables n8n's internal UID tracking and forces a real `SEARCH UNSEEN` against the server at every reconnect. Combined with `Mark as Read`, the IMAP server itself becomes the source of truth for "already processed" — no internal state to desynchronize, no duplicates.

This stateless-polling pattern is portable across all standards-compliant IMAP providers and works whether your provider keeps IDLE connections open for hours or closes them within minutes. Worst-case ingestion delay is about 2 minutes.

> **Note**: in n8n logs, you should see the `Connected closed unexpectedly` / `Will try to reactivate` messages disappear after applying these settings. Reconnections happen silently in the background.

---

## 7. Explore the API

Once the stack is running, the API is available at `http://localhost:8081`.

### Authenticate

```bash
# Get a JWT token
TOKEN=$(curl -s -X POST http://localhost:8081/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"Un1que$trongPassword2024"}' \
  | jq -r '.access_token')
```

### Example API Calls

```bash
# List conversations
curl -s http://localhost:8081/api/v1/communication/conversation \
  -H "Authorization: Bearer $TOKEN" | jq .

# List scam types
curl -s http://localhost:8081/api/v1/communication/scam-types \
  -H "Authorization: Bearer $TOKEN" | jq .

# Get scambaiting stats
curl -s http://localhost:8081/api/v1/scambaiting/stats \
  -H "Authorization: Bearer $TOKEN" | jq .

# View all routes
make debug-router
```

---

## 8. Common Workflows

### Full Reset (Nuclear Option)

If you need to start completely fresh (drops all databases, recreates everything, loads all fixtures):

```bash
make respawn-all
```

This runs: `reset-db` + `reset-db-test` + `reset-db-e2e` + all fixtures.

### Database Operations

```bash
# Reset dev database only (drop + create + migrate)
make reset-db

# Reset test database only
make reset-db-test

# Reset E2E database only
make reset-db-e2e

# Generate a new migration after changing an entity
make migration-diff

# Load fixtures in dev
make fixtures-dev

# Load fixtures in test
make fixtures

# Load fixtures in E2E
make fixtures-e2e
```

### Symfony Console

```bash
# Run any Symfony console command
make console q="debug:router"
make console q="doctrine:schema:validate"
make console q="cache:clear"

# In test environment
make console-test q="debug:container --tag=doctrine.event_listener"
```

### Logs

```bash
# Backend logs (Symfony)
make log-backend

# Database logs (PostgreSQL)
make log-db
```

### Composer

```bash
# Install a new package
make composer-require q="symfony/mailer"

# Install a dev-only package
make composer-require-dev q="phpstan/phpstan"

# Update all dependencies
make composer-update

# Run any Composer command
make composer q="show --outdated"
```

---

## 9. Project Structure Overview

```
scambuster/
├── backend-symfony/           # PHP/Symfony backend
│   ├── src/
│   │   ├── Domain/            # Entities, value objects, events, enums
│   │   ├── Application/       # Handlers, services, orchestrators
│   │   ├── Infrastructure/    # Doctrine repos, API adapters, listeners
│   │   └── UI/Http/           # Controllers (final, single __invoke)
│   ├── tests/
│   │   ├── Unit/              # Pure domain logic tests
│   │   ├── Integration/       # Service + database tests
│   │   └── EndToEnd/          # Full API flow tests
│   └── migrations/            # Doctrine migrations + reference data SQL
├── n8n/                       # Workflow JSON definitions
├── prompts/personas/          # Persona YAML templates (27 personas)
├── infra/                     # Docker configs (Dockerfile)
├── docs/                      # Project documentation
├── docker-compose.yml
├── Makefile                   # All commands documented here
└── .env.dist                  # Environment template
```

---

## 10. Docker Services Reference

| Container | Image | Env | Purpose |
|-----------|-------|-----|---------|
| `backend-dev` | Custom (PHP 8.3) | `dev` | Development API server |
| `backend-test` | Custom (PHP 8.3) | `test` | Integration/unit test runner |
| `backend-e2e` | Custom (PHP 8.3) | `e2e` | End-to-end test runner |
| `backend-preprod` | Custom (PHP 8.3) | `dev` | Pre-production (port 8082) |
| `postgres` | postgres:15-alpine | -- | Main database |
| `postgres-preprod` | postgres:15-alpine | -- | Pre-production database (port 5433) |
| `redis` | redis:7-alpine | -- | Cache and distributed locks |
| `frontend` | node:20-alpine | -- | React frontend (port 3002) |
| `n8n` | n8nio/n8n | -- | Workflow automation |
| `scheduler` | Custom (PHP 8.3) | `dev` | Automated tasks (close stale, rewards, injection detection, embeddings, bandit report, backups) |

### Scheduler (Automated Tasks)

The `scheduler` container runs automatically with `docker compose up`. It executes these tasks on a loop:

| Task | Frequency | Command |
|------|-----------|---------|
| Close stale conversations | Every 6h | `app:close-stale-conversations` |
| Backfill rewards | Every 6h | `preprod:calculate-rewards` |
| Prompt injection detection | Every 6h | `app:detect-prompt-injection` |
| Generate embeddings | Every 6h | `app:generate-embeddings --limit=500` |
| Bandit convergence report | Daily 06:00 UTC | `app:bandit:daily-report` |
| Actor profile generation | Daily 06:00 UTC | `app:generate-actor-profiles` |
| PostgreSQL backup | Daily 02:00 UTC | `pg_dump` (7-day retention) |
| Weekly cleanup | Sunday 04:00 UTC | `app:cleanup:weekly` |

To disable the scheduler: set `SCHEDULER_ENABLED=false` in `.env`.

---

## 10. Makefile Quick Reference

Run `make help` for the full list. Here are the most useful commands:

### Essentials

| Command | Description |
|---------|-------------|
| `make build` | Build Docker images |
| `make up` | Start the stack (foreground) |
| `make upd` | Start the stack (background) |
| `make down` | Stop all containers |
| `make ps` | Show container status |
| `make test` | Run unit + integration tests |
| `make endToEndTest` | Run E2E tests |
| `make stan` | PHPStan static analysis |
| `make cs-fixer` | Fix code style |

### Database

| Command | Description |
|---------|-------------|
| `make migration` | Run pending migrations (dev) |
| `make migration-diff` | Generate migration from entity changes |
| `make reset-db` | Drop + create + migrate (dev) |
| `make fixtures-dev` | Load dev fixtures |
| `make respawn-all` | Full reset of all environments |

### Scambaiting Operations

| Command | Description |
|---------|-------------|
| `make close-stale` | Close conversations inactive > 7 days (use `d=N` to override) |
| `make close-stale-dry` | Preview stale conversations without closing |

### Evaluation Benchmark

| Command | Description |
|---------|-------------|
| `make evaluate-corpus COUNT=500` | Generate evaluation corpus (500 LLM replies with metadata) |
| `make evaluate-corpus COUNT=500 DRY_RUN=1` | Estimate cost without LLM calls |
| `make evaluate-quality` | Compute 9 quality metrics on latest corpus |
| `make evaluate-bandit` | Analyze bandit convergence per scam type |
| `make evaluate-all` | Run full evaluation pipeline (corpus + quality + bandit) |

### Deployment

| Command | Description |
|---------|-------------|
| `make deploy` | Build, start, migrate, load fixtures, and activate n8n workflows |

### Development

| Command | Description |
|---------|-------------|
| `make console q="..."` | Run Symfony console command |
| `make debug-router` | Show all API routes |
| `make log-backend` | Tail backend logs |
| `make composer q="..."` | Run Composer command |
| `make testOne q=MyTest` | Run a single test |

---

## Troubleshooting

### Containers won't start

```bash
# Check if ports are already in use
lsof -i :8081  # backend
lsof -i :5432  # postgres
lsof -i :6379  # redis

# Rebuild images from scratch
make build
make up
```

### "Cannot find the redis extension" error

This means the Docker image was built without the Redis PHP extension. Rebuild:

```bash
make build
docker compose up -d backend-dev
```

### Database migration errors

```bash
# Validate schema consistency
make console q="doctrine:schema:validate"

# Full reset if migrations are broken
make reset-db
```

### Tests fail on first run

```bash
# Ensure test database exists and is up to date
make reset-db-test

# Clear test cache
make cc-test

# Then retry
make test
```

### 401 Unauthorized on API calls

- JWT tokens expire after 15 minutes. Generate a new one.
- Check that fixtures have been loaded (`make fixtures-dev`).
- Verify the `JWT_SECRET` in `.env` matches what was used when the token was issued.

### OpenAI API returns 401

- Check that `LLM_API_KEY` in `.env` is a valid OpenAI API key.
- Restart the container after changing `.env`: `docker compose up -d backend-dev`

### Permission issues on Linux

```bash
# Fix volume permissions if needed
sudo chown -R $(whoami):$(whoami) backend-symfony/var/
```

---

## Next Steps

- Read the [Architecture Overview](03_high_level_architecture.md) to understand the system design
- Review [Security & Ethics](04_security_guardrails.md) before deploying
- Check the [FAQ](07_faq.md) for common questions
- Explore the API documentation at `http://localhost:8081/api/doc`

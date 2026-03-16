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
| `JWT_SECRET` | JWT signing key | `openssl rand -base64 64` |
| `LLM_API_KEY` | OpenAI API key | From [platform.openai.com](https://platform.openai.com) |

The following have safe defaults for local development but should be changed in production:

| Variable | Purpose | How to generate |
|----------|---------|-----------------|
| `APP_SECRET` | Symfony secret | `php -r "echo bin2hex(random_bytes(16));"` |
| `VAULT_TOKEN` | Vault dev token | Use `root` for local dev |
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
| `vault` | 8200 | HashiCorp Vault (dev mode) |
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

> **What `make fixtures-dev` does**: seeds the development database with reference data (12 scam types, 27 personas across 7 archetypes, lookup tables for channels and directions) **and creates two default users** (see below).

### Default Users

The fixtures create the following accounts:

| Email | Password | Role |
|-------|----------|------|
| `user@example.com` | `Un1que$trongPassword2024` | `ROLE_USER` |
| `admin@example.com` | `Un1que$trongPassword2024` | `ROLE_ADMIN` |

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

ScamBuster has **1310 automated tests** (1077 unit/integration + 233 E2E) organized in three suites.

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

### Seed Vault (for IMAP accounts)

If you want n8n to poll real mailboxes, you need to store IMAP credentials in Vault:

```bash
# Seed a dummy IMAP account (included in respawn-all)
make console q="vault:imap-secret:add dummyhash user@example.com motdepasse123"

# Or use respawn-all which does everything (reset DBs + fixtures + Vault seed)
make respawn-all
```

---

## 6. Explore the API

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
curl -s http://localhost:8081/api/v1/communication/scam-type \
  -H "Authorization: Bearer $TOKEN" | jq .

# Get scambaiting stats
curl -s http://localhost:8081/api/v1/scambaiting/stats \
  -H "Authorization: Bearer $TOKEN" | jq .

# View all routes
make debug-router
```

---

## 7. Common Workflows

### Full Reset (Nuclear Option)

If you need to start completely fresh (drops all databases, recreates everything, loads all fixtures, seeds Vault):

```bash
make respawn-all
```

This runs: `reset-db` + `reset-db-test` + `reset-db-e2e` + all fixtures + Vault seed.

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

## 8. Project Structure Overview

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
├── prompts/personas/          # Persona YAML templates (6 personas)
├── infra/                     # Docker configs (Dockerfile)
├── docs/                      # Project documentation
├── docker-compose.yml
├── Makefile                   # All commands documented here
└── .env.dist                  # Environment template
```

---

## 9. Docker Services Reference

| Container | Image | Env | Purpose |
|-----------|-------|-----|---------|
| `backend-dev` | Custom (PHP 8.3) | `dev` | Development API server |
| `backend-test` | Custom (PHP 8.3) | `test` | Integration/unit test runner |
| `backend-e2e` | Custom (PHP 8.3) | `e2e` | End-to-end test runner |
| `backend-preprod` | Custom (PHP 8.3) | `dev` | Pre-production (port 8082) |
| `postgres` | postgres:15-alpine | -- | Main database |
| `postgres-preprod` | postgres:15-alpine | -- | Pre-production database (port 5433) |
| `redis` | redis:7-alpine | -- | Cache and distributed locks |
| `vault` | hashicorp/vault | -- | Secrets management (dev mode) |
| `n8n` | n8nio/n8n | -- | Workflow automation |

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
| `make respawn-all` | Full reset of all environments + Vault |

### Scambaiting Operations

| Command | Description |
|---------|-------------|
| `make close-stale` | Close conversations inactive > 7 days (use `d=N` to override) |
| `make close-stale-dry` | Preview stale conversations without closing |

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

- JWT tokens expire after 1 hour. Generate a new one.
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

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

Edit `.env` and replace the `change-me` placeholders with real values. The critical ones are:

| Variable | Purpose | How to generate |
|----------|---------|-----------------|
| `APP_SECRET` | Symfony secret | `php -r "echo bin2hex(random_bytes(16));"` |
| `POSTGRES_PASSWORD` | Database password | Choose a strong password |
| `DATABASE_URL` | Must match `POSTGRES_PASSWORD` | Update the password in the URL |
| `JWT_SECRET` | JWT signing key | `openssl rand -base64 64` |
| `VAULT_TOKEN` | Vault dev token | Use `root` for local dev |
| `LOGIN_HASH_SALT` | Salt for hashing | `openssl rand -hex 16` |
| `N8N_ENCRYPTION_KEY` | n8n encryption | `openssl rand -hex 32` |
| `LLM_API_KEY` | OpenAI API key | From [platform.openai.com](https://platform.openai.com) |

> For local development/testing without LLM calls, you can leave `LLM_API_KEY` as the placeholder. Tests that require LLM are mocked.

---

## 2. Start the Stack

```bash
# Start all containers (foreground — you'll see logs)
make up

# Or start in background (detached)
make upd
```

This starts the following services:

| Service | Port | Purpose |
|---------|------|---------|
| `backend-dev` | 8081 | Symfony API (development) |
| `postgres` | 5432 | PostgreSQL 15 database |
| `redis` | 6379 | Cache and locks |
| `vault` | 8200 | HashiCorp Vault (dev mode) |
| `n8n` | 5678 | Workflow automation |

Verify containers are running:

```bash
make ps
```

---

## 3. Install Dependencies and Set Up Database

```bash
# Install PHP dependencies
make composer-install

# Run database migrations (creates tables)
make migration

# Load test fixtures (seed reference data)
make fixtures-dev
```

> **What `make migration` does**: executes all Doctrine migrations to create the schema (tables, indexes, foreign keys, views).

> **What `make fixtures-dev` does**: seeds the development database with reference data (13 scam types, 27 personas, lookup tables for channels and directions).

---

## 4. Run the Tests

ScamBuster has **955 automated tests** organized in three suites.

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

## 5. Explore the API

Once the stack is running, the API is available at `http://localhost:8081`.

### Authenticate

```bash
# Get a JWT token
curl -s -X POST http://localhost:8081/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"your-password"}' \
  | jq .

# Save the token for subsequent requests
TOKEN="your-jwt-token-here"
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

# View routes
make debug-router
```

### API Documentation

Swagger/OpenAPI documentation is available at:
- `http://localhost:8081/api/doc` (Swagger UI)

---

## 6. Common Workflows

### Full Reset (Nuclear Option)

If you need to start completely fresh (drops all databases, recreates everything, loads all fixtures):

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

## 7. Project Structure Overview

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
├── infra/                     # Docker configs
├── docs/                      # Project documentation
├── docker-compose.yml
├── Makefile                   # All commands documented here
└── .env.dist                  # Environment template
```

---

## 8. Docker Services Reference

| Container | Image | Env | Purpose |
|-----------|-------|-----|---------|
| `backend-dev` | Custom (PHP 8.3) | `dev` | Development API server |
| `backend-test` | Custom (PHP 8.3) | `test` | Integration/unit test runner |
| `backend-e2e` | Custom (PHP 8.3) | `e2e` | End-to-end test runner |
| `backend-preprod` | Custom (PHP 8.3) | `dev` | Pre-production (port 8082) |
| `postgres` | postgres:15-alpine | — | Main database |
| `postgres-preprod` | postgres:15-alpine | — | Pre-production database (port 5433) |
| `redis` | redis:7-alpine | — | Cache and distributed locks |
| `vault` | hashicorp/vault | — | Secrets management (dev mode) |
| `n8n` | n8nio/n8n | — | Workflow automation |

---

## 9. Makefile Quick Reference

Run `make help` for the full list. Here are the most useful commands:

### Essentials

| Command | Description |
|---------|-------------|
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

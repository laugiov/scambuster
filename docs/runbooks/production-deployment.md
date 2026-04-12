# Production deployment runbook

> **Spec 065c** — operational guide for deploying ScamBuster to a
> single-host Docker Compose production target.

## Scope

This runbook covers a **single-host Docker Compose** deployment. For
Kubernetes / Swarm, see the placeholder section at the end (deferred
to a future spec).

## Prerequisites

- Linux host (Ubuntu 22.04+, Debian 12+)
- Docker Engine 24+ and Docker Compose v2
- 8 GB RAM, 4 vCPUs, 100 GB disk minimum
- Outbound HTTPS to OpenAI / Anthropic (LLM provider)
- Inbound HTTPS via reverse proxy (Nginx, Caddy, Traefik) to the
  backend on port 8080 and the frontend on port 5173 (or whatever
  ports your reverse proxy maps)
- A real domain with TLS (Let's Encrypt or commercial cert)

## Pre-deployment checklist

- [ ] `.env` file created from `.env.dist` with **production** values for:
  - `POSTGRES_PASSWORD` — `openssl rand -hex 24`
  - `JWT_PASSPHRASE` — `openssl rand -hex 32`
  - `LOGIN_HASH_SALT` — `openssl rand -hex 16`
  - `N8N_ENCRYPTION_KEY` — `openssl rand -hex 32` (see
    `docs/runbooks/n8n-credentials.md` for the rotation procedure)
  - `N8N_BASIC_AUTH_USER` and `N8N_BASIC_AUTH_PASSWORD` — strong
    credentials for the n8n web UI
  - `LLM_API_KEY` — your OpenAI / Anthropic key
  - `LLM_MAX_COST_USD_MONTH` — your monthly budget cap (Spec 065b)
  - `LLM_BUDGET_ENFORCEMENT_MODE` — `enforce` (after the
    one-week telemetry validation per Spec 065b)
  - `APP_API_BASE_URL` — your public HTTPS URL
- [ ] `.env` is mode `0600` and owned by the deploy user
- [ ] JWT keypair generated:
  ```bash
  bash scripts/generate-jwt-keys.sh
  ```
- [ ] DNS A/AAAA records pointing the domain at the host
- [ ] TLS certificate provisioned and mounted on the reverse proxy
- [ ] Backups configured for `./data/n8n/`, the Postgres volume, and
  `.env` (separate destinations — see n8n-credentials.md)

## Deployment

```bash
# 1. Clone the repository
git clone https://github.com/laugiov/scambuster.git
cd scambuster

# 2. Check out the production tag
git checkout v2.18.0   # or the latest stable release tag

# 3. Copy and edit .env
cp .env.dist .env
$EDITOR .env

# 4. Generate JWT keys
bash scripts/generate-jwt-keys.sh

# 5. Build the backend image (production-ready, no source mount)
docker compose -f docker-compose.yml -f docker-compose.prod.yml build backend-dev

# 6. Start the production stack
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d \
    postgres redis backend-dev n8n scheduler

# 7. Wait for services to become healthy
sleep 30
docker compose ps
# Expected:
#   postgres                healthy
#   redis                   healthy
#   backend-prod            healthy   (Spec 065c HEALTHCHECK)
#   n8n                     running
#   scheduler               running

# 8. Run database migrations
docker compose exec backend-dev php bin/console doctrine:migrations:migrate \
    --no-interaction --env=prod

# 9. Run admin user fixture (one-time bootstrap)
docker compose exec backend-dev php bin/console app:user:create-admin \
    --email=<admin-email> --no-interaction
# (or seed via your own fixture pipeline)

# 10. Smoke test
curl -fsS https://your-domain.example.com/api/health
# Expected: {"status":"ok","checks":{"database":{"status":"ok",...},"redis":{"status":"ok",...}}}
```

## Post-deployment verification

```bash
# Stack health
docker compose -f docker-compose.yml -f docker-compose.prod.yml ps

# Backend health endpoint (Spec 065c)
curl -fsS http://localhost:8080/api/health | jq

# Spec 065b LLM cost monitoring (after admin login)
JWT=$(curl -s -X POST https://your-domain/api/v1/auth/login \
    -H 'Content-Type: application/json' \
    -d '{"email":"admin@example.com","password":"<password>"}' | jq -r .access_token)
curl -s -H "Authorization: Bearer $JWT" \
    https://your-domain/api/v1/monitoring/llm-cost | jq

# Spec 065c — verify Vault is gone
docker compose ps | grep vault   # expected: empty

# Spec 065c — verify Redis is internal-only
nc -zv localhost 6379            # expected: connection refused

# Spec 065c — verify backend container is healthy
docker compose ps backend-dev | grep -i healthy
```

## Rollback procedure

If a deployment fails (failed migration, broken endpoint, regression):

1. **Identify the failure scope** — is the DB schema corrupted? Is it
   only the backend container? Is the frontend still serving?

2. **Roll back the code**:
   ```bash
   git checkout <previous-tag>   # e.g., the previous v2.X.Y
   docker compose -f docker-compose.yml -f docker-compose.prod.yml \
       build backend-dev
   docker compose -f docker-compose.yml -f docker-compose.prod.yml \
       up -d backend-dev
   ```

3. **Roll back the DB** (only if a migration ran successfully):
   ```bash
   docker compose exec backend-dev php bin/console doctrine:migrations:migrate \
       prev --no-interaction --env=prod
   ```
   If the migration is irreversible (some Spec 065 migrations are),
   the rollback path is **restore from backup**:
   ```bash
   # Stop the stack
   docker compose stop backend-dev
   # Restore the backup
   gunzip < /backups/scambuster_<date>.sql.gz | docker compose exec -T postgres psql -U postgres scambuster
   # Restart
   docker compose up -d backend-dev
   ```

4. **Verify** the rollback with the post-deployment verification
   commands above.

5. **File an incident report** documenting the failure and the
   rollback steps.

## Operating notes

- The backend container exposes `/api/health` (no auth) for orchestrator
  liveness probes.
- The Spec 065b LLM kill switch admin endpoint is at
  `POST /api/v1/admin/llm/killswitch` and toggles a Redis-backed flag
  to halt all reply generation without restarting.
- The scheduler container runs cron-like recurring tasks (clustering,
  budget check, daily backup). It is **NOT** a single point of failure
  for ingestion — n8n handles the IMAP polling.
- Backups via `pg_dump` are written to the `postgres-backups` named
  volume, daily at 02:00 UTC.
- For n8n credential rotation, see `docs/runbooks/n8n-credentials.md`.

## Kubernetes / Swarm deployment

**Out of scope for Spec 065c.** A future spec will publish a Helm
chart and a Swarm stack file. For now, operators with Kubernetes
ambitions can derive their own manifests from
`docker-compose.yml + docker-compose.prod.yml`.

## References

- Spec 065c: `specs/065c-sprint1-ops-hardening/`
- Spec 065b LLM cost guard: `specs/065b-sprint1-llm-cost-guard/`
- n8n credentials runbook: `docs/runbooks/n8n-credentials.md`
- `.env.dist` template: project root
- `docker-compose.prod.yml` overlay: project root

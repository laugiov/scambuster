# 031 — Railway Demo Deployment

## Problem

A decision-maker (SOC manager, CERT lead, CISO) who wants to evaluate ScamBuster must currently:
1. Clone a private GitHub repo
2. Install Docker + Docker Compose
3. Configure 4 environment variables
4. Run `make quickstart` and wait 90 seconds

This is fine for developers but too much friction for a non-technical evaluator. We need a **zero-install demo** accessible via a single URL.

## Goal

Deploy ScamBuster on Railway with pre-loaded demo data. A decision-maker visits `https://scambuster-demo.up.railway.app`, logs in, and sees a fully populated platform — 150 conversations, 876 IOCs, 5 campaigns, pipeline monitoring, convergence history, analytics.

## Update workflow

```
main (development) ──merge──> demo (branch) ──auto-deploy──> Railway
```

- Developer works on `main` as usual
- When ready to update the demo: `git checkout demo && git merge main && git push origin demo`
- Railway auto-deploys on push to `demo` branch (native feature, no GitHub Actions needed)
- The entrypoint is idempotent: if data exists, it skips seeding. If schema changed, migrations run automatically.

For a full data reset (e.g., after fixture changes):
- Push a commit that sets `DEMO_FORCE_RESEED=true` in the Railway env, then reset it after deploy

## Architecture

### Services (4 containers on Railway)

```
┌─────────────┐     ┌─────────────┐
│  frontend    │────>│  backend     │
│  nginx:alpine│ /api│  php:8.3-cli │
│  port 3000   │     │  port 8080   │
└─────────────┘     └──────┬───────┘
                           │
                    ┌──────┴───────┐
                    │              │
              ┌─────┴─────┐ ┌─────┴─────┐
              │ PostgreSQL │ │   Redis    │
              │ 15-alpine  │ │ 7-alpine   │
              └───────────┘ └───────────┘
```

### What's included
- Backend API (PHP 8.3, Symfony 7.2, built-in server)
- Frontend (React 19, Vite build, served by nginx with `/api` proxy to backend)
- PostgreSQL 15 (Railway plugin — persistent across deploys)
- Redis 7 (Railway plugin or container)

### What's NOT included (vs full install)
- n8n (no workflows — demo data is pre-loaded, no live email ingestion)
- Vault (not needed — no IMAP secrets to manage)
- Scheduler (no cron jobs in demo)
- All email-related features (IMAP polling, SMTP sending) — disabled

### Demo mode behavior
- `LLM_PROVIDER=mock` — no API key needed, no LLM calls
- `MAILER_DSN=null://null` — no emails sent
- `SCAMBUSTER_KILL_SWITCH=false` — pipeline visible but no live processing
- All data is synthetic (150 conversations from `scambuster-dataset-sample.json`)

## Technical decisions

### Frontend: nginx proxy (not Vite env var injection)

The current frontend uses Vite with a dev proxy (`/api` → `backend-dev:8080`). In production, Vite variables are injected at **build time** — this means the backend URL must be known before building.

**Solution**: Build the frontend as static files, serve via nginx. Nginx proxies `/api` to the backend container. This way:
- No CORS issues (same origin)
- No build-time URL injection needed
- Same pattern as the Vite dev proxy
- Single public URL for the decision-maker

### Backend: PHP built-in server (not FPM)

The existing backend Dockerfile uses `php -S 0.0.0.0:8080`. For a demo with <10 concurrent users, this is sufficient. No need for FrankenPHP or PHP-FPM complexity.

### Entrypoint: migrations → fixtures → demo dataset

The backend entrypoint must handle 3 scenarios:
1. **First deploy** (empty DB): run migrations → load fixtures → load demo dataset
2. **Schema update** (DB exists, new migrations): run migrations only — data preserved
3. **Normal restart** (nothing changed): skip everything, start fast

```
Is DB reachable?
  └─ No → wait + retry
  └─ Yes → Run migrations (--allow-no-migration)
           └─ Is conversation table empty?
              └─ Yes → Load fixtures-dev → Load demo dataset
              └─ No → Skip seeding, start server
```

### Railway deployment: native auto-deploy (no GitHub Actions)

Railway supports connecting a GitHub repo and auto-deploying on push to a specific branch. This is simpler and more reliable than GitHub Actions + Railway API.

Configuration (one-time, via Railway UI):
- Connect repo `laugiov/scambuster`
- Set deploy branch: `demo`
- Set root directory per service: `backend-symfony/` for backend, `frontend-react/` for frontend
- Add PostgreSQL plugin + Redis plugin
- Set environment variables

## Files to create

### 1. `docker-compose.demo.yml` (for local testing)

Minimal compose file that mirrors Railway architecture. Used to test locally before deploying.

```yaml
services:
  postgres:
    image: postgres:15-alpine
    environment:
      POSTGRES_DB: scambuster_demo
      POSTGRES_USER: postgres
      POSTGRES_PASSWORD: demo-password
    volumes:
      - demo-pgdata:/var/lib/postgresql/data

  redis:
    image: redis:7-alpine

  backend:
    build:
      context: .
      dockerfile: infra/docker/demo/Dockerfile.backend
    environment:
      DATABASE_URL: postgresql://postgres:demo-password@postgres:5432/scambuster_demo
      REDIS_URL: redis://redis:6379
      APP_ENV: dev
      APP_SECRET: demo-secret-not-for-production
      JWT_PASSPHRASE: demo-jwt-passphrase
      LLM_PROVIDER: mock
      LLM_MODEL: mock
      LLM_API_KEY: not-needed
      LLM_API_URL: https://api.openai.com/v1
      MAILER_DSN: "null://null"
      SCAMBUSTER_KILL_SWITCH: "false"
      SCAMBUSTER_SAFE_DOMAINS: "*"
      LOGIN_HASH_SALT: demo-salt
      VAULT_ADDR: http://localhost:8200
      VAULT_TOKEN: root
      VAULT_IMAP_PATH: secret/scambuster/imap/
      LOCK_DSN: redis://redis:6379
      PROMPT_INJECTION_ENABLED: "false"
      STIX_EXPORT_PATH: /tmp/stix-demo
      SCORE_RISK_MIN: "30"
      CAMPAIGN_PROMOTION_PPV_THRESHOLD: "0.85"
      CAMPAIGN_PROMOTION_MIN_HITS: "5"
      CAMPAIGN_PROMOTION_MIN_LEAD_TIME_SEC: "10800"
      REPLY_MIN_WORDS: "50"
      REPLY_MAX_WORDS: "150"
      REPLY_MAX_LINKS: "1"
      REPLY_HISTORY_LAST_N: "5"
      CONVERSATION_HISTORY_EXCLUDED_EMAILS: noreply@example.com
      SIEM_PROVIDER: none
      INGEST_LOGIN: user@example.com
      INGEST_PASSWORD: "Un1que$$trongPassword2024"
      LLM_VALIDATION_ENABLED: "true"
    depends_on:
      - postgres
      - redis
    ports:
      - "8080:8080"

  frontend:
    build:
      context: .
      dockerfile: infra/docker/demo/Dockerfile.frontend
    environment:
      BACKEND_URL: http://backend:8080
    depends_on:
      - backend
    ports:
      - "3002:80"

volumes:
  demo-pgdata:
```

### 2. `infra/docker/demo/Dockerfile.backend`

Multi-stage build based on existing `infra/docker/backend/Dockerfile`. Adds:
- Copy `scambuster-dataset-sample.json` into the image
- Custom entrypoint that handles auto-seeding
- JWT key generation at startup

### 3. `infra/docker/demo/docker-entrypoint-demo.sh`

```bash
#!/bin/sh
set -e

echo "[demo] ScamBuster Demo — starting..."

# 1. Wait for PostgreSQL
echo "[demo] Waiting for database..."
until php bin/console doctrine:query:sql "SELECT 1" > /dev/null 2>&1; do
  sleep 2
done
echo "[demo] Database ready."

# 2. Run migrations
echo "[demo] Running migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

# 3. Generate JWT keys if missing
if [ ! -f config/jwt/private.pem ]; then
  echo "[demo] Generating JWT keys..."
  mkdir -p config/jwt
  openssl genpkey -algorithm RSA -out config/jwt/private.pem \
    -aes-256-cbc -pass "pass:${JWT_PASSPHRASE}" -pkeyopt rsa_keygen_bits:2048 2>/dev/null
  openssl rsa -in config/jwt/private.pem -pubout -out config/jwt/public.pem \
    -passin "pass:${JWT_PASSPHRASE}" 2>/dev/null
  echo "[demo] JWT keys generated."
fi

# 4. Seed if DB is empty
CONV_COUNT=$(php bin/console doctrine:query:sql \
  "SELECT COUNT(*) FROM conversation" --no-interaction 2>/dev/null \
  | grep -oE '[0-9]+' | head -1 || echo "0")

if [ "$CONV_COUNT" = "0" ] || [ "${DEMO_FORCE_RESEED:-false}" = "true" ]; then
  echo "[demo] Seeding database (fixtures + demo dataset)..."
  php bin/console doctrine:fixtures:load --no-interaction
  php bin/console scambuster:demo:load
  echo "[demo] Database seeded: fixtures + 150 demo conversations."
else
  echo "[demo] Database already has $CONV_COUNT conversations — skipping seed."
fi

# 5. Clear cache
php bin/console cache:clear --no-warmup -q 2>/dev/null || true

echo "[demo] Ready. Starting server..."
exec php -S 0.0.0.0:8080 -t public
```

### 4. `infra/docker/demo/Dockerfile.frontend`

Multi-stage build:
- Stage 1 (build): `node:20-alpine`, `npm ci`, `npm run build`
- Stage 2 (serve): `nginx:alpine`, copy build output + nginx config

### 5. `infra/docker/demo/nginx-demo.conf`

```nginx
server {
    listen 80;
    root /usr/share/nginx/html;
    index index.html;

    # API proxy to backend
    location /api/ {
        proxy_pass http://backend:8080/api/;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    # SPA fallback
    location / {
        try_files $uri $uri/ /index.html;
    }
}
```

### 6. `docs/DEMO.md`

Documentation for the online demo:
- URL, credentials, what works, what doesn't
- How to update: merge main → demo → push
- How to reset data
- Railway configuration reference

## Files to modify

| File | Change |
|------|--------|
| `Makefile` | Add `demo-up` (local test) and `demo-down` targets |
| `.gitignore` | Ensure `docker-compose.demo.yml` is NOT ignored |

## Acceptance criteria

1. `docker compose -f docker-compose.demo.yml up` works locally — all 4 services start, DB auto-seeded, dashboard accessible at localhost:3002
2. Second `docker compose up` (after first) does NOT reseed — idempotent
3. Frontend at localhost:3002 shows dashboard with 150 conversations (no CORS errors)
4. Login works with `user@example.com` / `Un1que$trongPassword2024`
5. All screens populated: Dashboard, Conversations, IOC Explorer, Personas, Analytics, Monitoring
6. `make test` still passes (no regressions)
7. Railway deployment documented in `docs/DEMO.md`

## Out of scope

- Railway account setup (done manually once via UI)
- Railway environment variable configuration (documented, done once)
- n8n, Vault, scheduler containers
- Live email ingestion in demo mode
- HTTPS/domain configuration (Railway provides this automatically)
- GitHub Actions workflow (Railway native auto-deploy is sufficient)

## Estimated effort

- Dockerfile.backend + entrypoint: 2h
- Dockerfile.frontend + nginx config: 1h
- docker-compose.demo.yml: 30 min
- Local testing + debugging: 2h
- docs/DEMO.md: 30 min
- **Total: ~6h**

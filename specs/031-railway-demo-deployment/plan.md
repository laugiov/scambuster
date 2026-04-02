# 031 — Plan: Railway Demo Deployment

## Overview

Create Docker images and configuration for a self-contained demo deployment on Railway. The demo works both locally (`docker compose -f docker-compose.demo.yml up`) and on Railway (auto-deploy on push to `demo` branch).

## Step 1: Backend Dockerfile + entrypoint

Create `infra/docker/demo/Dockerfile.backend`:
- Based on existing `infra/docker/backend/Dockerfile`
- Adds `scambuster-dataset-sample.json` into the image at build time
- Adds `docker-entrypoint-demo.sh` as entrypoint
- Entrypoint: wait DB → migrations → JWT keys → seed if empty → start PHP server

Key difference from existing Dockerfile: the entrypoint handles all initialization automatically. No `make quickstart` needed.

## Step 2: Frontend Dockerfile + nginx proxy

Create `infra/docker/demo/Dockerfile.frontend`:
- Stage 1: `node:20-alpine` — `npm ci` + `npm run build` (Vite production build)
- Stage 2: `nginx:alpine` — copy `dist/` + custom `nginx-demo.conf`

Create `infra/docker/demo/nginx-demo.conf`:
- Serves static files from `/usr/share/nginx/html`
- Proxies `/api/` to `http://backend:8080/api/`
- SPA fallback: all other routes → `index.html`

The proxy approach eliminates CORS issues and avoids needing to inject the backend URL at build time.

**Important**: the `BACKEND_URL` for the nginx proxy must be configurable via environment variable for Railway (where the backend hostname differs from local). Use `envsubst` in the nginx entrypoint to template the upstream URL.

## Step 3: docker-compose.demo.yml

Minimal compose file with 4 services:
- `postgres` (15-alpine, named volume for persistence)
- `redis` (7-alpine)
- `backend` (Dockerfile.backend, all env vars set for demo mode)
- `frontend` (Dockerfile.frontend, proxies to backend)

Frontend exposed on port 3002 (same as dev), backend on 8080.

## Step 4: Local testing

```bash
docker compose -f docker-compose.demo.yml up --build
```

Verify:
- Backend starts, waits for DB, runs migrations, loads fixtures + dataset
- Frontend builds, nginx serves, `/api` proxy works
- Dashboard at localhost:3002 shows 150 conversations
- Login works
- Second `up` (without `--build`) skips seeding

## Step 5: Makefile targets

```makefile
demo-up:    docker compose -f docker-compose.demo.yml up -d --build
demo-down:  docker compose -f docker-compose.demo.yml down
demo-reset: docker compose -f docker-compose.demo.yml down -v && demo-up
```

## Step 6: Documentation (docs/DEMO.md)

- Railway setup guide (one-time)
- Demo URL and credentials
- Update workflow (merge main → demo → push)
- Data reset procedure
- What works vs what doesn't

## Step 7: Create `demo` branch

```bash
git checkout -b demo
git push origin demo
```

This branch is what Railway watches. Updates to the demo = merge main into demo + push.

## Execution order

```
Step 1 (backend Dockerfile + entrypoint)
  → Step 2 (frontend Dockerfile + nginx)
    → Step 3 (compose file)
      → Step 4 (local test)
        → Step 5 (Makefile)
          → Step 6 (docs)
            → Step 7 (demo branch)
```

Sequential — each step depends on the previous for testing.

## Risk mitigation

- **Railway free tier limits**: 500h execution/month. 4 services × 24h × 30 days = 2880h. This exceeds free tier. Mitigation: Railway $5/month hobby plan covers this. Or: stop the demo when not presenting (Railway supports manual start/stop).
- **Dataset staleness**: if fixtures change on main, the demo DB may have stale data. Mitigation: the entrypoint detects schema changes via migrations. For data changes: manually trigger reseed via `DEMO_FORCE_RESEED=true`.
- **DB persistence across deploys**: Railway PostgreSQL plugin persists data. This is desired (no re-seed on every deploy). If not desired: use an ephemeral DB (container, not plugin).

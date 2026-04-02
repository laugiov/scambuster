# 031 — Tasks: Railway Demo Deployment

## Task 1: Backend Dockerfile
- [ ] Create `infra/docker/demo/Dockerfile.backend`
- [ ] Multi-stage build based on existing `infra/docker/backend/Dockerfile`
- [ ] Copy `scambuster-dataset-sample.json` into image at `/app/scambuster-dataset-sample.json`
- [ ] Copy `docker-entrypoint-demo.sh` into image
- [ ] Set entrypoint to `docker-entrypoint-demo.sh`
- [ ] Ensure composer install includes dev dependencies (fixtures need them)

## Task 2: Backend entrypoint script
- [ ] Create `infra/docker/demo/docker-entrypoint-demo.sh`
- [ ] Wait for PostgreSQL with retry loop
- [ ] Run `doctrine:migrations:migrate --no-interaction --allow-no-migration`
- [ ] Generate JWT keys if `config/jwt/private.pem` missing
- [ ] Check if `conversation` table is empty
- [ ] If empty: run `doctrine:fixtures:load --no-interaction` then `scambuster:demo:load`
- [ ] If not empty: skip seeding
- [ ] Support `DEMO_FORCE_RESEED=true` env var to force re-seed
- [ ] Clear Symfony cache
- [ ] Start PHP built-in server on 0.0.0.0:8080
- [ ] Make script executable (`chmod +x`)

## Task 3: Frontend Dockerfile
- [ ] Create `infra/docker/demo/Dockerfile.frontend`
- [ ] Stage 1: `node:20-alpine`, copy `package*.json`, `npm ci`, copy source, `npm run build`
- [ ] Stage 2: `nginx:alpine`, copy `dist/` to `/usr/share/nginx/html`
- [ ] Copy `nginx-demo.conf` as nginx config template
- [ ] Entrypoint: `envsubst` to inject `BACKEND_URL` into nginx config, then start nginx

## Task 4: Nginx config
- [ ] Create `infra/docker/demo/nginx-demo.conf`
- [ ] Proxy `/api/` to `${BACKEND_URL}/api/`
- [ ] Serve static files from `/usr/share/nginx/html`
- [ ] SPA fallback: `try_files $uri $uri/ /index.html`
- [ ] Gzip compression for JS/CSS
- [ ] Cache headers for static assets

## Task 5: docker-compose.demo.yml
- [ ] Create `docker-compose.demo.yml` at repo root
- [ ] 4 services: postgres, redis, backend, frontend
- [ ] All environment variables for demo mode (LLM_PROVIDER=mock, MAILER_DSN=null, etc.)
- [ ] Named volume for postgres data persistence
- [ ] Frontend exposed on port 3002, backend on 8080
- [ ] Network configuration for inter-service communication

## Task 6: Local testing
- [ ] `docker compose -f docker-compose.demo.yml up --build` — all services start
- [ ] Backend auto-seeds on first start (verify logs)
- [ ] Frontend loads at http://localhost:3002
- [ ] Login works with demo credentials
- [ ] Dashboard shows 150 conversations, 876 IOCs
- [ ] All screens populated (Conversations, IOC Explorer, Personas, Analytics, Monitoring)
- [ ] No CORS errors in browser console
- [ ] Second `up` skips seeding (verify "already has N conversations" in logs)
- [ ] `docker compose -f docker-compose.demo.yml down -v` + `up` triggers re-seed

## Task 7: Makefile targets
- [ ] Add `demo-up` target: `docker compose -f docker-compose.demo.yml up -d --build`
- [ ] Add `demo-down` target: `docker compose -f docker-compose.demo.yml down`
- [ ] Add `demo-reset` target: `down -v` then `up --build`
- [ ] Add `demo-logs` target: `docker compose -f docker-compose.demo.yml logs -f`

## Task 8: Documentation
- [ ] Create `docs/DEMO.md`
- [ ] Section: Online demo URL + credentials
- [ ] Section: What works in demo mode (list all screens)
- [ ] Section: What doesn't work (email ingestion, n8n workflows, LLM generation)
- [ ] Section: Run demo locally (`docker compose -f docker-compose.demo.yml up`)
- [ ] Section: Railway setup guide (one-time manual steps)
- [ ] Section: How to update the demo (merge main → demo → push)
- [ ] Section: How to reset demo data

## Task 9: Verify no regressions
- [ ] `make test` — all backend tests pass
- [ ] `make front-check` — frontend builds cleanly
- [ ] Existing `make quickstart` still works (docker-compose.demo.yml doesn't interfere)

## Task 10: Create demo branch + commit
- [ ] Commit all new files to main
- [ ] Create `demo` branch from main
- [ ] Push `demo` branch to origin
- [ ] Verify branch exists on GitHub

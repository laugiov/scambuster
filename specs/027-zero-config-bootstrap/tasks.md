# Tasks: Zero-Config Docker Bootstrap

**Input**: spec.md, plan.md
**Depends on**: 025 (merged), 026 (merged)
**Tests**: Idempotency tests, make doctor exit codes, manual fresh deploy test

## Phase 1: Setup

- [ ] T001 Add N8N_ENCRYPTION_KEY to .env.dist with openssl rand -hex 32 generation instruction and WARNING comment about persistence
- [ ] T002 Add N8N_DEFAULT_USER_EMAIL and N8N_DEFAULT_USER_PASSWORD to .env.dist with defaults (admin@scambuster.local / change-me-on-first-login)
- [ ] T003 Add SETUP_WIZARD_ENABLED=true to .env.dist (reserved for future use)

## Phase 2: US1 — n8n Init Script (P1)

**Goal**: Auto-import workflows on first Docker boot via Architecture B (background n8n + REST API)

- [ ] T004 [US1] Create n8n/n8n-init.sh: Architecture B entrypoint script
  - Start n8n in background (n8n start &)
  - Wait for DB readiness (retry loop: n8n list:workflow, 3s intervals, max 20 retries)
  - Wait for n8n HTTP readiness (curl /healthz, max 60s)
  - Import workflows (n8n import:workflow --separate --input=/home/node/init-workflows/)
  - Activate 4 production workflows by name (parse n8n list:workflow or REST API, activate per-ID)
  - Trap SIGTERM + SIGINT → kill n8n PID
  - Wait on n8n PID (main process)
  - All output prefixed with [n8n-init], timestamped
- [ ] T005 [US1] Make n8n-init.sh executable (chmod +x)
- [ ] T006 [US1] Update docker-compose.yml n8n service:
  - entrypoint: ["/bin/sh", "/home/node/n8n-init.sh"]
  - volumes: ./n8n/n8n-init.sh:/home/node/n8n-init.sh:ro
  - volumes: ./n8n/workflows:/home/node/init-workflows:ro
  - Forward N8N_DEFAULT_USER_EMAIL, N8N_DEFAULT_USER_PASSWORD, N8N_ENCRYPTION_KEY
- [ ] T007 [US1] Implement idempotency: check n8n list:workflow output, skip import for workflows whose names already exist
- [ ] T008 [US1] Implement per-name activation: parse workflow names from n8n list:workflow or GET /rest/workflows, activate only WF-INTAKE-EMAIL-V2, WF-REPLY-GENERATE-V2, WF-REPLY-SEND-v1, WF-EXTRACT-AND-ENRICH-IOC (NOT simulator workflows)

## Phase 3: US2 — Credential Seeding via REST API (P1)

**Goal**: Create IMAP credential in n8n via REST API from env vars

- [ ] T009 [US2] Add REST API authentication to n8n-init.sh: POST /rest/login with N8N_DEFAULT_USER_EMAIL/PASSWORD → extract Bearer token
- [ ] T010 [US2] Add credential creation to n8n-init.sh: if HONEYPOT_IMAP_HOST is set, build JSON payload with jq (type: "imap", name: "ScamBuster IMAP", data: {host, port, user, password, secure}), POST /rest/credentials
- [ ] T011 [US2] Add idempotency check: GET /rest/credentials, filter by name "ScamBuster IMAP", skip creation if exists
- [ ] T012 [US2] Add credential type verification: GET /rest/credentials/schema/imap — if 404, try alternative types (imapEmail, emailImapAccount)
- [ ] T013 [US2] Handle missing IMAP vars: log warning "[n8n-init] IMAP credentials not configured — email workflows will fail at execution time" but do NOT fail

## Phase 4: US3 — make doctor (P2)

**Goal**: CLI health check script

- [ ] T014 [US3] Create scripts/doctor.sh: check required env vars (POSTGRES_PASSWORD, JWT_PASSPHRASE, LLM_API_KEY, HONEYPOT_IMAP_HOST, HONEYPOT_IMAP_USER, MAILER_DSN, N8N_ENCRYPTION_KEY), detect placeholders
- [ ] T015 [US3] Add connectivity checks: curl backend API, pg_isready for PostgreSQL, redis-cli ping, curl n8n /healthz
- [ ] T016 [US3] Add n8n workflow checks: call n8n REST API GET /rest/workflows, verify 4 production workflows are imported + active, 2 simulators are imported + inactive
- [ ] T017 [US3] Add optional var checks: VIRUSTOTAL_API_KEY, URLSCAN_API_KEY — show grey, not red
- [ ] T018 [US3] Output formatting: color-coded (green ✅ / red ❌ / amber ⚠️ / grey ⬜), structured sections (REQUIRED, CONNECTIVITY, n8n WORKFLOWS, OPTIONAL), exit 0 if all required pass, exit 1 otherwise
- [ ] T019 [US3] Add make doctor target in Makefile

## Phase 5: US4 — Documentation (P3)

- [ ] T020 [US4] Update docs/08_getting_started.md: replace manual n8n import section with "Workflows are auto-imported on first boot. Configure .env and run docker compose up."
- [ ] T021 [US4] Update n8n/README.md: document the bootstrap process, env vars needed, how n8n-init.sh works, how to override/customize
- [ ] T022 [US4] Add "Quickstart" section to README.md: 5-step guide (clone → .env → docker compose up → make doctor → open browser)
- [ ] T023 [US4] Document rollback procedure: "To restore original workflows, copy from n8n/workflows-backup-pre-025/ and restart n8n"

## Phase 6: Validation & Polish

- [ ] T024 Test fresh deploy: rm -rf data/n8n, docker compose up, verify 4 workflows active, IMAP credential created
- [ ] T025 Test idempotency: docker compose down && docker compose up, verify no duplicate workflows, no duplicate credentials
- [ ] T026 Test missing env vars: unset HONEYPOT_IMAP_HOST, docker compose up, verify n8n starts with warning (not crash)
- [ ] T027 Test make doctor: verify exit 0 when all configured, exit 1 when required var missing
- [ ] T028 Test signal handling: docker compose stop, verify n8n shuts down gracefully (no SIGKILL)

---

## Dependencies

- Phase 1 (Setup): no dependencies
- Phase 2 (Init script): depends on T001-T003
- Phase 3 (Credentials): depends on Phase 2 (n8n must be running)
- Phase 4 (Doctor): independent of Phase 2/3
- Phase 5 (Docs): depends on all phases
- Phase 6 (Validation): depends on all phases

## Notes

- n8n-init.sh is mounted as a volume, NOT baked into the Docker image — we use the official n8n image unchanged
- jq is required in the n8n container for safe JSON building — verify it's present in the official image
- N8N_ENCRYPTION_KEY is THE most critical env var — without it, credentials become unreadable on container restart
- The REST API approach for credential seeding is more reliable than CREDENTIALS_OVERWRITE_DATA (which is an Embed feature)

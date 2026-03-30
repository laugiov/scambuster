# Feature Specification: Zero-Config Docker Bootstrap -- Auto-Import, Credential Seeding, Setup Wizard

**Feature Branch**: `027-zero-config-bootstrap`
**Created**: 2026-03-30
**Status**: Draft
**Depends on**: 025-n8n-workflow-hardening, 026-provider-agnostic-email
**Input**: User description: "Eliminate all manual n8n UI interaction from the first-boot experience. A fresh `git clone && docker compose up` should yield a fully wired system in under 5 minutes, with a setup wizard showing what is configured and what is missing."

## User Scenarios & Testing *(mandatory)*

### User Story 1 -- Auto-Import n8n Workflows on First Boot (Priority: P1)

As a developer or operator, I want n8n workflows to be automatically imported the first time I run `docker compose up`, so that I never have to manually open the n8n UI, click "Import from File", and repeat this for each of the 6 workflow JSONs.

Today, Section 5 of `docs/08_getting_started.md` documents a fully manual process: open browser, navigate to n8n, import each JSON one by one, configure credentials, activate. This is error-prone and the single biggest friction point in onboarding.

**Why this priority**: Without workflows, the entire pipeline (email ingestion, reply generation, IOC extraction) is dead. This is the minimum viable improvement -- everything else builds on top of working workflows.

**Independent Test**: Can be fully tested by running `docker compose up` on a fresh clone (no `data/n8n/` directory) and verifying that `n8n list:workflow` returns all 6 workflows. Delivers immediate value: zero manual n8n interaction for the core pipeline.

**Acceptance Scenarios**:

1. **Given** a fresh git clone with no `data/n8n/` directory, **When** the operator runs `docker compose up`, **Then** all 6 workflow JSONs from `n8n/workflows/` are imported into n8n and appear in `n8n list:workflow` output.
2. **Given** workflows were already imported (container restart or `docker compose down && up`), **When** n8n starts again, **Then** the init script detects existing workflows and skips import (no duplicates created).
3. **Given** a new workflow JSON is added to `n8n/workflows/` after initial import, **When** the container restarts, **Then** only the new workflow is imported; existing workflows are not duplicated or overwritten.
4. **Given** the init script runs successfully, **When** all workflows are imported, **Then** the 4 production workflows (WF-INTAKE-EMAIL-V2, WF-REPLY-GENERATE-V2, WF-REPLY-SEND-v1, WF-EXTRACT-AND-ENRICH-IOC) are activated individually by name (via `n8n list:workflow` to get IDs, then `n8n update:workflow --id=<ID> --active=true` for each). The 2 simulator workflows remain inactive.
5. **Given** the `n8n/workflows/` directory is mounted read-only in the container, **When** the init script runs, **Then** it reads the JSONs without attempting to write to the mounted volume.

---

### User Story 2 -- Credential Seeding from Environment Variables (Priority: P1)

As a developer or operator, I want n8n credentials (IMAP host/user/password, SMTP host/user/password, backend API URL/JWT) to be automatically injected from environment variables, so that I never have to manually configure credentials in the n8n UI.

Today, after importing workflows, the operator must open each workflow, find the credential nodes, create new credentials, and type in connection details. This is undocumented, fragile, and blocks automated deployment.

**Why this priority**: Imported workflows are useless without credentials. This story is co-equal with US1 because together they deliver the "zero UI interaction" promise.

**Independent Test**: Set `HONEYPOT_IMAP_*` env vars in `.env`, run `docker compose up`, and verify that n8n workflows can execute without manual credential entry. Trigger WF-INTAKE-EMAIL-V2 manually and confirm it attempts an IMAP connection (even if it fails due to wrong credentials, the attempt proves injection worked).

**Architecture Decision — REST API post-startup (not CREDENTIALS_OVERWRITE_DATA)**:

`CREDENTIALS_OVERWRITE_DATA` is documented under "n8n Embed" (the paid white-label product), not the self-hosted Docker image. Community reports indicate it is ignored in standard Docker deployments. Instead, the init script uses the n8n REST API after n8n is running:

```
Architecture B — Background n8n + REST API seeding:

1. n8n start &          (start n8n in background)
2. N8N_PID=$!
3. wait_for_n8n_ready   (loop on GET /healthz, max 60s)
4. wait_for_db_ready    (loop on n8n list:workflow, catches DB not ready)
5. authenticate          (POST /rest/login → get Bearer token)
6. check_existing_creds  (GET /rest/credentials → skip if already exists)
7. create_imap_cred     (POST /rest/credentials type=imap, data from env)
8. import_workflows      (n8n import:workflow --separate --input=/workflows/)
9. activate_production   (per-name activation, NOT --all --active=true)
10. trap "kill $N8N_PID; wait $N8N_PID" SIGTERM SIGINT  (relay Docker + Ctrl+C signals)
11. wait $N8N_PID        (n8n runs as main process, script exits with n8n's exit code)
```

This approach is reliable, testable, and does not depend on undocumented Embed features.

**Acceptance Scenarios**:

1. **Given** the operator has set `HONEYPOT_IMAP_HOST`, `HONEYPOT_IMAP_PORT`, `HONEYPOT_IMAP_USER`, `HONEYPOT_IMAP_PASSWORD`, `HONEYPOT_IMAP_SECURE` in `.env`, **When** the n8n container starts, **Then** the init script creates an IMAP credential via the n8n REST API (`POST /rest/credentials` with type `imap`).
2. **Given** IMAP env vars are missing, **When** the init script runs, **Then** it logs a warning "IMAP credentials not configured" but does not fail — n8n starts normally and workflows fail with clear credential errors at execution time.
3. **Given** the init script runs on a container restart (credentials already exist), **When** it checks `GET /rest/credentials`, **Then** it skips creation (idempotent) — no duplicate credentials are created.
4. **Given** `N8N_DEFAULT_USER_EMAIL` and `N8N_DEFAULT_USER_PASSWORD` are set in `.env`, **When** the init script authenticates via `POST /rest/login`, **Then** it obtains a Bearer token for subsequent API calls.
5. **Given** the n8n database is not yet ready (PostgreSQL still starting), **When** the init script tries `n8n list:workflow`, **Then** it retries with 3-second intervals (max 20 retries / 60 seconds) before failing.

---

### User Story 3 -- `make doctor` Health Check Script (Priority: P2)

As a first-time operator, I want a `make doctor` command that validates my environment and shows at a glance which components are properly configured and which are missing, so I can diagnose and fix my installation from the terminal.

**Why this priority**: After US1+US2, the system should "just work" for the happy path. `make doctor` is a quality-of-life diagnostic for when things go wrong. A CLI tool is preferred over a React wizard for the target audience (CERT/SOC admins) — it's scriptable, works headless over SSH, and has zero frontend dependencies.

**Independent Test**: Run `make doctor` and verify it reports correct status for all checks. Remove an env var and re-run — the corresponding check should fail.

**Output format**:
```
[ScamBuster Doctor] Checking environment...

REQUIRED
  ✅ POSTGRES_PASSWORD       set
  ✅ JWT_PASSPHRASE          set
  ✅ LLM_API_KEY             set (not placeholder)
  ✅ HONEYPOT_IMAP_HOST      imap.gmail.com
  ✅ HONEYPOT_IMAP_USER      set
  ✅ MAILER_DSN              set
  ✅ N8N_ENCRYPTION_KEY      set (not placeholder)

CONNECTIVITY
  ✅ Backend API             http://backend-dev:8080 → 200 OK
  ✅ PostgreSQL              connected
  ✅ Redis                   connected
  ✅ n8n                     http://n8n:5678 → healthy

n8n WORKFLOWS
  ✅ WF-INTAKE-EMAIL-V2      imported, active
  ✅ WF-REPLY-GENERATE-V2   imported, active
  ✅ WF-REPLY-SEND-v1        imported, active
  ✅ WF-EXTRACT-AND-ENRICH   imported, active
  ⬜  WF-SIMULATOR-*         imported, inactive (expected)

OPTIONAL
  ⬜  VIRUSTOTAL_API_KEY     not configured
  ⬜  URLSCAN_API_KEY        not configured

[ScamBuster Doctor] 0 errors, 0 warnings. System ready.
```

**Acceptance Scenarios**:

1. **Given** all required env vars are set and services are running, **When** `make doctor` executes, **Then** it exits with code 0 and all checks show green.
2. **Given** `HONEYPOT_IMAP_HOST` is missing, **When** `make doctor` executes, **Then** it shows a red check with "HONEYPOT_IMAP_HOST: not set — required for email ingestion" and exits with code 1.
3. **Given** `LLM_API_KEY=sk-your-api-key-here` (placeholder), **When** `make doctor` executes, **Then** it shows amber/warning "LLM_API_KEY appears to be a placeholder".
4. **Given** n8n is not running, **When** `make doctor` executes, **Then** it shows red for n8n connectivity and grey for workflow checks (cannot determine without n8n).
5. **Given** optional vars (VIRUSTOTAL, URLSCAN) are not set, **Then** they show grey, not red — they are optional.
6. **Given** `make doctor` is run in CI (non-interactive), **Then** it exits 0/1 based on required checks only — usable as a CI gate.

---

### User Story 4 -- Documentation Update (Priority: P3)

As a new contributor or operator, I want the getting-started documentation to reflect the zero-config bootstrap flow, so that the docs match reality and I am not confused by references to manual steps that are now automated.

**Why this priority**: Documentation follows implementation. It has no functional impact on the system but is essential for maintainability and onboarding.

**Independent Test**: Can be tested by reading the updated docs and following them step by step on a fresh clone. If the documented steps produce a working system, the test passes.

**Acceptance Scenarios**:

1. **Given** US1 and US2 are implemented, **When** the operator reads `docs/08_getting_started.md`, **Then** Section 5 ("Set Up n8n Workflows") is updated to reflect that workflows are auto-imported and credentials are seeded from env vars, with a note on how to override or customize.
2. **Given** the operator wants to deploy to production, **When** they read `docs/16_deployment_guide.md`, **Then** they find a production checklist covering: required env vars, TLS termination, n8n encryption key rotation, backup strategy, monitoring endpoints.
3. **Given** the README quickstart section exists, **When** the operator reads it, **Then** the "5-minute quickstart" flow is: `git clone`, `cp .env.dist .env`, edit 4 required vars, `make build && make upd`, `make migration && make fixtures-dev`, open browser.

---

### Edge Cases

- **What happens when `n8n/workflows/` contains a malformed JSON file?** The init script logs an error for that file and continues importing the remaining valid workflows. It exits with a non-zero code only if zero workflows were imported successfully.
- **What happens when n8n's SQLite database is corrupted?** The init script cannot run `n8n list:workflow`. It logs the error, skips the idempotency check, and attempts a fresh import. If import also fails, it logs a fatal error and n8n starts without workflows (operator must investigate).
- **What happens when the operator downgrades n8n to an older version?** Workflow JSON format may be incompatible. The init script logs import errors per file. The setup wizard (US3) shows "N workflows imported, M failed" with the error details.
- **What happens when the operator has manually pre-configured credentials in n8n UI?** The idempotency check (`GET /rest/credentials` filtered by name "ScamBuster IMAP") detects the existing credential and skips creation. The operator's manual configuration takes precedence.
- **What happens when the same workflow is imported twice (e.g., operator manually imports before auto-import)?** The idempotency check compares workflow names from `n8n list:workflow`. If a workflow with the same name already exists, it is skipped. If the operator imported under a different name, a duplicate is created -- this is acceptable and documented.
- **What happens when the init script is killed mid-import (e.g., `docker compose down` during boot)?** On next start, `n8n list:workflow` shows partially imported workflows. The init script detects which are missing and imports only those.
- **What happens when `HONEYPOT_IMAP_PASSWORD` contains special characters (quotes, backslashes)?** The init script uses proper JSON encoding (e.g., `jq` for building the JSON) to handle special characters correctly. Raw string interpolation in shell is explicitly avoided.
- **What happens when the setup wizard's backend health check endpoint is slow or times out?** Each check has a 5-second timeout. Timed-out checks show amber with "Health check timed out -- service may be starting up". The page provides a "Retry All" button.
- **What happens on a system with no internet access (air-gapped)?** All workflow JSONs are bundled in the repository. The init script does not fetch anything from the network. The only network dependency is pulling Docker images, which must be done beforehand or via a private registry.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: System MUST provide a custom Docker entrypoint script (`n8n-init.sh`) that runs before the n8n process starts.
- **FR-002**: The init script MUST import all `.json` files from the `n8n/workflows/` directory using `n8n import:workflow --separate --input=/workflows/`.
- **FR-003**: The init script MUST be idempotent: it MUST check `n8n list:workflow` output and skip import for workflows whose names already exist in n8n's database.
- **FR-004**: The init script MUST activate the 4 production workflows individually by name — NOT using `--all --active=true` (which would also activate the 2 simulator workflows). The script must: parse `n8n list:workflow` output to find workflow IDs by name, then call `n8n update:workflow --id=<ID> --active=true` for each production workflow. The 2 simulator workflows MUST remain inactive. **Note on output format**: prefer the n8n REST API (`GET /rest/workflows`) which returns JSON, over the CLI `n8n list:workflow` which returns tabular text that is fragile to parse. If using the CLI, pipe through `grep` + `awk` with exact name matching to handle workflows with spaces in names.
- **FR-005**: The `n8n/workflows/` directory MUST be mounted read-only (`:ro`) in the n8n container via `docker-compose.yml`.
- **FR-006**: The init script MUST create IMAP credentials via the n8n REST API (`POST /rest/credentials`) after n8n is running, using `HONEYPOT_IMAP_*` env vars. It MUST NOT use `CREDENTIALS_OVERWRITE_DATA` (unreliable in self-hosted Docker). SMTP credentials are not needed in n8n (sending is handled by the backend via Symfony Mailer).
- **FR-007**: The init script MUST use `jq` (or equivalent safe JSON builder) to construct credential payloads for the REST API, never raw shell string concatenation. This prevents special characters in passwords from breaking JSON.
- **FR-008**: The init script MUST log warnings (not errors) when IMAP env vars are missing, and MUST NOT prevent n8n from starting. Workflows will fail at execution time with clear credential errors.
- **FR-009**: The `.env.dist` template MUST include `N8N_DEFAULT_USER_EMAIL` and `N8N_DEFAULT_USER_PASSWORD` for the init script to authenticate against the n8n REST API.
- **FR-010**: The init script MUST check for existing credentials before creating new ones (`GET /rest/credentials` filtered by name) to ensure idempotency — no duplicate credentials on container restart.
- **FR-011**: The init script MUST wait for n8n database readiness before attempting imports (retry loop on `n8n list:workflow` with 3-second intervals, max 20 retries).
- **FR-012**: A `scripts/doctor.sh` script MUST validate all required env vars, service connectivity, and n8n workflow status.
- **FR-013**: The doctor script MUST be callable via `make doctor` from the project root.
- **FR-014**: The doctor script MUST check: required env vars (presence + not-placeholder), backend API connectivity, PostgreSQL connectivity, Redis connectivity, n8n connectivity, n8n workflow import/activation status.
- **FR-015**: The doctor script MUST output structured, color-coded results (green/red/amber/grey) with actionable remediation (env var name + what to set).
- **FR-016**: The doctor script MUST exit 0 if all required checks pass, exit 1 if any required check fails. Optional checks (VIRUSTOTAL, URLSCAN) do not affect exit code.
- **FR-017**: The `docker-compose.yml` n8n service MUST be updated to use the custom entrypoint, mount workflows read-only, and pass through the new env vars.
- **FR-018**: The init script MUST produce structured log output (timestamped, prefixed with `[n8n-init]`) for all operations, warnings, and errors to stdout.
- **FR-019**: `N8N_ENCRYPTION_KEY` MUST be included in `.env.dist` with a placeholder value and an explicit warning: "Generate with `openssl rand -hex 32`. This key encrypts credentials in n8n's database. It MUST remain constant across container restarts — changing it makes all stored credentials unreadable." Without a fixed encryption key, credentials created by the bootstrap script become unreadable after `docker compose down && docker compose up`.

### Key Entities

- **HealthCheck**: A subsystem health probe result containing: name (string), status (enum: healthy/degraded/unhealthy/unknown/optional), message (string), env_vars (string[]), doc_link (string|null). Not persisted -- computed on each request.
- **n8n REST API Credentials**: IMAP credentials created via `POST /rest/credentials` with type `imap` (verified: the `emailReadImap` node declares `credentials: [{name: "imap", required: true}]` — confirmed via n8n MCP node inspection), data `{host, port, user, password, secure}`. Persisted in n8n's credential store (encrypted with `N8N_ENCRYPTION_KEY`). The init script authenticates via `POST /rest/login` using `N8N_DEFAULT_USER_EMAIL`/`N8N_DEFAULT_USER_PASSWORD`. **Note**: the credential type name may vary across n8n versions — the init script should verify via `GET /rest/credentials/schema/imap` and fall back to common alternatives (`imapEmail`, `emailImapAccount`) if not found.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A fresh `git clone` followed by `cp .env.dist .env` (with 4 required vars filled: `POSTGRES_PASSWORD`, `DATABASE_URL`, `JWT_PASSPHRASE`, `LLM_API_KEY`) and `docker compose up` results in a system with all 4 production n8n workflows imported and active within 5 minutes, with zero manual n8n UI interaction.
- **SC-002**: Running `docker compose down && docker compose up` (container restart) does not create duplicate workflows. `n8n list:workflow` shows exactly the same count before and after.
- **SC-003**: When `HONEYPOT_IMAP_*` env vars are set, the n8n IMAP trigger workflow can attempt email polling without manual credential configuration in the n8n UI. Email sending is handled by the backend via `MAILER_DSN` (spec 026) — no SMTP credentials in n8n.
- **SC-004**: `make doctor` completes in under 10 seconds and correctly reports the status of all subsystems (env vars, backend, database, redis, n8n, workflows, optional integrations).
- **SC-005**: An operator following only the updated `docs/08_getting_started.md` can go from zero to a working system (backend responding, workflows active, setup wizard all-green for required checks) in under 10 minutes.
- **SC-006**: The init script handles all edge cases (missing env vars, pre-existing workflows, malformed JSON, special characters in passwords) without crashing or leaving n8n in an unrecoverable state.

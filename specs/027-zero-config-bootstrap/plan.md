# Implementation Plan: Zero-Config Docker Bootstrap

**Branch**: `027-zero-config-bootstrap` | **Date**: 2026-03-30 | **Spec**: [spec.md](spec.md)
**Depends on**: 025-n8n-workflow-hardening, 026-provider-agnostic-email

## Summary

Auto-import n8n workflows on first Docker boot, seed IMAP credentials via n8n REST API, provide a `make doctor` diagnostic CLI. Target: `git clone → edit .env → docker compose up → it works`.

## Technical Context

**Language/Version**: Shell (bash, init script + doctor script), n8n CLI + REST API
**Primary Dependencies**: n8n CLI (`import:workflow`, `list:workflow`, `update:workflow`), n8n REST API (`/rest/login`, `/rest/credentials`, `/rest/workflows`), `jq` (JSON building), `curl` (connectivity checks)
**Storage**: n8n SQLite/PostgreSQL (credential store, encrypted with N8N_ENCRYPTION_KEY)
**Testing**: Idempotency tests (restart container), make doctor exit codes, grep validation
**Constraints**: Use official n8n Docker image (no custom Dockerfile), mount scripts via volumes, n8n-init.sh as entrypoint

## Constitution Check

| Gate | Status | Notes |
|------|--------|-------|
| I. DDD layers | N/A | No PHP code |
| II. Hexagonal ports | N/A | No PHP code |
| III. Test-driven quality | PASS | Idempotency tests + make doctor as CI gate |
| IV. Safety & Ethics | PASS | Credentials encrypted via N8N_ENCRYPTION_KEY |
| V. Cost awareness | N/A | No LLM calls |
| VI. Internationalization | N/A | No frontend |
| VII. Simplicity | PASS | ~80 lines of shell, no new abstractions |

## Project Structure

```text
n8n/
├── n8n-init.sh                              # NEW: Docker entrypoint init script
├── workflows/                               # EXISTING: mounted read-only in container
│   └── *.json
└── README.md                                # MODIFY: document auto-import + bootstrap

scripts/
└── doctor.sh                                # NEW: make doctor health check script

.env.dist                                    # MODIFY: add N8N_DEFAULT_USER_*, N8N_ENCRYPTION_KEY
docker-compose.yml                           # MODIFY: custom entrypoint, volume mounts
Makefile                                     # MODIFY: add make doctor target
docs/08_getting_started.md                   # MODIFY: updated quickstart
```

## Strategy

1. Create n8n-init.sh (Architecture B: background n8n + REST API seeding)
2. Update docker-compose.yml (entrypoint, volumes, env vars)
3. Update .env.dist (N8N_ENCRYPTION_KEY, N8N_DEFAULT_USER_*)
4. Create scripts/doctor.sh
5. Add make doctor to Makefile
6. Update documentation
7. Test idempotency: docker compose down && docker compose up
8. Test fresh deploy: rm -rf data/n8n && docker compose up

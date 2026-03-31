# Implementation Plan: n8n Workflow Hardening

**Branch**: `025-n8n-workflow-hardening` | **Date**: 2026-03-30 | **Spec**: [spec.md](spec.md)

## Summary

Replace all hardcoded values in n8n workflow JSONs with environment variable references. 11 backend URLs → `$env.SCAMBUSTER_API_URL`, 2 plaintext passwords → `$env.INGEST_LOGIN/PASSWORD`, 3 workflow ID references → name-based resolution. Also add env vars to `.env.dist` and `docker-compose.yml`.

## Technical Context

**Language/Version**: n8n workflow JSON (declarative), shell (validation)
**Primary Dependencies**: n8n expression engine (`$env.*`), n8n executeWorkflow name resolution
**Storage**: N/A (JSON file editing only)
**Testing**: grep-based CI tests + `jq` JSON validation + manual smoke test
**Target Platform**: Docker (n8n container)
**Project Type**: Configuration/infrastructure change
**Constraints**: Zero backend PHP changes, zero frontend changes, zero SQL changes

## Constitution Check

| Gate | Status | Notes |
|------|--------|-------|
| I. DDD layers | N/A | No PHP code changed |
| II. Hexagonal ports | N/A | No PHP code changed |
| III. Test-driven quality | PASS | grep + jq validation tests in CI |
| IV. Safety & Ethics | PASS | Removes plaintext credentials (security improvement) |
| V. Cost awareness | N/A | No LLM calls |
| VI. Internationalization | N/A | No frontend changes |
| VII. Simplicity | PASS | Pure search/replace, no new abstractions |

## Project Structure

### Source Code

```text
n8n/
├── workflows/
│   ├── WF-INTAKE-EMAIL-V2.json          # MODIFY: 3 URLs + 1 password + 2 workflow IDs
│   ├── WF-REPLY-GENERATE-V2.json        # MODIFY: 4 URLs + 1 workflow ID
│   ├── WF-REPLY-SEND-v1.json            # MODIFY: 4 URLs + 1 password
│   └── WF-EXTRACT-AND-ENRICH-IOC.json   # MODIFY: 3 URLs
├── workflows-backup-pre-025/            # BACKUP: original JSONs before changes
│   └── *.json
└── README.md                            # MODIFY: document env var approach

.env.dist                                # MODIFY: add SCAMBUSTER_API_URL
docker-compose.yml                       # MODIFY: forward 3 env vars to n8n service
```

## Strategy

1. Backup workflows (done)
2. Replace URLs in all 4 JSONs (4 static → expression conversion, 7 expression → value swap)
3. Replace passwords in 2 JSONs
4. Replace workflow IDs in 3 nodes (mode: list → mode: name)
5. Update `.env.dist` and `docker-compose.yml`
6. Validate with grep + jq
7. Document in n8n/README.md
8. Manual smoke test

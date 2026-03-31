# Feature Specification: n8n Workflow Hardening -- Replace All Hardcoded Values with Environment Variables

**Feature Branch**: `025-n8n-workflow-hardening`
**Created**: 2026-03-30
**Status**: Draft
**Input**: User description: "Replace all hardcoded backend URLs, plaintext credentials, and workflow ID references in n8n workflow JSON files with environment variable references and name-based workflow lookups."

## User Scenarios & Testing *(mandatory)*

### User Story 1 -- Backend URL Centralization (Priority: P1)

As an operator deploying ScamBuster to a new environment (preprod, prod, or a colleague's dev machine), I need all 11 hardcoded `http://backend-dev:8080` URLs across the four n8n workflows replaced with `$env.SCAMBUSTER_API_URL` so that changing a single environment variable is sufficient to point every workflow at the correct backend.

**Why this priority**: This is the most impactful change. Every environment (dev, preprod, prod) has a different backend hostname and port. Today, moving to a new environment requires manually editing 14 URL strings across 4 JSON files, which is error-prone and has caused production incidents.

**Independent Test**: Can be fully tested by setting `SCAMBUSTER_API_URL=http://backend-preprod:8082` in the n8n container environment, then triggering each workflow and verifying all HTTP requests target the correct host.

**Acceptance Scenarios**:

1. **Given** a fresh deployment with `SCAMBUSTER_API_URL=http://backend-prod:8080` set in the n8n container environment, **When** WF-INTAKE-EMAIL-V2 receives a new email, **Then** all three HTTP requests (auth/login, ingest/raw, message/risk) use `http://backend-prod:8080` as the base URL.
2. **Given** `SCAMBUSTER_API_URL` is set, **When** WF-REPLY-GENERATE-V2 executes, **Then** all four HTTP requests (auth/login, context, generate, draft) use the environment variable value.
3. **Given** `SCAMBUSTER_API_URL` is set, **When** WF-REPLY-SEND-v1 executes, **Then** all four HTTP requests (auth/login, fetch reply, compose, confirm sent) use the environment variable value.
4. **Given** `SCAMBUSTER_API_URL` is set, **When** WF-EXTRACT-AND-ENRICH-IOC executes, **Then** all three HTTP requests (auth/login, extract-iocs, enrich) use the environment variable value.
5. **Given** `SCAMBUSTER_API_URL` is **not** set, **When** any workflow attempts an HTTP request, **Then** the n8n expression evaluates to an empty or undefined base URL and the HTTP node fails with a clear error (no silent fallback to a wrong host).

#### Exact Locations -- 14 Hardcoded Backend URLs

**WF-INTAKE-EMAIL-V2.json** (3 occurrences):

| # | Line | Node Name | JSON Path | Current Value |
|---|------|-----------|-----------|---------------|
| 1 | 49 | `Retrieve Token` | `nodes[2].parameters.url` | `"http://backend-dev:8080/api/v1/auth/login"` |
| 2 | 95 | `Ingest Email` | `nodes[4].parameters.url` | `"http://backend-dev:8080/api/v1/communication/ingest/raw"` |
| 3 | 131 | `Get Risk Assessment` | `nodes[5].parameters.url` | `"={{ 'http://backend-dev:8080/api/v1/communication/message/' + ... + '/risk' }}"` |

**WF-REPLY-GENERATE-V2.json** (4 occurrences):

| # | Line | Node Name | JSON Path | Current Value |
|---|------|-----------|-----------|---------------|
| 4 | 20 | `00_auth_login` | `nodes[1].parameters.url` | `"http://backend-dev:8080/api/v1/auth/login"` |
| 5 | 46 | `10_fetch_context` | `nodes[2].parameters.url` | `"=http://backend-dev:8080/api/v1/communication/conversation/{{...}}/context"` |
| 6 | 70 | `20_generate` | `nodes[3].parameters.url` | `"=http://backend-dev:8080/api/v1/communication/reply/generate"` |
| 7 | 101 | `40_store_draft` | `nodes[4].parameters.url` | `"=http://backend-dev:8080/api/v1/communication/reply/draft"` |

**WF-REPLY-SEND-v1.json** (4 occurrences):

| # | Line | Node Name | JSON Path | Current Value |
|---|------|-----------|-----------|---------------|
| 8 | 6 | `10_fetch_reply` | `nodes[0].parameters.url` | `"=http://backend-dev:8080/api/v1/communication/reply/{{...}}"` |
| 9 | 29 | `12_fetch_compose` | `nodes[1].parameters.url` | `"=http://backend-dev:8080/api/v1/communication/reply/{{...}}/compose"` |
| 10 | 115 | `30_confirm` | `nodes[5].parameters.url` | `"=http://backend-dev:8080/api/v1/communication/reply/{{...}}/sent"` |
| 11 | 172 | `00_auth_login` | `nodes[7].parameters.url` | `"http://backend-dev:8080/api/v1/auth/login"` |

**WF-EXTRACT-AND-ENRICH-IOC.json** (3 occurrences):

| # | Line | Node Name | JSON Path | Current Value |
|---|------|-----------|-----------|---------------|
| 12 | 18 | `Retrieve Token` | `nodes[1].parameters.url` | `"http://backend-dev:8080/api/v1/auth/login"` |
| 13 | 51 | `Extract IOCs via Backend API (LLM)` | `nodes[2].parameters.url` | `"=http://backend-dev:8080/api/v1/communication/message/{{...}}/extract-iocs"` |
| 14 | 287 | `PATCH IOC Enrichment` | `nodes[13].parameters.url` | `"=http://backend-dev:8080/api/v1/iocs/{{...}}/enrich"` |

---

### User Story 2 -- Fix Hardcoded Credentials (Priority: P1)

As a security-conscious operator, I need the two workflow files that contain plaintext login credentials (`user@example.com` / `Un1que$trongPassword2024`) to use `$env.INGEST_LOGIN` and `$env.INGEST_PASSWORD` instead, so that credentials are never stored in version-controlled JSON files.

**Why this priority**: Plaintext passwords in JSON files committed to Git are a critical security risk. Two of the four workflows (WF-INTAKE-EMAIL-V2 and WF-REPLY-SEND-v1) have hardcoded email and password in their auth login nodes, while the other two (WF-REPLY-GENERATE-V2 and WF-EXTRACT-AND-ENRICH-IOC) already correctly use `$env.INGEST_LOGIN` / `$env.INGEST_PASSWORD`.

**Independent Test**: Can be fully tested by setting `INGEST_LOGIN` and `INGEST_PASSWORD` in the n8n container environment, then triggering WF-INTAKE-EMAIL-V2 and WF-REPLY-SEND-v1 and verifying the auth/login requests use the env var values.

**Acceptance Scenarios**:

1. **Given** WF-INTAKE-EMAIL-V2.json after the fix, **When** I search the file for `Un1que` or `user@example.com`, **Then** zero matches are found.
2. **Given** WF-REPLY-SEND-v1.json after the fix, **When** I search the file for `Un1que` or `user@example.com`, **Then** zero matches are found.
3. **Given** `INGEST_LOGIN=ops@scambuster.io` and `INGEST_PASSWORD=s3cret` in the n8n environment, **When** WF-REPLY-SEND-v1 authenticates, **Then** the POST body contains `{"email":"ops@scambuster.io","password":"s3cret"}`.
4. **Given** `INGEST_LOGIN` or `INGEST_PASSWORD` is **not** set, **When** the auth login node executes, **Then** the request fails with a clear authentication error (not a silent empty-string login).

#### Exact Locations -- 2 Hardcoded Credential Pairs

| # | Workflow File | Line | Node Name | JSON Path | Current Value |
|---|---------------|------|-----------|-----------|---------------|
| 1 | `WF-INTAKE-EMAIL-V2.json` | 61 | `Retrieve Token` | `nodes[2].parameters.jsonBody` | `"={{ { \"email\": \"user@example.com\", \"password\": \"Un1que$trongPassword2024\" } }}"` |
| 2 | `WF-REPLY-SEND-v1.json` | 184 | `00_auth_login` | `nodes[7].parameters.jsonBody` | `"={{ { \"email\": \"user@example.com\", \"password\": \"Un1que$trongPassword2024\" } }}"` |

**Target value** (matching the pattern already used in WF-REPLY-GENERATE-V2 and WF-EXTRACT-AND-ENRICH-IOC):
```
"={{ { \"email\": $env.INGEST_LOGIN, \"password\": $env.INGEST_PASSWORD } }}"
```

---

### User Story 3 -- Workflow Name-Based References (Priority: P2)

As a developer importing workflows into a fresh n8n instance, I need all `executeWorkflow` nodes to reference target workflows by **name** instead of by **hardcoded ID**, so that workflow cross-calls work regardless of the instance-specific IDs assigned on import.

**Why this priority**: Workflow IDs are instance-specific. When workflows are exported and re-imported (or deployed to a new n8n instance), the IDs change, breaking all cross-workflow calls. Name-based references resolve at runtime and survive re-imports.

**Independent Test**: Can be fully tested by importing all four workflows into a clean n8n instance and verifying that the executeWorkflow nodes resolve their targets without manual ID patching.

**Acceptance Scenarios**:

1. **Given** all workflows are imported into a fresh n8n instance, **When** WF-INTAKE-EMAIL-V2 triggers the "Trigger Reply Generation" node, **Then** it resolves `WF-REPLY-GENERATE-V2` by name, not by ID `Jx9lpFM49jf9cBTP`.
2. **Given** all workflows are imported into a fresh n8n instance, **When** WF-INTAKE-EMAIL-V2 triggers the "WF-EXTRACT-AND-ENRICH-IOC" node, **Then** it resolves by name, not by ID `csaNdBEuJLViUqcz`.
3. **Given** all workflows are imported into a fresh n8n instance, **When** WF-REPLY-GENERATE-V2 triggers "Call WF-REPLY-SEND-v1", **Then** it resolves by name, not by ID `your-wf-reply-send-id`.

#### Exact Locations -- 3 Hardcoded Workflow ID References

| # | Workflow File | Lines | Node Name | Current ID | Target Workflow Name |
|---|---------------|-------|-----------|------------|---------------------|
| 1 | `WF-INTAKE-EMAIL-V2.json` | 194-199 | `Trigger Reply Generation` | `Jx9lpFM49jf9cBTP` | `WF-REPLY-GENERATE-V2` |
| 2 | `WF-INTAKE-EMAIL-V2.json` | 280-285 | `WF-EXTRACT-AND-ENRICH-IOC` | `csaNdBEuJLViUqcz` | `WF-EXTRACT-AND-ENRICH-IOC` |
| 3 | `WF-REPLY-GENERATE-V2.json` | 164-169 | `Call WF-REPLY-SEND-v1` | `your-wf-reply-send-id` | `WF-REPLY-SEND-v1` |

---

### Edge Cases

- **What if `SCAMBUSTER_API_URL` does not end with `/api/v1`?** The env var MUST contain only the scheme + host + port (e.g., `http://backend-dev:8080`). The `/api/v1/...` path suffix is appended in each node's URL expression. If the operator adds a trailing slash or `/api/v1` to the env var, URLs will double up (e.g., `http://backend-dev:8080//api/v1/auth/login`). This convention MUST be documented in `.env.dist`.
- **What if an environment variable is not set?** n8n's `$env.VAR_NAME` returns `undefined` when the variable is missing. HTTP nodes will attempt a request to `undefined/api/v1/...` which will fail with a connection error. This is acceptable -- a missing required env var is a deployment configuration error and should fail loudly.
- **What if two workflows have the same name?** n8n's name-based resolution picks the first match. Workflow names MUST remain unique across the instance. The four ScamBuster workflows already have unique names prefixed with `WF-`.
- **What if the n8n instance is upgraded?** The `$env` expression syntax and name-based workflow resolution are stable n8n features (available since v0.170+). No compatibility risk with current or future n8n versions.
- **What if `SCAMBUSTER_API_URL` changes while workflows are mid-execution?** n8n evaluates `$env` expressions at node execution time. A URL change mid-workflow would cause subsequent nodes to hit the new URL. This is unlikely in practice and acceptable behavior.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: All 11 occurrences of `http://backend-dev:8080` across the four workflow JSON files (4 static strings + 7 expressions) MUST be replaced with `{{ $env.SCAMBUSTER_API_URL }}` using the appropriate n8n expression syntax. **Critical implementation detail**: in n8n workflow JSON, a string value prefixed with `=` is treated as an expression; without the `=` prefix, n8n treats the value as a literal string. Fields that are already expressions (prefixed with `=`) just need the URL replaced. Fields that are static strings (no `=` prefix) MUST be converted to expressions by prepending `=` to the value. Example: `"url": "http://backend-dev:8080/api/v1/auth/login"` becomes `"url": "={{ $env.SCAMBUSTER_API_URL }}/api/v1/auth/login"`.
- **FR-002**: The 2 hardcoded credential pairs (`"email": "user@example.com", "password": "Un1que$trongPassword2024"`) in WF-INTAKE-EMAIL-V2.json (line 61) and WF-REPLY-SEND-v1.json (line 184) MUST be replaced with `$env.INGEST_LOGIN` / `$env.INGEST_PASSWORD`, matching the pattern already used in WF-REPLY-GENERATE-V2.json (line 32) and WF-EXTRACT-AND-ENRICH-IOC.json (line 30).
- **FR-003**: The 3 executeWorkflow nodes with hardcoded workflow IDs MUST be updated to use name-based resolution (`"mode": "name"` with the workflow name as the value). The `cachedResultUrl` field (an internal n8n cache reference tied to the old numeric ID — meaningless with name-based resolution) MUST be removed to prevent n8n from attempting stale ID lookups. **Dependency note**: name-based resolution only works when the target workflow is imported AND activated. Activation is handled by spec 027 (bootstrap script). Without 027, an operator must manually activate workflows after import for cross-workflow calls to resolve.
- **FR-004**: The `SCAMBUSTER_API_URL` environment variable MUST be added to `.env.dist` with a default value of `http://backend-dev:8080` and a comment explaining the expected format (scheme + host + port, no trailing slash, no path).
- **FR-005**: The `SCAMBUSTER_API_URL` environment variable MUST be passed to the n8n container in `docker-compose.yml` via the environment section (e.g., `- SCAMBUSTER_API_URL=${SCAMBUSTER_API_URL:-http://backend-dev:8080}`).
- **FR-006**: The `INGEST_LOGIN` and `INGEST_PASSWORD` environment variables MUST be passed to the n8n container in `docker-compose.yml` (they already exist in `.env.dist` but are not currently forwarded to the n8n service).
- **FR-007**: No workflow behavior, node connections, or execution logic MUST change. Only URL values, credential references, and workflow ID resolution modes change.

### Key Entities

- **Environment Variable `SCAMBUSTER_API_URL`**: Base URL for the Symfony backend API (scheme + host + port). Used by all 4 workflows. Expected format: `http://backend-dev:8080` (no trailing slash).
- **Environment Variables `INGEST_LOGIN` / `INGEST_PASSWORD`**: Credentials for the n8n service account to authenticate against the backend API. Already defined in `.env.dist`, already used by 2 of 4 workflows.
- **n8n Workflow JSON files**: The 4 files in `n8n/workflows/` that define the automation pipelines. These are the only files modified by this feature.
- **`docker-compose.yml`**: The n8n service section needs 3 additional environment variables forwarded (`SCAMBUSTER_API_URL`, `INGEST_LOGIN`, `INGEST_PASSWORD`).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: `grep -r "backend-dev:8080" n8n/workflows/` returns zero matches after implementation.
- **SC-002**: `grep -r "Un1que" n8n/workflows/` returns zero matches after implementation.
- **SC-003**: `grep -r "user@example.com" n8n/workflows/` returns zero matches after implementation (credentials must only appear in `.env.dist` as placeholders).
- **SC-004**: All 3 executeWorkflow nodes use `"mode": "name"` instead of `"mode": "list"` with hardcoded IDs.
- **SC-005**: `docker-compose.yml` passes `SCAMBUSTER_API_URL`, `INGEST_LOGIN`, and `INGEST_PASSWORD` to the n8n service.
- **SC-006**: `.env.dist` contains `SCAMBUSTER_API_URL` with documentation of the expected format.
- **SC-007**: All 4 workflows execute successfully in the dev environment after the changes (manual smoke test: trigger one email through the full pipeline).
- **SC-008**: Runtime validation: set `SCAMBUSTER_API_URL` to a non-default value (e.g., `http://backend-preprod:8082`), trigger each workflow, and verify (via n8n execution logs or backend access logs) that HTTP requests target the correct host — not the old `backend-dev:8080`.

### Assumptions

- Name-based workflow resolution (FR-003) requires target workflows to be both imported and activated. Activation is handled by spec 027. Without spec 027, operators must manually activate workflows after import.
- The `=` prefix convention for n8n expressions is stable across n8n versions (verified on v0.170+ through v2.42.0).
- `$env` resolution happens at node execution time, not at workflow activation time. Changing an env var takes effect on the next node execution without workflow restart.

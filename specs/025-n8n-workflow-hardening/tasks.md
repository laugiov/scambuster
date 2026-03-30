# Tasks: n8n Workflow Hardening

**Input**: spec.md, plan.md
**Tests**: grep + jq validation (CI-compatible), manual smoke test

## Phase 1: Setup

- [ ] T001 Verify backup exists in n8n/workflows-backup-pre-025/ (all 6 JSON files)
- [ ] T002 Add SCAMBUSTER_API_URL to .env.dist with default http://backend-dev:8080 and format documentation comment (no trailing slash, no /api/v1 path)
- [ ] T003 Forward SCAMBUSTER_API_URL, INGEST_LOGIN, INGEST_PASSWORD to n8n service in docker-compose.yml environment section

## Phase 2: US1 — Backend URL Replacement (P1)

**Goal**: Replace all 11 hardcoded `http://backend-dev:8080` URLs with `$env.SCAMBUSTER_API_URL`

- [ ] T004 [US1] WF-INTAKE-EMAIL-V2.json: Replace 3 backend URLs (node "Retrieve Token" static→expression, node "Ingest Email" static→expression, node "Get Risk Assessment" expression value swap)
- [ ] T005 [US1] WF-REPLY-GENERATE-V2.json: Replace 4 backend URLs (node "00_auth_login" static→expression, nodes "10_fetch_context", "20_generate", "40_store_draft" expression value swap)
- [ ] T006 [US1] WF-REPLY-SEND-v1.json: Replace 4 backend URLs (node "00_auth_login" static→expression, nodes "10_fetch_reply", "12_fetch_compose", "30_confirm" expression value swap)
- [ ] T007 [US1] WF-EXTRACT-AND-ENRICH-IOC.json: Replace 3 backend URLs (node "Retrieve Token" static→expression, nodes "Extract IOCs", "PATCH IOC Enrichment" expression value swap)
- [ ] T008 [US1] Validate: grep -r "backend-dev:8080" n8n/workflows/ returns 0 matches
- [ ] T009 [US1] Validate: jq validates all 4 JSONs are syntactically valid

## Phase 3: US2 — Fix Hardcoded Credentials (P1)

**Goal**: Replace 2 plaintext passwords with $env references

- [ ] T010 [US2] WF-INTAKE-EMAIL-V2.json: Replace node "Retrieve Token" jsonBody with $env.INGEST_LOGIN / $env.INGEST_PASSWORD (matching the pattern in WF-REPLY-GENERATE-V2)
- [ ] T011 [US2] WF-REPLY-SEND-v1.json: Replace node "00_auth_login" jsonBody with $env.INGEST_LOGIN / $env.INGEST_PASSWORD
- [ ] T012 [US2] Validate: grep -r "Un1que" n8n/workflows/ returns 0 matches
- [ ] T013 [US2] Validate: grep -r "user@example.com" n8n/workflows/ returns 0 matches

## Phase 4: US3 — Workflow Name-Based References (P2)

**Goal**: Replace 3 hardcoded workflow IDs with name-based resolution

- [ ] T014 [US3] WF-INTAKE-EMAIL-V2.json: Replace "Trigger Reply Generation" node workflowId from {mode:"list", value:"Jx9lpFM49jf9cBTP"} to {mode:"name", value:"WF-REPLY-GENERATE-V2"}, remove cachedResultUrl
- [ ] T015 [US3] WF-INTAKE-EMAIL-V2.json: Replace "WF-EXTRACT-AND-ENRICH-IOC" node workflowId from {mode:"list", value:"csaNdBEuJLViUqcz"} to {mode:"name", value:"WF-EXTRACT-AND-ENRICH-IOC"}, remove cachedResultUrl
- [ ] T016 [US3] WF-REPLY-GENERATE-V2.json: Replace "Call WF-REPLY-SEND-v1" node workflowId from {mode:"list", value:"your-wf-reply-send-id"} to {mode:"name", value:"WF-REPLY-SEND-v1"}, remove cachedResultUrl
- [ ] T017 [US3] Validate: jq '.nodes[] | select(.type == "n8n-nodes-base.executeWorkflow") | .parameters.workflowId.mode' on all JSONs returns only "name"

## Phase 5: Documentation & Polish

- [ ] T018 Update n8n/README.md: document the env var approach (SCAMBUSTER_API_URL, INGEST_LOGIN/PASSWORD), explain that operators only edit .env — never workflow JSONs
- [ ] T019 Add validation script: scripts/validate-n8n-workflows.sh that runs all grep + jq checks from T008/T009/T012/T013/T017 — exit 0 or 1 (CI-compatible)
- [ ] T020 Add `make validate-n8n` target in Makefile calling the validation script
- [ ] T021 Manual smoke test: set SCAMBUSTER_API_URL to a non-default value, trigger a workflow, verify in n8n execution logs that requests target the correct host

---

## Dependencies

- Phase 1 (Setup): no dependencies
- Phase 2 (URLs): depends on T002 (env var exists)
- Phase 3 (Credentials): independent of Phase 2
- Phase 4 (Workflow IDs): independent of Phase 2/3
- Phase 5 (Docs): depends on all previous phases

## Notes

- All changes are to JSON files — no PHP, no TypeScript, no SQL
- Backup exists in n8n/workflows-backup-pre-025/
- Static URL fields need `=` prefix to become expressions (n8n convention)
- The `__rl`, `cachedResultUrl`, `cachedResultName` keys in workflowId objects need to be handled correctly during mode change

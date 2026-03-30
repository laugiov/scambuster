# Tasks: Provider-Agnostic Email

**Input**: spec.md, plan.md
**Depends on**: 025-n8n-workflow-hardening (merged)
**Tests**: Integration tests (PHPUnit), grep validation, manual IMAP test, manual E2E smoke test

## Phase 0: PREREQUISITE — IMAP Raw Format Validation

**BLOCKER**: This must be done before ANY US1 code is written.

- [ ] T000 [BLOCKER] Test emailReadImap node in n8n: create test workflow, connect to real IMAP mailbox, format: "raw", send test email, inspect output structure. Document exact output fields. Compare with Extract Email Data input expectations. Result determines if US1 proceeds as-is or requires adaptation.

## Phase 1: Setup

- [ ] T001 Install symfony/mailer: cd backend-symfony && composer require symfony/mailer
- [ ] T002 Create config/packages/mailer.yaml with MAILER_DSN env var reference
- [ ] T003 Add HONEYPOT_IMAP_* variables to .env.dist with provider-specific comments (Gmail, Outlook, Yahoo, ProtonMail, custom)
- [ ] T004 Add MAILER_DSN to .env.dist with smtps:// vs smtp:// documentation and URL-encoding warning
- [ ] T005 [P] Forward HONEYPOT_IMAP_* to n8n service in docker-compose.yml
- [ ] T006 [P] Forward MAILER_DSN to backend service in docker-compose.yml

## Phase 2: US2 — Backend /send-email Endpoint (P1)

**Goal**: New stateless endpoint that sends an email via Symfony Mailer and returns the Message-ID. Does NOT modify message state in DB.

- [ ] T007 [US2] Add sendEmail() method to ReplyHandler.php: read draft from DB, build Symfony Email with From/To/Subject/HTML/In-Reply-To/References headers from conversation history, send via MailerInterface, generate Message-ID, return {message_id, ts_sent}
- [ ] T008 [US2] Add sendEmail action to ReplyController.php: POST /api/v1/communication/reply/{msgId}/send-email, JWT auth, delegates to handler, returns 200/404/422/500
- [ ] T009 [US2] Integration test: POST /send-email with valid draft → 200 + message_id returned (mock Mailer transport to avoid real SMTP in tests)
- [ ] T010 [US2] Integration test: POST /send-email with non-existent msgId → 404
- [ ] T011 [US2] Integration test: POST /send-email with already-sent message → 422
- [ ] T012 [US2] Integration test: verify In-Reply-To and References headers are correctly set on the built Email object (inspect via mock transport)
- [ ] T013 [US2] Run PHPStan on new/modified files
- [ ] T014 [US2] Run CS-Fixer on new/modified files

## Phase 3: US1 — Replace Gmail Trigger with IMAP Trigger (P1)

**Goal**: WF-INTAKE-EMAIL-V2 receives emails via standard IMAP instead of Gmail OAuth.

- [ ] T015 [US1] WF-INTAKE-EMAIL-V2.json: Replace Gmail Trigger node with emailReadImap node (format: raw, postProcessAction: read, mailbox: INBOX). Credential reference: "ScamBuster IMAP" (created by spec 027 bootstrap)
- [ ] T016 [US1] WF-INTAKE-EMAIL-V2.json: Remove "Gmail Get Raw Message" node entirely (IMAP trigger provides raw directly)
- [ ] T017 [US1] WF-INTAKE-EMAIL-V2.json: Update "Merge Email Data" code node to read from IMAP trigger output instead of Gmail Trigger + Gmail Get Raw Message
- [ ] T018 [US1] WF-INTAKE-EMAIL-V2.json: Update "Prepare Payload" code node — replace references to $('Gmail Trigger') and $('Gmail Get Raw Message') with reference to new IMAP node name
- [ ] T019 [US1] WF-INTAKE-EMAIL-V2.json: Update node connections (wiring) to reflect removed node
- [ ] T020 [US1] Validate: grep -r "gmailTrigger\|gmailOAuth2" n8n/workflows/WF-INTAKE-EMAIL-V2.json returns 0 matches

## Phase 4: US2 continued — Replace Gmail Send in WF-REPLY-SEND-v1 (P1)

**Goal**: n8n calls backend /send-email instead of Gmail API for sending.

- [ ] T021 [US2] WF-REPLY-SEND-v1.json: Replace "20_gmail_send" node with HTTP Request node named "20_backend_send": POST {{ $env.SCAMBUSTER_API_URL }}/api/v1/communication/reply/{{ msg_id }}/send-email, Bearer auth
- [ ] T022 [US2] WF-REPLY-SEND-v1.json: Remove "21_retrieve_message_metadata" node
- [ ] T023 [US2] WF-REPLY-SEND-v1.json: Simplify "22_extract_message_id" code node to read message_id from /send-email response instead of Gmail headers
- [ ] T024 [US2] WF-REPLY-SEND-v1.json: Update "30_confirm" node to send provider: "smtp" instead of "gmail", use message_id from /send-email response
- [ ] T025 [US2] WF-REPLY-SEND-v1.json: Verify UNCHANGED nodes: Calculate Human Delay, Wait Until Send Time, 10_fetch_reply, 12_fetch_compose — must not be modified
- [ ] T026 [US2] WF-REPLY-SEND-v1.json: Update node connections (wiring) to reflect removed nodes
- [ ] T027 [US2] Validate: grep -r "gmail\|gmailOAuth2" n8n/workflows/WF-REPLY-SEND-v1.json returns 0 matches

## Phase 5: US3 + US4 — Config & Documentation (P2/P3)

- [ ] T028 [US3] Verify all IMAP env vars forwarded to n8n in docker-compose.yml
- [ ] T029 [US3] Verify MAILER_DSN forwarded to backend in docker-compose.yml
- [ ] T030 [US4] Create docs/17_email_provider_setup.md: provider table (Gmail/Outlook/Yahoo/ProtonMail/custom), Gmail App Password walkthrough (2-min setup), MAILER_DSN format guide with smtps:// vs smtp://, known limitations
- [ ] T031 [US4] Update docs/08_getting_started.md: replace Gmail OAuth instructions with IMAP App Password + MAILER_DSN

## Phase 6: Regression Testing & Polish

- [ ] T032 Run make test — verify ALL existing tests still pass (zero regressions on /sent, /compose, /generate, /draft endpoints)
- [ ] T033 Run make stan — PHPStan clean
- [ ] T034 Run make cs-fixer — zero violations
- [ ] T035 Validate all workflow JSONs with jq (syntactically valid)
- [ ] T036 Manual smoke test: configure Gmail IMAP App Password, send test email to honeypot, verify ingestion works end-to-end
- [ ] T037 Manual smoke test: trigger reply generation, verify /send-email is called, verify reply appears in correct thread

---

## Dependencies

- T000 (IMAP test) blocks ALL of Phase 3
- Phase 1 (Setup) blocks Phase 2
- Phase 2 (backend) blocks Phase 4 (n8n workflow needs the endpoint)
- Phase 3 (IMAP) is independent of Phase 2/4
- Phase 5 depends on Phase 2+3+4
- Phase 6 depends on everything

## Critical Notes

- The /sent endpoint is UNCHANGED — n8n still calls it with the same payload (just provider="smtp" instead of "gmail")
- The human delay logic is UNCHANGED — the Wait node stays in n8n
- The orchestration flow is UNCHANGED — same sequence of nodes, same data flow
- Only the Gmail-specific nodes are replaced
- Symfony Mailer must be mocked in integration tests (NullTransport or InMemoryTransport)

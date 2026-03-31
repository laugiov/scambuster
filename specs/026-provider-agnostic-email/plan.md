# Implementation Plan: Provider-Agnostic Email

**Branch**: `026-provider-agnostic-email` | **Date**: 2026-03-30 | **Spec**: [spec.md](spec.md)
**Depends on**: 025-n8n-workflow-hardening (must be merged first)

## Summary

Replace Gmail-specific n8n nodes with standard IMAP trigger (receiving) and a new backend endpoint for SMTP sending (Symfony Mailer). This makes ScamBuster compatible with any email provider while preserving the existing n8n orchestration (human delay, confirmation flow).

## Technical Context

**Language/Version**: PHP 8.3 / Symfony 7.2 (new endpoint), n8n workflow JSON (node replacement)
**Primary Dependencies**: symfony/mailer (NEW — must install), n8n emailReadImap node (core)
**Storage**: No schema changes — Message.headers JSON column already stores all needed fields
**Testing**: PHPUnit integration tests for /send-email, grep validation for workflow JSONs, manual IMAP raw format test (PREREQUISITE)
**Constraints**: Zero changes to existing endpoints (/sent, /compose, /generate, /draft). Human delay logic stays in n8n.

## Constitution Check

| Gate | Status | Notes |
|------|--------|-------|
| I. DDD layers | PASS | New endpoint in UI/Http, new method in Application handler — correct layers |
| II. Hexagonal ports | PASS | Symfony Mailer is an infrastructure adapter, could be abstracted behind interface if needed |
| III. Test-driven quality | PASS | Integration tests for new endpoint, grep tests for JSON, manual IMAP test |
| IV. Safety & Ethics | PASS | No new attack surface — /send-email is JWT-protected, only sends pre-approved drafts |
| V. Cost awareness | PASS | Zero LLM calls. SMTP sending is free/cheap |
| VI. Internationalization | N/A | No frontend changes |
| VII. Simplicity | PASS | One new endpoint, one new method, minimal workflow changes |

## PREREQUISITE — IMAP Raw Format Test

**BEFORE any US1 implementation**, manually test the `emailReadImap` node in n8n:
1. Create a test workflow with an emailReadImap trigger, format: "raw"
2. Connect to a real IMAP mailbox (Gmail with App Password is easiest)
3. Send a test email and inspect the node's output in n8n UI
4. Verify: does the output contain the full RFC822 message (headers + body as base64 string)?
5. Compare with what `Extract Email Data` code node expects as input

**If raw format provides RFC822**: proceed with US1 as specified
**If raw format provides structured fields**: adapt `Extract Email Data` to consume structured output instead of raw RFC822. The backend `/ingest/raw` endpoint may also need adaptation.

This test takes 20 minutes and blocks all US1 work.

## Project Structure

```text
backend-symfony/
├── src/
│   ├── UI/Http/Communication/
│   │   └── ReplyController.php              # MODIFY: add sendEmail action
│   └── Application/Communication/
│       └── ReplyHandler.php                 # MODIFY: add sendEmail() method
├── config/packages/
│   └── mailer.yaml                          # NEW: Symfony Mailer config
├── composer.json                            # MODIFY: add symfony/mailer
└── tests/Integration/UI/Http/Communication/
    └── ReplyControllerTest.php              # MODIFY: add /send-email tests

n8n/
├── workflows/
│   ├── WF-INTAKE-EMAIL-V2.json             # MODIFY: Gmail Trigger → IMAP Trigger
│   └── WF-REPLY-SEND-v1.json              # MODIFY: Gmail Send → HTTP Request /send-email

.env.dist                                    # MODIFY: add HONEYPOT_IMAP_*, MAILER_DSN
docker-compose.yml                           # MODIFY: forward IMAP vars + MAILER_DSN
docs/                                        # NEW: provider setup guide
```

## Strategy

1. **PREREQUISITE**: Test IMAP raw format manually (20 min)
2. Install symfony/mailer (`composer require symfony/mailer`)
3. Create /send-email backend endpoint + tests
4. Replace Gmail Trigger with IMAP Trigger in WF-INTAKE-EMAIL-V2 (US1)
5. Replace Gmail Send with HTTP Request to /send-email in WF-REPLY-SEND-v1 (US2)
6. Update .env.dist and docker-compose.yml (US3)
7. Write provider documentation (US4)
8. Full regression test: make test + manual smoke test

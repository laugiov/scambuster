# Feature Specification: Provider-Agnostic Email -- Replace Gmail Nodes with IMAP/SMTP

**Feature Branch**: `026-provider-agnostic-email`
**Created**: 2026-03-30
**Status**: Draft
**Input**: User description: "Replace Gmail-specific n8n nodes with generic IMAP/SMTP nodes so ScamBuster works with any email provider, and reduce Gmail setup from 45 min OAuth to 2 min App Password."

## Context

Currently, ScamBuster's email pipeline is locked to Gmail through two n8n workflows:

- **WF-INTAKE-EMAIL-V2** uses `n8n-nodes-base.gmailTrigger` (node: "Gmail Trigger") to poll for new emails, then `n8n-nodes-base.gmail` (node: "Gmail Get Raw Message") to fetch the full RFC822 content. Both require a `gmailOAuth2` credential (`id: bxq5Cj1I9zO37PlB`, "Gmail account Valeris") configured via Google Cloud Console OAuth -- a 45-minute setup involving project creation, OAuth consent screen, credential generation, and token exchange.

- **WF-REPLY-SEND-v1** uses `n8n-nodes-base.gmail` (node: "20_gmail_send") to send replies via the Gmail API, and another `n8n-nodes-base.gmail` (node: "21_retrieve_message_metadata") to fetch the sent message's metadata (Message-ID, Thread-ID) for threading confirmation.

This Gmail lock-in has three problems:
1. **Onboarding friction**: New deployments require a Google Cloud project with OAuth -- 45 minutes of configuration before the first email is processed.
2. **Single-provider**: ScamBuster cannot be used with Outlook, Yahoo, ProtonMail Bridge, Fastmail, Dovecot, or any custom IMAP server.
3. **Credential complexity**: OAuth tokens expire and require refresh token rotation, adding operational fragility.

n8n provides built-in nodes that work with any IMAP/SMTP-compliant provider:
- `n8n-nodes-base.emailReadImap` -- IMAP Trigger that polls any IMAP mailbox
- `n8n-nodes-base.emailSend` -- SMTP Send that works with any SMTP server

For Gmail specifically, users just need to enable 2FA and create an App Password (Settings > Security > App Passwords). This reduces setup from 45 minutes to 2 minutes. No Google Cloud project, no OAuth consent screen, no client secrets.

## User Scenarios & Testing *(mandatory)*

### User Story 1 -- Replace Gmail Trigger with IMAP Trigger in WF-INTAKE-EMAIL-V2 (Priority: P1)

As a ScamBuster operator, I want the intake workflow to receive emails via standard IMAP so I can use any email provider (Gmail, Outlook, Yahoo, custom servers) without configuring Google Cloud OAuth.

**Why this priority**: This is the core ingestion path. Without email intake, ScamBuster cannot process any scam emails. Replacing the Gmail Trigger unblocks all other providers and eliminates the single biggest onboarding friction.

**Independent Test**: Deploy ScamBuster with a Gmail App Password (IMAP), send a scam email to the honeypot address, and verify the email is ingested and a conversation is created in the database. No Google Cloud Console interaction required.

**Current flow (to be replaced)**:

```
Gmail Trigger (gmailTrigger v1.1)
  - polls every minute for unread emails
  - credentials: gmailOAuth2 "Gmail account Valeris"
  - output: { id, threadId, labelIds, snippet }
      |
      v
Gmail Get Raw Message (gmail v1)
  - operation: "get", format: "raw"
  - input: message ID from trigger
  - credentials: gmailOAuth2 "Gmail account Valeris"
  - output: { id, threadId, raw (base64url-encoded RFC822) }
      |
      v
Extract Email Data (code node)
  - decodes base64url -> base64 -> RFC822
  - parses headers, body, attachments
```

**New flow**:

```
IMAP Email Trigger (emailReadImap)
  - host: {{ $env.HONEYPOT_IMAP_HOST }}
  - port: {{ $env.HONEYPOT_IMAP_PORT }}
  - user: {{ $env.HONEYPOT_IMAP_USER }}
  - password: {{ $env.HONEYPOT_IMAP_PASSWORD }}
  - secure: {{ $env.HONEYPOT_IMAP_SECURE }}
  - mailbox: INBOX
  - format: "raw" (full RFC822 output)
  - postProcessAction: "markRead" (mark as read after fetching)
  - pollTimes: everyMinute (same as current)
  - output: raw RFC822 message (NOT base64url -- standard base64 or plain text)
      |
      v
Extract Email Data (code node) -- MODIFIED
  - Remove base64url-to-base64 conversion (b64urlToB64) since IMAP
    provides standard base64 or raw text, not Gmail's base64url encoding
  - Keep all RFC822 parsing logic unchanged
  - Adapt input field name from item.raw to the IMAP node's output field
```

**Key changes**:
- The `Gmail Trigger` node (id: `a40abcac-34bb-49f6-a3d5-d3cb9314842d`) is replaced by a single `emailReadImap` node
- The `Gmail Get Raw Message` node (id: `84c43f4e-a4a7-4f92-8012-4be470f29116`) is **removed entirely** because the IMAP trigger in "raw" format already provides the full RFC822 message -- no second API call needed
- The `Merge Email Data` code node (id: `d2dfd221-ae0b-4c3f-a9a7-bd8084b3c3e2`) must be updated since it currently merges data from three separate nodes (`Gmail Trigger`, `Gmail Get Raw Message`, `Extract Email Data`). With IMAP, the trigger and raw message are a single node.
- The `Prepare Payload` code node references `$('Gmail Get Raw Message')` and `$('Gmail Trigger')` -- these references must be updated to point to the new IMAP node
- Credentials change from `gmailOAuth2` (OAuth) to environment variables (IMAP host/port/user/password)

**Acceptance Scenarios**:

1. **Given** ScamBuster is configured with Gmail IMAP credentials (imap.gmail.com:993, App Password), **When** a scam email arrives in the honeypot inbox, **Then** the IMAP trigger detects it within 1 minute, fetches the raw RFC822 content, and passes it to Extract Email Data unchanged.

2. **Given** ScamBuster is configured with Outlook IMAP credentials (outlook.office365.com:993), **When** a scam email arrives, **Then** it is ingested identically to a Gmail email -- same RFC822 parsing, same conversation creation, same IOC extraction.

3. **Given** the IMAP trigger fetches a message, **When** the message is processed, **Then** it is marked as read in the mailbox so it is not re-fetched on the next poll cycle.

4. **Given** the IMAP trigger fetches a raw message, **When** it is passed to Extract Email Data, **Then** all headers (From, To, Subject, Date, Message-ID, In-Reply-To, References), body (text + HTML), and attachments are parsed correctly -- identical output to the current Gmail-based flow.

5. **Given** IMAP credentials are provided via environment variables, **When** the n8n workflow starts, **Then** it uses credential overrides from env vars without any manual credential configuration in the n8n UI.

---

### User Story 2 -- Replace Gmail Send with Backend SMTP via Symfony Mailer (Priority: P1)

As a ScamBuster operator, I want the email sending delegated to the backend via Symfony Mailer, so the send path is provider-agnostic and supports proper RFC 2822 threading headers.

**Why this priority**: The n8n `emailSend` SMTP node does NOT support custom headers (verified via n8n MCP node inspection) — meaning `In-Reply-To` and `References` cannot be set, breaking email threading. Symfony Mailer supports all RFC 2822 headers natively.

**Critical design decision**: n8n KEEPS the orchestration (human delay, fetch compose, confirm sent). Only the physical SMTP send is delegated to the backend. The existing `/sent` endpoint contract is UNCHANGED. This minimizes regression risk.

**Independent Test**: Trigger a reply generation, verify the reply is sent via the backend's `/send-email` endpoint and appears in the correct email thread.

**Current flow**:
```
n8n: Calculate Human Delay → Wait → 00_auth → 10_fetch_reply → 12_fetch_compose
  → 20_gmail_send (Gmail API)         ← REPLACED
  → 21_retrieve_message_metadata      ← REMOVED
  → 22_extract_message_id             ← SIMPLIFIED
  → 30_confirm (/sent)                ← UNCHANGED
```

**New flow** (n8n orchestration preserved):
```
n8n: Calculate Human Delay → Wait → 00_auth → 10_fetch_reply → 12_fetch_compose
  → 20_backend_send (HTTP Request)    ← NEW: POST /send-email
      Backend: read draft → Symfony Mailer (SMTP + threading headers) → return Message-ID
  → 22_extract_message_id             ← SIMPLIFIED: read from /send-email response
  → 30_confirm (/sent)                ← UNCHANGED: same payload, provider="smtp"
```

**New backend endpoint**: `POST /api/v1/communication/reply/{msg_id}/send-email`
- Auth: JWT Bearer
- Does ONLY the SMTP send — no state changes, no orchestration
- Reads reply content + threading headers from DB
- Builds email with Symfony Mailer:
  - From: `HONEYPOT_FROM_ADDRESS` or `HONEYPOT_IMAP_USER`
  - To: scammer address (from compose data)
  - Subject: Re: original subject
  - Body: HTML reply
  - In-Reply-To: parent Message-ID (from stored headers)
  - References: full chain of Message-IDs
  - Generated Message-ID: `<uuid@scambuster.local>`
- Sends via SMTP (`MAILER_DSN`)
- Returns: `{ "success": true, "message_id": "<uuid@scambuster.local>", "ts_sent": "..." }`
- Does NOT update message state — that's `/sent`'s job (called by n8n after)
- `404` if message not found, `422` if not a draft, `500` if SMTP fails

**Backend .env addition**:
```env
# Email sending (Symfony Mailer DSN)
# smtps:// = TLS implicit (port 465) — Gmail, Yahoo
# smtp://  = STARTTLS (port 587) — Outlook, custom
# IMPORTANT: URL-encode special chars in password (@→%40 :→%3A)
MAILER_DSN=smtps://user:password@smtp.gmail.com:465
```

**Acceptance Scenarios**:

1. **Given** a reply is generated, **When** the n8n workflow calls `POST /reply/{id}/send-email`, **Then** the backend sends the email via SMTP and returns `{ message_id, ts_sent }`. n8n then calls `/sent` with this data (unchanged flow).

2. **Given** the original scam email has Message-ID `<abc@scammer.com>`, **When** `/send-email` is called, **Then** the outgoing email includes `In-Reply-To: <abc@scammer.com>` and `References: <abc@scammer.com>`.

3. **Given** a multi-turn conversation, **When** the next reply is sent, **Then** `References` contains the full Message-ID chain.

4. **Given** `/send-email` succeeds, **Then** the endpoint returns the Message-ID but does NOT modify message state in DB — that's `/sent`'s job (called by n8n after).

5. **Given** `MAILER_DSN` is misconfigured, **When** `/send-email` is called, **Then** it returns 500, the reply stays in draft, and n8n handles the error gracefully (existing error workflow).

6. **Given** the human delay + orchestration logic, **When** a reply is processed, **Then** the Wait node, timezone-aware delay, and log-normal distribution remain UNCHANGED in n8n — only the Gmail send node is swapped for an HTTP Request to `/send-email`.

---

### User Story 3 -- Update .env.dist and docker-compose.yml (Priority: P2)

As a ScamBuster operator, I want all IMAP/SMTP configuration in `.env` with sensible defaults and provider-specific examples so I can configure email by editing a single file.

**Why this priority**: This is the configuration layer that makes US1 and US2 usable. Without env var forwarding, the n8n container cannot read the credentials.

**Independent Test**: Copy `.env.dist` to `.env`, fill in Gmail App Password credentials, run `docker compose up`, and verify the n8n container has the IMAP/SMTP env vars available.

**New environment variables to add to `.env.dist`**:

```bash
########################################
# Honeypot Email — Receiving (IMAP via n8n)
########################################

HONEYPOT_IMAP_HOST=imap.gmail.com
HONEYPOT_IMAP_PORT=993
HONEYPOT_IMAP_USER=your-honeypot@gmail.com
HONEYPOT_IMAP_PASSWORD=your-app-password-here
HONEYPOT_IMAP_SECURE=true

########################################
# Honeypot Email — Sending (SMTP via Symfony Mailer)
########################################

# Symfony Mailer DSN format:
#   smtps://  = TLS implicit (port 465) — use for Gmail, Yahoo
#   smtp://   = STARTTLS (port 587) — use for Outlook, custom servers
#
# IMPORTANT: If your password contains @, :, / or # characters,
# you MUST URL-encode them. Example: p@ss:word → p%40ss%3Aword
#
# Gmail (port 465, TLS):
#   MAILER_DSN=smtps://honeypot@gmail.com:app-password@smtp.gmail.com:465
# Outlook (port 587, STARTTLS):
#   MAILER_DSN=smtp://user@outlook.com:password@smtp.office365.com:587
# Yahoo (port 465, TLS):
#   MAILER_DSN=smtps://user@yahoo.com:app-password@smtp.mail.yahoo.com:465
# ProtonMail Bridge (local, no TLS):
#   MAILER_DSN=smtp://user:bridge-password@127.0.0.1:1025
# Custom (port 587, STARTTLS):
#   MAILER_DSN=smtp://user:pass@mail.yourdomain.com:587
MAILER_DSN=smtps://user:password@smtp.gmail.com:465

# From address for outgoing replies (defaults to HONEYPOT_IMAP_USER if not set)
# HONEYPOT_FROM_ADDRESS=honeypot-alias@gmail.com

# Provider-specific IMAP examples:
#
# Gmail (App Password — 2 min setup):
#   HONEYPOT_IMAP_HOST=imap.gmail.com
#   HONEYPOT_IMAP_PORT=993
#   (Enable 2FA, then create App Password at https://myaccount.google.com/apppasswords)
#
# Outlook / Office 365:
#   HONEYPOT_IMAP_HOST=outlook.office365.com
#   HONEYPOT_IMAP_PORT=993
#   (Note: some enterprise tenants disable IMAP — check with your O365 admin)
#
# Yahoo Mail:
#   HONEYPOT_IMAP_HOST=imap.mail.yahoo.com
#   HONEYPOT_IMAP_PORT=993
#
# ProtonMail Bridge (local, paid plans only):
#   HONEYPOT_IMAP_HOST=127.0.0.1
#   HONEYPOT_IMAP_PORT=1143
#   HONEYPOT_IMAP_SECURE=false
#
# Custom Dovecot/Postfix:
#   HONEYPOT_IMAP_HOST=mail.yourdomain.com
#   HONEYPOT_IMAP_PORT=993
```

**docker-compose.yml changes**:

n8n service — forward IMAP vars only (SMTP is handled by backend):
```yaml
n8n:
  environment:
    # ... existing vars ...
    - HONEYPOT_IMAP_HOST=${HONEYPOT_IMAP_HOST:-imap.gmail.com}
    - HONEYPOT_IMAP_PORT=${HONEYPOT_IMAP_PORT:-993}
    - HONEYPOT_IMAP_USER=${HONEYPOT_IMAP_USER}
    - HONEYPOT_IMAP_PASSWORD=${HONEYPOT_IMAP_PASSWORD}
    - HONEYPOT_IMAP_SECURE=${HONEYPOT_IMAP_SECURE:-true}
```

Backend service — add MAILER_DSN:
```yaml
backend-dev:
  environment:
    # ... existing vars ...
    - MAILER_DSN=${MAILER_DSN}
```

**Acceptance Scenarios**:

1. **Given** a fresh clone of the repository, **When** the operator copies `.env.dist` to `.env` and fills in Gmail App Password credentials, **Then** `docker compose up` starts n8n with the IMAP/SMTP env vars available inside the container.

2. **Given** the operator uses Outlook credentials in `.env`, **When** `docker compose up` runs, **Then** the n8n IMAP trigger connects to `outlook.office365.com:993` and the SMTP node sends via `smtp.office365.com:587`.

---

### User Story 4 -- Update Documentation (Priority: P3)

As a new ScamBuster operator, I want clear documentation on how to configure email for any provider so I can get running quickly without reading n8n internals.

**Why this priority**: Documentation improves onboarding but is not blocking for functionality. Operators can figure out the env vars from `.env.dist` comments, but a dedicated guide reduces friction further.

**Independent Test**: A new operator follows the documentation to configure ScamBuster with a Gmail App Password and successfully ingests their first scam email within 5 minutes.

**Documentation content**:

Provider setup table:

| Provider | IMAP Host | IMAP Port | SMTP Host | SMTP Port | Auth Method | Notes |
|---|---|---|---|---|---|---|
| Gmail | imap.gmail.com | 993 | smtp.gmail.com | 465 | App Password | Enable 2FA first |
| Outlook/O365 | outlook.office365.com | 993 | smtp.office365.com | 587 | App Password or OAuth | Modern auth may require admin |
| Yahoo | imap.mail.yahoo.com | 993 | smtp.mail.yahoo.com | 465 | App Password | Generate at account security |
| ProtonMail | 127.0.0.1 | 1143 | 127.0.0.1 | 1025 | Bridge password | Requires ProtonMail Bridge running locally |
| Fastmail | imap.fastmail.com | 993 | smtp.fastmail.com | 465 | App Password | Settings > Privacy & Security |
| Custom (Dovecot) | mail.domain.com | 993 | mail.domain.com | 587 | Password | Standard IMAP/SMTP |

Step-by-step Gmail App Password setup (2 minutes):
1. Go to https://myaccount.google.com/security
2. Enable 2-Step Verification (if not already enabled)
3. Go to https://myaccount.google.com/apppasswords
4. Select "Mail" and "Other (Custom name)" > enter "ScamBuster"
5. Copy the 16-character password
6. Set `HONEYPOT_IMAP_PASSWORD` and `HONEYPOT_SMTP_PASSWORD` in `.env`

Limitations section:
- SMTP does not return sent message metadata (Message-ID, Thread-ID) like Gmail API does. The system generates a local Message-ID but cannot confirm the server-assigned one.
- IMAP polling has a minimum interval of 1 minute (n8n limitation). Gmail Pub/Sub push notifications are not available with IMAP.
- Some providers (e.g., Microsoft with "Security Defaults") may block IMAP/SMTP access and require admin exemption.

**Acceptance Scenarios**:

1. **Given** the documentation exists, **When** a new operator reads the Gmail App Password section, **Then** they can configure ScamBuster email in under 5 minutes without any prior n8n or Google Cloud experience.

---

### Edge Cases

- **Wrong IMAP credentials**: n8n displays a connection error in the execution log. The workflow does not process any emails. The error is visible in the n8n UI under the workflow's execution history. No data loss -- emails remain unread in the mailbox.

- **SMTP send failure** (bad credentials, network timeout, rate limit): The n8n workflow must use error handling (try/catch or error workflow) to catch the failure. The reply message remains in "draft" status in the backend and can be retried. The `30_confirm` endpoint is NOT called on failure.

- **Gmail "Less Secure Apps" deprecation**: Google deprecated "Less Secure Apps" in May 2022, but App Passwords still work when 2FA is enabled. App Passwords are the officially supported method for IMAP/SMTP access to Gmail. This is not affected by the deprecation.

- **Rate limits**: Gmail IMAP allows ~15 connections and ~10,000 fetches per day. For ScamBuster's volume (~20 emails/day), this is not a concern. The IMAP polling interval is configurable in the n8n node (default: every minute). For high-volume deployments, the interval can be increased.

- **Email threading with SMTP**: Gmail API's `reply` operation automatically threads replies. With SMTP, threading relies on `In-Reply-To` and `References` headers (RFC 2822). The compose endpoint must provide these headers. If they are missing, the reply will appear as a new email thread -- functional but suboptimal.

- **Provider does not support raw IMAP format**: The n8n `emailReadImap` node supports "raw" and "simple" output formats. If a provider does not return full RFC822 in raw mode (unlikely for IMAP-compliant servers), fall back to "simple" format. The `Extract Email Data` code node would need an alternate parsing path for simple format (structured fields instead of raw RFC822). This is unlikely to be needed in practice.

- **OAuth-only providers**: Some enterprise Microsoft 365 tenants disable IMAP password auth entirely and require OAuth. This is out of scope for the initial implementation. The old Gmail OAuth workflow can be preserved as a fallback for such cases (documented, not default).

- **Concurrent IMAP connections**: If multiple n8n instances poll the same mailbox, messages could be processed twice. Mitigation: the backend's `compositeHash` deduplication on the `Message` entity prevents duplicate ingestion. The IMAP trigger's "mark as read" also prevents re-fetch on the same instance.

- **Large attachments**: IMAP fetches the full message including attachments, unlike Gmail API which allows partial fetches. For emails with large attachments (>25MB), this may cause memory pressure in n8n. The existing attachment parsing in `Extract Email Data` handles this by extracting metadata (filename, size, hash) without storing the full binary in the workflow data.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: System MUST receive emails via standard IMAP protocol using `n8n-nodes-base.emailReadImap`, replacing `n8n-nodes-base.gmailTrigger` and `n8n-nodes-base.gmail` (get raw message) in WF-INTAKE-EMAIL-V2.
- **FR-002**: The n8n send path MUST delegate SMTP transmission to a new backend endpoint `POST /api/v1/communication/reply/{msg_id}/send-email`. This endpoint does ONLY the physical SMTP send (Symfony Mailer) + returns the generated Message-ID. It does NOT modify message state — the existing `/sent` endpoint handles state transitions (called by n8n after). The human delay, orchestration, and confirmation flow remain UNCHANGED in n8n.
- **FR-003**: IMAP credentials MUST be injectable into n8n via the bootstrap script (spec 027) using the n8n REST API (`POST /rest/credentials`). SMTP credentials are configured in the backend via `MAILER_DSN` env var (Symfony Mailer standard) — not in n8n.
- **FR-004**: The backend send endpoint MUST preserve email threading by setting `In-Reply-To` and `References` headers (RFC 2822) on outgoing emails, using stored Message-IDs from the conversation history.
- **FR-005**: System MUST mark emails as read after fetching via IMAP to prevent re-processing on the next poll cycle.
- **FR-006**: System MUST output RFC822 raw format from the IMAP trigger so the existing `Extract Email Data` parsing code and the backend `/ingest/raw` endpoint continue to work. **PREREQUISITE BEFORE IMPLEMENTATION**: The `emailReadImap` node in "raw" format MUST be manually tested on a real IMAP mailbox in n8n UI (20 min effort). Inspect the exact output structure and compare with what `Extract Email Data` expects. If the raw format does not provide full RFC822 (headers + body as a single string/base64), US1 must be redesigned to consume the node's structured output (text, html, headers fields) and adapt `Extract Email Data` accordingly. This test blocks all US1 implementation.
- **FR-007**: System MUST forward `HONEYPOT_IMAP_*` environment variables from `.env` to the n8n container and `MAILER_DSN` to the backend container via `docker-compose.yml`.
- **FR-008**: System MUST handle SMTP send failures gracefully -- the reply remains in draft status and the confirm endpoint is not called.
- **FR-009**: System MUST generate a local Message-ID for sent replies (format: `<uuid@scambuster.local>`) since SMTP does not return server-assigned Message-IDs.
- **FR-010**: System MUST work with at minimum Gmail (App Password), Outlook/O365 (IMAP), and custom IMAP/SMTP servers (Dovecot, Postfix, etc.).

### Key Entities

- **n8n IMAP Credential**: Created by the bootstrap script (spec 027) via n8n REST API. Type `imap` with host/port/user/password/secure fields. Not configured via `$env` expressions (trigger nodes read from credential DB, not expressions).
- **Backend Send Endpoint** (new): `POST /api/v1/communication/reply/{msg_id}/send` — reads reply from DB, builds email with Symfony Mailer (threading headers from conversation history), sends via MAILER_DSN, records Message-ID + timestamp.
- **MAILER_DSN** (new env var): Symfony Mailer transport DSN configured in backend `.env`. Format: `smtp://user:password@host:port`. Supports Gmail, Outlook, Yahoo, custom SMTP servers.
- **Sent Confirmation** (existing, UNCHANGED): The `POST /reply/{msg_id}/sent` endpoint remains exactly as-is. n8n continues to call it after receiving the Message-ID from `/send-email`. No contract changes. Provider field changes from `"gmail"` to `"smtp"`.
- **Send Email** (new): `POST /reply/{msg_id}/send-email` — pure SMTP send function. Stateless: reads draft from DB, sends via Symfony Mailer, returns Message-ID. Does NOT write to DB. No side effects on conversation state.

**New endpoint contract — `POST /api/v1/communication/reply/{msg_id}/send-email`**:
- Auth: JWT Bearer (ROLE_USER)
- Success: `200 OK` with `{ "success": true, "message_id": "<uuid@scambuster.local>", "ts_sent": "2026-03-30T15:00:00Z" }`
- `404 Not Found`: reply message does not exist
- `422 Unprocessable Entity`: message is not a draft/reply (wrong direction or status)
- `500 Internal Server Error`: Symfony Mailer transport failure — safe to retry (no DB state changed)

### Nodes Replaced (Summary)

| Workflow | Old Node | Old Type | New Node | New Type | Notes |
|---|---|---|---|---|---|
| WF-INTAKE-EMAIL-V2 | Gmail Trigger | `gmailTrigger v1.1` | IMAP Email Trigger | `emailReadImap` | format: raw |
| WF-INTAKE-EMAIL-V2 | Gmail Get Raw Message | `gmail v1` | *(removed)* | — | IMAP trigger provides raw directly |
| WF-REPLY-SEND-v1 | 20_gmail_send | `gmail v2.1` | 20_backend_send | `httpRequest` | POST /send-email |
| WF-REPLY-SEND-v1 | 21_retrieve_message_metadata | `gmail v2.1` | *(removed)* | — | /send-email returns Message-ID |
| WF-REPLY-SEND-v1 | 22_extract_message_id | code node | 22_extract_message_id | code node (simplified) | Parse /send-email response |
| WF-REPLY-SEND-v1 | 30_confirm (/sent) | httpRequest | 30_confirm (/sent) | httpRequest | **UNCHANGED** — provider="smtp" |
| WF-REPLY-SEND-v1 | Calculate Human Delay | code node | Calculate Human Delay | code node | **UNCHANGED** |
| WF-REPLY-SEND-v1 | Wait Until Send Time | wait node | Wait Until Send Time | wait node | **UNCHANGED** |

### Files Modified

| File | Change |
|---|---|
| `n8n/workflows/WF-INTAKE-EMAIL-V2.json` | Replace Gmail Trigger + Gmail Get Raw Message with IMAP Trigger; update code nodes |
| `n8n/workflows/WF-REPLY-SEND-v1.json` | Replace 20_gmail_send with HTTP Request to backend /send; remove 21/22 nodes; simplify 30_confirm |
| `backend-symfony/src/UI/Http/Communication/ReplyController.php` | Add `send` action (POST /reply/{id}/send) |
| `backend-symfony/src/Application/Communication/ReplyHandler.php` | Add `sendReply()` method using Symfony Mailer with threading headers |
| `backend-symfony/config/packages/mailer.yaml` | Configure Symfony Mailer with MAILER_DSN |
| `.env.dist` | Add HONEYPOT_IMAP_* variables + MAILER_DSN |
| `docker-compose.yml` | Forward IMAP vars to n8n, MAILER_DSN to backend |
| `docs/` (new file) | Provider setup guide with Gmail App Password walkthrough |

### Limitations

1. **No server-assigned Message-ID retrieval**: SMTP's `MAIL FROM`/`RCPT TO`/`DATA` protocol does not return the server-assigned Message-ID. The system generates a local Message-ID. If the SMTP server rewrites it, the stored Message-ID may differ from the one in the recipient's mailbox. This does not affect threading (which uses `In-Reply-To`/`References`).

2. **No Gmail Thread-ID equivalent**: Gmail's `threadId` is a proprietary concept. With SMTP, threading relies entirely on RFC 2822 headers. The `sent_headers.thread_id` field in the confirm payload will be `null` for SMTP-sent messages.

3. **No post-send metadata retrieval**: The `21_retrieve_message_metadata` node is removed because SMTP does not support "get sent message." To verify delivery, an operator would need to check the SMTP server logs or the Sent folder via IMAP -- out of scope for this feature.

4. **Polling latency**: IMAP polling introduces up to 60 seconds of latency (1-minute polling interval). Gmail's push-based trigger had near-instant delivery. For ScamBuster's use case (scambaiting, not time-critical), this is acceptable.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: ScamBuster successfully ingests emails from Gmail via IMAP with an App Password -- no Google Cloud Console OAuth setup required. Verified by sending a test email and confirming conversation creation.
- **SC-002**: ScamBuster successfully ingests emails from Outlook/O365 via IMAP. Verified by configuring Outlook credentials and ingesting a test email.
- **SC-003**: ScamBuster successfully ingests emails from a custom IMAP server (e.g., Dovecot). Verified by configuring custom server credentials and ingesting a test email.
- **SC-004**: Reply emails sent via SMTP appear in the correct thread in the recipient's email client. Verified by checking `In-Reply-To` and `References` headers on the sent email.
- **SC-005**: A new operator can configure ScamBuster email by editing `.env` only — zero n8n UI interaction for credentials. Verified by fresh deployment following spec 027 bootstrap, with only `.env` changes required from the operator. (Without spec 027, the IMAP credential must be created manually in n8n UI.)
- **SC-006**: Existing email parsing (RFC822 ingest via `/api/v1/communication/ingest/raw`) continues to work unchanged. Verified by running the existing integration test suite (`make test`).
- **SC-007**: Gmail setup time reduced from 45 minutes (OAuth) to under 5 minutes (App Password). Verified by timing a fresh setup following the documentation.
- **SC-008**: The `compositeHash` deduplication in the backend prevents duplicate ingestion if an email is fetched twice by IMAP (e.g., race condition before mark-as-read). Verified by existing dedup integration tests.

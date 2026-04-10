# Changelog - ScamBuster

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

---

## [2.14.0] - 2026-04-10

### Added

#### Attachment SHA256 linked into IOC pipeline (Spec 064)

Closes the spec 063 SC-008 known follow-up. Persisted attachments now
generate `observed_ioc` rows of type `sha256`, linked to the message
that carried them, immediately visible in the IOC Explorer UI and
included in STIX exports — no frontend change required.

**Why**: Spec 063 restored attachment **persistence** end-to-end, but
explicitly documented a known gap: the sha256 of persisted attachments
was NOT inserted as `observed_ioc` rows. As a result, attachments were
visible in the conversation detail view and via `AttachmentController::download`
but did **not** appear in the IOC Explorer. This was a P1 visible UX
gap — an analyst monitoring file-hash IOCs in real time could not see
new malicious files arriving via the honeypot.

**Implementation**: A new private method
`IngestHandler::linkAttachmentsAsIocs(Message, string $msgId, DateTimeImmutable $now): void`
iterates `$message->getAttachments()` and calls the existing
`IocUpsertService::upsertEnrichedIoc()` for each attachment, with
payload:

```
[
  'msg_id' => $msgId,
  'ioc' => [
    'type' => 'sha256',
    'value' => $contentHash,
    'value_norm' => strtolower($contentHash),
    'source' => 'attachment',
    'first_seen' => $now->format(DATE_ATOM),
  ],
  'enrichment' => [
    'attachment_id' => ..., 'filename' => ..., 'mime_type' => ..., 'size_bytes' => ...,
  ],
  'category' => 'file-hash',
  'tags' => ['attachment'],
  'tlp' => 'AMBER',
]
```

The new method is invoked from `IngestHandler::ingest()` **after**
`em->flush()` (the message must be in DB for `IocUpsertService` to
resolve it via the repository). The `IngestHandler` constructor gains
an optional `?IocUpsertService` injected via Symfony autowiring (no
`services.yaml` change). The `?` preserves backwards compat with
existing tests that may instantiate `IngestHandler` manually without
the new dependency.

**Defensive**: per-attachment try/catch on `InvalidArgumentException`
(logged at DEBUG, expected on outgoing messages thanks to spec 061
guards) and `\Throwable` (logged at WARNING for any other failure).
The mail ingestion has already succeeded by this point — losing IOC
linkage for one attachment is acceptable, losing the entire mail is
not.

**Spec 061 compliance**: `IocUpsertService` already enforces the
outgoing-message guard (Layer 1) and the honeypot filter (Layer 2).
The new linkage method does not duplicate these checks — it relies on
the existing service-level enforcement, with the catch making the
rejection silent for the legitimate outgoing-message case. The
permanent regression sentinel `NoIocFromOutgoingMessageTest` still
passes unchanged.

**Tests**: +3 EndToEnd tests on `IngestControllerTest`:
1. `test_ingest_raw_attachment_sha256_creates_observed_ioc` — single
   PDF, asserts 1 sha256 row in `observed_ioc` with correct `value_norm`
2. `test_ingest_raw_three_attachments_creates_three_observed_iocs` —
   PDF + DOCX + ZIP with 3 distinct hashes, asserts 3 rows
3. `test_ingest_raw_same_attachment_two_mails_documents_dedup_limitation`
   — sentinel test that documents an existing architectural quirk
   (see Known Limitation below)

The 13-assertion `test_ingest_raw_with_attachments` regression sentinel
from spec 063 continues to pass **unchanged**.

**Quality gates**: PHPStan L6 src clean (`make stan`), CS-Fixer clean,
audit grep clean, full unit+integration suite **2430/2430** green,
EndToEnd ingest **41/41** green (was 38, +3 new).

**Manual smoke validated on dev**: POST a mail with 1 PDF via curl →
`observed_ioc` contains the sha256 row linked to `msg_id` within
seconds. The new sha256 indicators are immediately visible in the IOC
Explorer UI, included in STIX exports, and queryable via the IOC
search/filter endpoints.

##### Known limitation — same PJ across multiple mails

The `attachment` table has a pre-existing `UNIQUE(content_hash)`
constraint (predates spec 063). Consequently, when two distinct mails
carry the same PDF (same sha256), `IngestHandler::processAttachments()`
**skips the second one entirely** at the existence check before persist.
The second message's `getAttachments()` returns empty, so the spec 064
linkage is a no-op for it.

**Result with the current schema**:
- 1 attachment row (the first mail wins)
- 1 `observed_ioc` row of type sha256 (linked to the FIRST message only)
- 1 indicator row with the sha256

**Threat-intel impact**: an analyst seeing 50 mails with the same
malicious PDF will see only 1 sha256 indicator linked to 1 message
instead of 50. This is a known limitation of the attachment dedup
strategy and is **out of scope for spec 064**. A future spec could
either:
- (a) introduce a many-to-many `message_attachment` join table, or
- (b) extend `processAttachments` to create per-message `observed_ioc`
  rows that point to the existing shared `attachment` row even when
  no new Attachment entity is created.

The new test `test_ingest_raw_same_attachment_two_mails_documents_dedup_limitation`
serves as a sentinel: it documents the current behavior in code and
will start failing if/when option (a) or (b) is implemented, forcing
the future author to update the assertions explicitly.

##### Files changed

- `backend-symfony/src/Application/Communication/IngestHandler.php`
  (+ optional `?IocUpsertService` constructor parameter, + new private
  `linkAttachmentsAsIocs()` method, + 1 call site after `em->flush()`)
- `backend-symfony/tests/EndToEnd/Communication/IngestControllerTest.php`
  (+ 3 tests)

**No schema change. No new dependency. No frontend change. No
modification to `IocUpsertService` itself.**

---

## [2.13.0] - 2026-04-10

### Fixed

#### End-to-end attachment capture restored (Spec 063)

Restores attachment capture that was **silently lost for 10 days**
(2026-03-31 → 2026-04-10) after commit `b090e31`
(`feat(n8n): replace Gmail nodes with IMAP trigger + backend /send-email`)
migrated the n8n intake workflow from the Gmail node (which fetched
full RFC822 with multipart binary parts) to the IMAP node with
`format: "simple"` (structured fields only). The replacement workflow
hardcoded `attachments: []` for every mail, the backend
`processAttachments()` silently no-op'd, and the `attachment` table
stayed empty (0 rows for 521 messages persisted) despite real mails
with attachments arriving in the honeypot mailbox.

Diagnosis confirmed by injecting test mails with 1 / 2 / 3 attachments
on 2026-04-10: zero rows in `attachment`, zero file-hash IOCs, zero
exception in any log — the attachment processing path was simply
never executed because the DTO arrived empty.

The fix is delivered in **4 atomic commits** on the
`063-backend-side-attachment` branch:

##### Step 1/4 — Backend `EmailParsingService::extractAttachments()`

New public method that uses the existing `zbateson/mail-mime-parser`
instance to extract attachments from raw multipart RFC822. Reuses
`MailMimeParser::getAllAttachmentParts()` (which already filters out
multipart containers, signature parts, and text/plain|html parts not
flagged as attachments). Adds an explicit exclusion for `inline` parts
to avoid persisting embedded HTML images. Reads attachment streams in
64 KB chunks with a configurable max-size guard
(default `DEFAULT_MAX_ATTACHMENT_SIZE_BYTES = 25 MB`). Defensive: any
parser failure is caught, logged as a WARNING, and returns `[]` —
never throws.

+12 unit tests covering empty cases, single PDF, three mixed
attachments, inline image ignored, text/calendar persisted, deeply
nested multipart, defensive malformed input, defensive random garbage,
size-limit enforcement (with injectable limit to avoid OOM in tests).

##### Step 2/4 — Backend `IngestHandler::ingest()` hybrid fallback

Wires the parser fallback into the ingestion pipeline. When the
upstream collector forwards an empty (or null) `attachments` array on
the DTO, `IngestHandler::ingest()` invokes
`EmailParsingService::extractAttachments()` on the raw RFC822 source
and substitutes the result before calling `processAttachments()`. When
the DTO already contains a non-empty array, the fallback is **skipped**
— the producer's array (which may carry strelka YARA hits, sandbox
results, etc.) keeps full precedence. Backwards-compatible: zero
breaking change to the `/ingest/raw` API contract.

+4 EndToEnd tests on `IngestControllerTest` covering:
- 1 PDF in raw_source with `dto.attachments=[]` → 1 row persisted
- 3 mixed PJ in raw_source with `dto.attachments=[]` → 3 rows persisted
- Regression sentinel: `dto.attachments` non-empty (with strelka marker)
  → fallback NOT invoked, DTO entry persisted with marker preserved
- Defensive: garbage raw_source → HTTP 201, 0 attachments, no crash

The pre-existing 13-assertion `test_ingest_raw_with_attachments`
sentinel continues to pass **unchanged** — its strelka/sandbox metadata
assertions prove the parser fallback is correctly skipped when
`dto.attachments` is non-empty.

##### Step 3/4 — Path-aware payload size limit + n8n crypto access

The backend `PayloadSizeLimitListener` previously enforced a global
1 MB hard cap on all HTTP request bodies. Appropriate for auth/admin
endpoints (DoS protection) but rejected real-world mail ingestion: a
single PDF attachment of 1 MB binary expands to ~1.4 MB after base64
+ JSON envelope overhead, immediately tripping the limit and causing
n8n to **silently drop the mail** (the IMAP node had already marked
it SEEN at fetch time, so no retry).

The listener now applies a path-aware limit:
- 1 MB default for all generic API endpoints (unchanged for security)
- **50 MB for `/api/v1/communication/ingest/*`** (matches the 25 MB
  binary attachment cap with headroom for base64 expansion + JSON
  envelope). Both limits are constructor-injectable for tests.

The listener also gained an injected `LoggerInterface` and now emits
a structured WARNING log entry on every 413 rejection (path, method,
content-length, remote addr, user-agent, hint), making oversized
rejections greppable in `var/log` instead of being silently dropped.
The 413 response body now also includes `received_bytes` and a `hint`
field so n8n logs and ops dashboards can correlate failures with
their source.

+2 new tests on `PayloadSizeLimitListenerTest`:
- `testIngestEndpointAcceptsLargePayload`: 5 MB POST on `/ingest/raw`
  is NOT 413 (path-aware higher limit applies)
- `testIngestEndpointRejectsExtremelyLargePayload`: 60 MB POST IS 413
  with `max_bytes=52428800` in the response

Infrastructure: `docker-compose.yml` n8n service gets a new env var
`NODE_FUNCTION_ALLOW_BUILTIN=crypto` so the new V5 Extract Email Data
node can `require('crypto')` to compute SHA256 of attachments. n8n's
vm2 sandbox blocks all built-in module requires by default; this
whitelists `crypto` specifically (scoped, not `*`) to keep the sandbox
tight.

##### Step 4/4 — n8n workflow `WF-INTAKE-EMAIL-V2` V5

Replaces the V3.3 nodes that lost attachment capture. The new V5 path:

1. **`IMAP Email Trigger`** — `format: simple` + `downloadAttachments: true`
   (NEW). n8n downloads each attachment as binaryData accessible via
   `item.binary[propertyName]`. We deliberately do **not** use
   `format: raw` even though it sounded appealing: the n8n IMAP V2 raw
   mode is **broken** — it does
   `find(message.parts, { which: 'TEXT' })` which in IMAP RFC 3501
   returns ONLY the body, not the headers. Confirmed by inspecting
   `EmailReadImap/v2/utils.js:147`. Without headers, the backend
   cannot parse from / subject / date and reply generation fails
   with `Cannot determine reply recipient`.

2. **`Extract Email Data`** node — V3.3 → V5 (~95 lines, +18 vs V3.3):
   - Reads `item.metadata` + `item.from` + `item.subject` +
     `item.textPlain` (parsed by n8n via mailparser, no manual MIME
     walking required)
   - Iterates `$input.item.binary` to access downloaded attachments
   - Computes sha256 of each attachment via `require('crypto')`
     (Node built-in, enabled by step 3/4)
   - Populates `attachments: [{filename, mime_type, size_bytes, sha256}]`
     in the output JSON

3. **`Prepare Payload`** node — **UNCHANGED**. The existing V5 already
   had `attachments: emailData.attachments || []` so it automatically
   picks up the now-populated array from Extract Email Data without
   any modification. Clean separation of concerns.

End-to-end behavior: n8n downloads attachments via mailparser → exposes
as binaryData → Extract Email Data computes sha256 in JS via Node
crypto → Prepare Payload forwards the populated `attachments` DTO +
the reconstructed-headers RFC822 (no multipart binary inside, payload
stays small ~2-5 KB regardless of attachment size) → backend uses the
existing DTO precedence path.

The spec 063 backend parser fallback (steps 1-2) is **not invoked**
for this workflow specifically (DTO precedence wins) but remains
valuable as defense in depth for any other producer that forwards
full RFC822 bytes with empty `attachments`. Both code paths are
covered by tests.

##### End-to-end validation on live dev backend

- Mail with 1 PDF (`sample.pdf`, 18 KB) → 1 attachment row, correct
  sha256, subject + from correctly parsed, reply generation works
- Mail with 3 attachments (1 STIX 17 KB + 1 STIX **1.01 MB** + 1 STIX
  27 KB) → 3 attachment rows, all sha256 distinct, all sizes match
  source files. The 1.01 MB STIX bundle previously triggered the 1 MB
  hard cap; now passes cleanly.

##### Quality gates

- PHPUnit 2430 / 2430 unit+integration green (was 2428, +2 listener
  tests)
- 38 / 38 EndToEnd ingest tests green (was 34, +4 fallback tests)
- 12 / 12 unit tests on the new `extractAttachments()` method
- Existing 13-assertion `test_ingest_raw_with_attachments` regression
  sentinel still green, **unchanged**
- PHPStan L6 src clean (`make stan`)
- PHP-CS-Fixer clean on touched files
- Audit grep clean
- n8n workflow validator clean (placeholders restored after live export)

##### Known follow-up — spec 064 (separate)

The sha256 of persisted attachments is NOT yet inserted as
`observed_ioc` rows of type `sha256`. The spec assumed an existing
`processAttachments → IocUpsertService` chain that does not actually
exist in the codebase. This is a separate gap (~spec 064) and is
intentionally **out of scope for spec 063**, which only restored the
attachment **persistence** capability. Attachments are visible in
`AttachmentController::download` and the conversation detail API
today, but do not yet appear in the IOC Explorer.

##### Files changed

- `backend-symfony/src/Application/Communication/EmailParsingService.php`
  (+ method, + constructor parameter, + 25 MB default size constant)
- `backend-symfony/src/Application/Communication/IngestHandler.php`
  (+ fallback substitution between parseEmail and processAttachments)
- `backend-symfony/src/EventListener/Security/PayloadSizeLimitListener.php`
  (+ path-aware ingest limit, + injected logger, + structured 413
  response body)
- `backend-symfony/tests/Unit/Application/Communication/EmailParsingServiceExtractAttachmentsTest.php`
  (new, 12 tests)
- `backend-symfony/tests/EndToEnd/Communication/IngestControllerTest.php`
  (+ 4 fallback tests)
- `backend-symfony/tests/Integration/Security/PayloadSizeLimitListenerTest.php`
  (+ 2 ingest endpoint tests)
- `n8n/workflows/WF-INTAKE-EMAIL-V2.json` (IMAP node:
  `downloadAttachments: true`; Extract Email Data: V3.3 → V5 with
  Node crypto sha256)
- `docker-compose.yml` (n8n service: `+NODE_FUNCTION_ALLOW_BUILTIN=crypto`)

**No schema change. No new Composer/npm dependency. No frontend change.**

---

## [2.12.0] - 2026-04-10

### Changed

#### MITRE ATT&CK mapping refresh (Spec 062)

Refresh of `lkp_scam_type.attck_technique` to remove deprecated and
semantically wrong technique IDs identified by the CTI expert audit
(2026-04-10). Previously, several scam types referenced techniques that
were either retired from MITRE ATT&CK or semantically incorrect, causing
the exported STIX bundles to either reference invalid IDs or to silently
omit the attack-pattern object entirely (when the wrong ID wasn't in
`ThreatActorStixBuilder::MITRE_TECHNIQUES`).

**Mapping changes**

| Scam type | Old | New |
|---|---|---|
| `INVOICE_FRAUD` | `T1534` (insider, wrong) | `T1566.002` (Spearphishing Link) |
| `CEO_FRAUD` | `T1534` | `T1566.002` |
| `TECH_SUPPORT` | `T1566.004` (retired) | `T1656` (Impersonation) |
| `ROMANCE` | `T1566.001` (wrong, no attachment) | `T1656` |
| `LOTTERY` | `T1566.001` | `T1656` |
| `CHARITY` | `T1566.001` | `T1656` |
| `ADVANCE_FEE_419` | `T1566.001` | `T1656` |
| `INVESTMENT` | `T1566.002` | `T1656` |

`PHISHING`, `PHISH_CREDENTIALS`, `PHISH_MALWARE`, `JOB_OFFER` mappings
unchanged (already correct).

**Implementation**
- New irreversible forward migration `Version2026041100000000.php` with two
  UPDATE statements. `down()` throws `IrreversibleMigration` — restoring
  incorrect mappings would damage the credibility of the STIX feed
- `ScamTypeFixtures.php` updated to seed the new mapping for the test DB
- `ThreatActorStixBuilder::MITRE_TECHNIQUES` adds `T1656` (Impersonation,
  added in MITRE ATT&CK v14, October 2023). Previously, when a scam type
  was mapped to T1534/T1566.004, `buildAttackPatterns()` returned `[]`
  silently and the resulting STIX bundle had a missing attack-pattern.
  Now the new mappings produce real attack-pattern objects.
- `preprod_reference_data.sql` updated to remove the explicit T1566.004
  reference and fill in previously NULL `attack_id` values

### Tests
- 5 new backend tests in `MitreMappingTest`:
  - `testNoT1534InAnyScamType`
  - `testNoT1566004InAnyScamType`
  - `testT1656MappedForImpersonationScams` (6 scam types)
  - `testInvoiceAndCeoFraudMappedToT1566002`
  - `testThreatActorStixBuilderEmitsT1656AttackPattern`
- 2416 backend tests, 0 errors, 11 skipped (baseline)

### Compatibility
- `T1656` (Impersonation) was added to MITRE ATT&CK v14 (October 2023).
  Modern OpenCTI releases (≥ 5.10) support it. Operators on older versions
  should upgrade before consuming the refreshed bundles.

### Out of scope
- Historical migrations `Version20260325151254` and `Version20260406180000`
  are immutable by Doctrine convention. The new forward migration corrects
  the live state without rewriting them.
- `observed_ioc.context_observation` JSON cached values are not rewritten.
  New extractions use the corrected mapping; analysts re-export STIX
  bundles regularly so the correction propagates naturally.

---

## [2.11.0] - 2026-04-10

### Changed

#### IOC extraction skip platform mails (Spec 061)

Two-sprint hardening of the IOC extraction pipeline to eliminate platform
contamination — IOCs that should never have been ingested in the first place
(the honeypot's own email address re-extracted from message bodies, and any
data from outgoing reply messages).

**Sprint 1 — Preventive guards (defense in depth)**
- New `Message::canExtractIocs(): bool` domain helper (true iff `direction='in'`)
- `IocUpsertService::upsertEnrichedIoc()` is now the single funnel that
  enforces both layers — throws `\InvalidArgumentException` on outgoing messages
  or honeypot email matches, caught by callers as HTTP 400
- Layer 1 (direction guard) at the 3 admin/debug entry points:
  `MigrateHeaderIocsCommand` (QueryBuilder filter), `MessageController POST
  /extract-iocs` (400), `IocController POST /iocs/enriched` (400)
- Layer 2 (honeypot identity filter) at upsert time: `IocUpsertService` reads
  the new `HONEYPOT_EMAIL_ADDRESSES` env var (csv), normalises lowercase, and
  rejects any email IOC whose `value_norm` matches — case-insensitive
- Bonus fix: `IocHandlerTest::testGetConversationIocsDeduplicates` was itself
  an example of the bug (created `direction='out'` and called upsertEnrichedIoc).
  The test silently masked the contamination. Fixed to use 2 incoming messages.

**Sprint 2 — One-time historical cleanup + permanent guard**
- New `app:indicator:cleanup-platform-contamination` command with phases:
  - Phase 5: delete every `observed_ioc` referencing an outgoing message
  - Phase 6: delete every indicator that becomes orphan after phase 5
  - Phase 7: delete every indicator matching a configured honeypot address
    (mixed-origin indicators correctly preserved — only outgoing observation
    deleted, incoming kept)
- Safety: `--dry-run`, `--no-csv`, `--no-confirm`, `--honeypot-address` overrides;
  audit CSV in `var/audit/061-cleanup-{timestamp}.csv` before any delete;
  interactive confirmation prompt; single transaction with rollback
- Permanent anti-regression test
  (`tests/Integration/Communication/NoIocFromOutgoingMessageTest`) asserts
  zero `observed_ioc` on outgoing messages, runs as part of `make test`

### Tests
- 15 new backend tests (TDD red→green)
  - `MessageCanExtractIocsTest` (unit, 2)
  - `MigrateHeaderIocsCommandSkipOutgoingTest` (1)
  - `IocUpsertServiceHoneypotFilterTest` (5 — case-insensitive, non-email
    bypass, empty list no-op, regression)
  - `MessageControllerTest` +2 (outgoing 400 + incoming regression)
  - `IocControllerTest` +2 (outgoing 400 + incoming regression)
  - `CleanupPlatformContaminationCommandTest` (4 — dry-run, real run, mixed
    origin, idempotency)
  - `NoIocFromOutgoingMessageTest` (1, anti-regression)
- 2410 backend tests, 0 errors, 11 skipped (baseline)
- Real e2e validation on dev DB: 3 distinct test mails sent through n8n
  pipeline (Layer 2 stress, regression, case-insensitive); 17 IOCs created,
  zero honeypot pollution, all expected scammer IOCs captured

### Cleanup results on dev DB
- 2 historical honeypot indicators deleted
  (`valeris.conseil@gmail.com`, `scamtest.scambuster@gmail.com`)
- 141 cascade observations removed
- 0 outgoing observations to clean (Sprint 1 had already prevented)
- Idempotent: second dry-run reports "Nothing to clean"

### Configuration
- New env var `HONEYPOT_EMAIL_ADDRESSES` (csv, lowercase, exact-match)
- Symfony parameter `app.honeypot_email_addresses` injected globally via bind
- Default empty (no-op for fresh deployments — operators must opt in)

### Out of scope (deferred to follow-up specs)
- MITRE ATT&CK mapping refresh (T1534 deprecated, T1656 missing for
  impersonation scams) → **spec 062**

---

## [2.10.0] - 2026-04-10

### Changed

#### STIX Export Hardening (Spec 060)

Two-sprint hardening of conversation, IOC explorer, and cluster STIX exports
based on three independent CTI expert audits. Eliminates O(n²) graph noise and
correctly attributes clustered conversations to their shared threat-actor.

**Sprint 1 — Mesh removal**
- `ConversationStixExportHandler`: dropped the double-for loop that emitted a
  full mesh of `relationship_type=related-to` between every pair of indicators
  in a conversation (O(n²) growth, OpenCTI graph pollution)
- `IocStixExportHandler`: deleted private `buildRelationships()` and its
  co-occurrence SQL query (capped at LIMIT 100 but still useless noise)
- Conversation `report.object_refs` already conveys co-occurrence — no
  information lost
- Bulk IOC export of 2139 indicators went from ~2M potential relationships to 0

**Sprint 2 — Cluster attribution**
- `ConversationStixExportHandler` now resolves the cluster (if any) for the
  exported conversation via new `ClusterQueryService::getClusterIdForConversation()`
- Clustered conversations attach `indicates` relationships to the **cluster**
  threat-actor (shared with all sibling conversations) instead of producing a
  per-conversation singleton — OpenCTI graph collapses to 1 actor node
- New `ClusteredThreatActorStixBuilder::buildThreatActorObjects()` returns
  `{threat_actor, attack_patterns, relationships}` for embedding in a
  conversation bundle (no indicator objects — they belong to the conv bundle)
- Singleton branch (unclustered conversations) now uses
  `ThreatActorStixBuilder::buildSingleton()` with new naming convention
  `Unattributed Scam Actor (Title Case Type)` instead of the unreadable
  `ScamBuster Actor - SCAM_TYPE #shortid`
- STIX `id` unchanged across both branches (deterministic UUID v5) — OpenCTI
  dedup preserved across migration

### Tests
- 27 new backend tests (TDD red/green per sprint)
  - Sprint 1: 6 integration tests (`ConversationStixExportHardeningTest`,
    `IocStixExportHardeningTest`) — assert zero `related-to` indicator↔indicator
  - Sprint 2: 4 cluster delegation integration tests
    (`ConversationStixExportClusterDelegationTest`), 7 unit tests for
    `buildThreatActorObjects`, 7 unit tests for `buildSingleton`
- Visual validation on 7 STIX bundles exported from production UI:
  3 cluster exports, 1 bulk IOC export (2139 IOCs), 2 singleton conv exports,
  1 clustered conv export — all green
- 2374 → 2394 backend tests, 0 regression
- Zero new LLM cost (verified)

### Out of scope (deferred to follow-up specs)
- Honeypot/platform-mail IOCs still leak into exports
  (`scamtest.scambuster@gmail.com`, `+555*` phone numbers) → **spec 061**
  (preventive ingestion fix + historical cleanup command)
- MITRE ATT&CK mapping refresh (T1534 deprecated, T1656 missing for
  impersonation scams) → **spec 062**

---

## [2.9.0] - 2026-04-10

### Added

#### Cluster Detail Enrichment (Spec 059)

Enriches the cluster detail page with behavioral context from `ioc_context`
table. Zero new LLM calls — pure aggregation of existing enrichment data.

**Sprint 1 — Quick wins**
- Navigation icon `↗` on each anchor IOC → opens IOC Detail (filter behavior preserved on row click)
- "Campaign Excerpts" section: distinct context excerpts with `×N` occurrence count and source conversation link
- Active anchor IOC visually highlighted (border-l-4, bg-accent, badge inversion)
- Fix `IocDetail` Behavioral Signals: removed bullet prefix `○`/`●` rendering as `=`

**Sprint 2 — Behavioral Profile**
- New `ClusterQueryService::computeBehavioralProfile()` aggregating dominant stimulus, avg urgency, dominant revelation turn, hesitation/language switch counts, templated excerpt count
- New `ClusterQueryService::computeAnchorBehaviors()` per-anchor IOC aggregation (semantic role, stimulus, urgency)
- Frontend "Threat Profile" section with 6 fields, conditional on `behavioral_profile != null`
- Per-anchor behavioral pills (semantic role + stimulus + urgency)
- Uses PostgreSQL `MODE() WITHIN GROUP` and `FILTER (WHERE)` for aggregations

**Sprint 2.5 — Bug fix + visual polish**
- **Critical fix**: `hesitation_count`, `language_switch_count`, `dominant_stimulus_count` now use `COUNT(DISTINCT m.conv_id)` (was counting `ioc_context` rows, could exceed total conversations)
- Primary tactic as colored stimulus badge
- Avg urgency mini progress bar (green/amber/red)
- Template signal as warning badge with icon
- Anchor IOC pills as colored badges (semantic role + stimulus)
- Campaign Excerpts with left border accent + amber `×N` badge
- New shared `iocContextLabels.ts` lib (ROLE_COLORS, STIMULUS_COLORS, urgency helpers)

### Tests
- 12 new backend tests (Sprint 1: 6, Sprint 2: 12, Sprint 2.5: regression test for distinct count bug)
- 13 new frontend tests
- 2368 backend tests, 104 frontend tests, all passing
- Zero new LLM cost (verified)

### Out of scope (deferred)
- T1566.001 mismatch on Romance/Bitcoin clusters — requires dedicated MITRE ATT&CK mapping spec
- Cross-cluster template signal detection
- Cluster annotations / analyst notes

---

## [2.8.0] - 2026-04-09

### Added

#### Threat Actor Clustering (Specs 058a/b/c)

Real-time IOC-based conversation clustering using Union-Find algorithm on HIGH-severity financial IOCs (IBAN, crypto wallets, phone numbers). Conversations sharing anchor IOCs are grouped into threat-actor clusters.

**Domain & Algorithm (058a)**
- `IocClusteringService`: real-time clustering during email ingestion (< 1ms overhead)
- Union-Find with transitive merging (conversations linked via shared IOCs)
- `NormalizedIocValue` value object (IBAN strip spaces/dashes, ETH lowercase, phone digits-only)
- `ClusterStixIdGenerator`: deterministic UUID v5 for STIX threat-actor IDs
- PostgreSQL advisory locks for race condition prevention
- 3 new tables: `threat_actor_cluster`, `threat_actor_cluster_conversation`, `threat_actor_cluster_ioc`
- Critical index `idx_observed_ioc_indicator_id` (query time: 0.53ms)
- `app:clustering:backfill` command with `--dry-run` and `--limit` options
- Mega-cluster guard: clusters > 50 conversations flagged as SUSPECT
- BIC demoted from HIGH to MEDIUM (bank identifier, not threat actor indicator)
- Whitelist: USDT/USDC/WETH contract addresses + fictional 555 phone numbers excluded

**STIX Export & TAXII (058b)**
- `ClusteredThreatActorStixBuilder`: STIX 2.1 threat-actor with `cluster_type: "consolidated"` extension
- TAXII 3rd collection `threat-actors` (UUID `a1b2c3d4-0003-4000-8000-000000000003`)
- Full STIX bundle: threat-actor + indicators + attack-patterns + relationships (indicates, uses)
- `indicator_types: ["malicious-activity", "attribution"]` on all anchor indicators
- Extension-definitions for `x_scambuster_actor` and financial IOC patterns
- MITRE mapping corrected: removed T1534 (Internal Spearphishing) and T1566.004 (Vishing)
- `app:clustering:export-stix` command with `--cluster-id`, `--since`, `--output` filters
- Weighted goals: only scam types >= 10% frequency contribute to threat-actor goals

**API & Frontend (058c)**
- 5 API endpoints: `/clusters`, `/clusters/stats`, `/clusters/{id}`, `/clusters/{id}/export/stix`, `/iocs/{indicatorId}/cluster`
- Clusters list page with KPI cards (Active Clusters, Clustered Conversations, Unclustered, Actor Deduplication)
- Cluster detail page with anchor IOCs (real values visible) + conversations panel
- Clickable anchor IOCs to filter conversations by shared IOC
- Sort (Risk, Scam Type, Status) + Scam Type dropdown filter on conversations
- SUSPECT badge with tooltip explanation
- Conditional "Actor Deduplication" metric (124 → 24, -80.6%)
- Hover tooltips on all KPI cards explaining each metric
- STIX export button (authenticated download, consistent with Export CSV style)
- Sidebar navigation entry + i18n (EN/FR)

**Operations**
- Scheduler: clustering backfill every 30 minutes (fast inner loop)
- Demo: automatic backfill after `make demo-load` and Railway `docker-entrypoint-demo.sh`
- IngestPostProcessor: real-time clustering after IOC extraction

### Tests
- 125 new clustering tests (96 domain + 18 STIX + 7 API + 4 audit fixes)
- TDD strict: every test written before code
- Core query performance: 0.53ms (EXPLAIN ANALYZE verified)
- Coverage: NormalizedIocValue 100%, ClusterStixIdGenerator 100%, IocClusteringService 95%

---

## [2.7.0] - 2026-04-09

### Added

#### UX Hardening — Expert CTI/UX Audit (Specs 051–057)

Complete UX overhaul across all frontend screens, driven by a dual UX/UI + CTI expert audit (4 rounds of review, 60+ items addressed).

**Conversations List (051)**
- Scam type colored badges (PHISHING → "Phishing" amber, INVOICE_FRAUD → "Invoice Fraud" red, etc.)
- Risk score mini progress bar (green → amber → red)
- Persona column truncation + tooltip
- Precise timestamps ("Apr 7, 14:23" instead of "1d ago")
- Facet filters: Status + Scam Type dropdowns with URL persistence
- Filtered result counter ("6 conversations · open · Phishing")
- IOC count column (backend batch query, infra IOCs excluded)
- Column sorting on Risk, IOCs, Messages, Last Activity with ▼/▲/⇅ indicators
- Header counters fixed (45 active + 31 closed + 2 abandoned = 78 total)
- Full row clickable → conversation detail

**Conversation Detail (052)**
- Scam type + Persona in Session Metadata
- "4 messages (2 exch.)" — unified vocabulary
- Infrastructure IOC filtering (honeypot email, DMARC/SPF/DKIM → collapsed "Email Auth" section)
- SCAMMER / SENTINEL labels + teal tint on honeypot messages
- STIX pattern full display + clipboard copy button
- "← Back to Intelligence" + Threat Actor summary on IOC detail
- Agent Decision Log + Double Validation Pipeline removed (hardcoded, no real data)
- "Show full message" toggle on truncated messages

**IOC Explorer + Detail (053, 054)**
- IOC type acronyms normalized (IPv4, IBAN, BIC, URL, SHA256, Wallet BTC...)
- Precise timestamps in LAST SEEN column
- Column sorting on Score, Confidence, Last Seen
- "No external detections" contextual message based on IOC age
- Observation Timeline hidden for < 3 occurrences
- Context tab format fixes (LLM, "Turn 1 · Initial email", humanized roles, confidence color)
- Observations tab: badges for status, scam type, dates
- Financial IOC filter category (IBAN, BIC, Wallet, Bank Account)
- URL/Domain: defanged value as primary, active URL with warning
- Scoring section restructured: External Sources (VT /72 engines) + ScamBuster Scoring
- Co-occurrence graph: increased height, tooltip, color legend
- CTX badge replacing ✨ sparkle icon

**IOC Severity System (transversal)**
- Backend `IocConfidenceCalculator::computeSeverity()` — type-based severity:
  HIGH (IBAN, crypto wallet, phone), MEDIUM (URL, domain, email, IP), LOW (metadata)
- Frontend shared `iocSeverity.ts` utility mirroring backend logic
- 54 tests (26 backend + 28 frontend)

**Pre-Demo Polish (055)**
- IOC Detail header badges normalized (Wallet BTC, Phish / Malware)
- Stimulus humanized (URGENCY_PRESSURE → "Urgency Pressure")
- IOC inline panel: scam type + date format fixed
- Abandoned status tooltip

**Personas & Bandit (056)**
- KPI renamed: "Convergence Rate" → "Best Avg Reward"
- Performance Matrix sorted by pulls desc + avg reward desc
- Low-pull lines (< 3 sessions) dimmed with statistical note
- "iocs sensibles" → "high-value IOCs"
- Convergence History: scam types + personas normalized, STATUS column hidden
- **Dominance Evolution chart** with scam type selector (Recharts LineChart)
- Persona detail: Performance BY SCAM TYPE first, System Prompt in accordion
- "Created by: fixture" → "System", dates non-ambiguous
- "Algorithm in pure exploration mode" note under MIN_PULLS

**Monitoring & Dashboard (057)**
- Snake_case normalization across all monitoring screens (Analytics, Conversation Monitoring, Operations Dashboard, Pipeline Monitor, Impact)
- Injection Monitor: amber banner when 0 messages analyzed
- Pipeline Monitor: N/A for metrics when 0 replies
- Impact: EXCLUSIVE IOCS tooltip definition

### Changed
- CLOSED conversation badge: red → neutral gray (semantic correction)
- IOC count in conversation list excludes infrastructure IOCs (DMARC/SPF/DKIM, @scambuster.local)
- Conversation detail: IOC count shows filtered count (consistent with list)

### Fixed
- Bug: ObservedIoc array access in ReplyContextService (real bug found by PHPStan L7)
- Bug: IocDetail aggScore reference breaking production build
- Header counters: 45+31≠78 resolved (2 abandoned conversations)

---

## [2.6.0] - 2026-04-07

### Added

#### Threat Actor Frontend Display (Feature 044b)
- **ThreatActorCard** on ConversationDetail: collapsible card showing sophistication badge, goals, MITRE ATT&CK link, description, engagement metrics, persona used
- **ThreatActorSummaryCard** on IocDetail: aggregated attribution summary across all conversations where the IOC was observed (scam types, goals, max sophistication, MITRE techniques)
- Card visible when IOC is selected in ConversationDetail right panel
- `useThreatActorProfile` hook: fetches STIX bundle and extracts threat-actor client-side
- `useThreatActorSummary` hook: aggregates up to 5 conversation threat-actors for IOC attribution

#### TAXII IOC Feed Enrichment
- TAXII IOC collection now includes `threat-actor`, `attack-pattern`, and `indicates` relationships alongside indicators
- For each unique conversation behind the IOCs in a batch, a threat-actor is built on-the-fly
- Integration test `ConversationStixExportWithActorTest` (7 tests)

### Changed
- Removed `attributed-to` relationship from STIX export (rejected by OpenCTI: Report→Threat-Actor-Group not allowed)
- Added threat-actor and attack-pattern to report `object_refs` for OpenCTI container linking
- TAXII limit parameter now applies to indicators only (enrichment objects are additional)
- Campaign UI hidden from frontend (pipeline disconnected): stat cards removed from Dashboard and Impact, routes commented out
- Removed vanity comparison banner from Impact page

### Removed
- Deleted `GenerateDemoDataCommand` (1703 LOC, obsolete — replaced by `LoadDemoDataCommand`)

---

## [2.5.0] - 2026-04-06

### Added

#### STIX Threat Actor Export (Feature 044)
- **STIX 2.1 threat-actor objects** in conversation STIX exports
- Each conversation produces a `threat-actor` with sophistication scoring, goals mapping, and behavioral profile from IOC context enrichment (feature 043)
- `ThreatActorStixBuilder`: builds threat-actor, attack-pattern (MITRE ATT&CK, TLP:WHITE), and relationships
- `x_scambuster_actor` STIX extension with persona, engagement metrics, IOC type diversity
- **2 relationship types**: threat-actor→uses→attack-pattern, indicator→indicates→threat-actor
- Deterministic UUIDs for OpenCTI/MISP deduplication
- Backward compatible: `?include_threat_actor=false` on conversation export
- Description enriched with IOC context excerpts (feature 043 integration)

### Changed
- Completed MITRE ATT&CK mapping for 6 scam types (INVOICE_FRAUD→T1534, ROMANCE/LOTTERY/CHARITY/ADVANCE_FEE_419→T1566.001, INVESTMENT→T1566.002)
- Fixed `ActorProfileGenerator` bugs: column reference (message_id→msg_id), direction ID (hardcoded 3→dynamic subquery)

---

## [2.4.0] - 2026-04-06

### Added

#### Rich Contextual IOC Bundle (Feature 043)
- **Structural IOC context** computed at extraction time: revelation turn, scam type, persona, extraction method, engagement duration, co-revealed IOC types, campaign link
- **LLM semantic enrichment** via gpt-4o-mini (1 call per message): semantic role (PAYMENT_DESTINATION, PHISHING_CREDENTIAL_URL, CONTACT_CHANNEL, etc.), stimulus type (PASSIVE, URGENCY_PRESSURE, TRUST_BUILDING, etc.), scammer urgency score, hesitation/language switch detection, PII-free context excerpt
- **Confidence calibration**: analysis confidence capped based on available context window (max 0.60 for first-contact with no conversational history)
- **PII anonymization**: MessageAnonymizer strips emails, IBANs, phones, crypto wallets before LLM analysis; output validated post-LLM
- **IOC Context API**: `GET /api/v1/iocs/{indicatorId}/context` returns structural + semantic context per observation
- **Context tab** in IOC Detail (frontend): revelation context with turn indicator, semantic role with color coding, stimulus type, behavioral signals (urgency bar, hesitation, language switch), context excerpt, co-revealed IOCs
- **IOC Explorer enhancements**: sparkle indicator on IOCs with context, "Has context" filter checkbox, `has_context` boolean in list response
- **STIX export**: `x_scambuster_context` extension on indicators with scam type, persona, turn ratio, semantic role, stimulus type, urgency score
- **TAXII feed**: context extension included by default on all IOC objects
- **Batch command**: `app:ioc:compute-context --with-llm --budget-usd=1.00` for backfill with budget cap
- **Scheduler**: context computation with LLM enrichment runs every 6 hours
- **i18n**: full English and French translations for all context UI labels

### Changed
- `IocUpsertService` now triggers structural context + LLM enrichment at n8n IOC upsert time
- `IocHandler::extractIocsFromMessage()` runs 1 LLM enrichment call per message (not per IOC)
- `extraction_method` normalized: n8n pipeline correctly labeled as `llm` instead of generic `extraction`
- n8n workflow `WF-EXTRACT-AND-ENRICH-IOC` timeout increased from 30s to 120s

---

## [2.3.0] - 2026-04-04

### Added

#### TAXII 2.1 Server (Feature 040 -- MT-7)
- **4 TAXII 2.1 endpoints** for automated CTI feed consumption by OpenCTI, MISP, TheHive, SIEM:
  - `GET /api/v1/taxii2/` -- Server discovery
  - `GET /api/v1/taxii2/api/` -- API root
  - `GET /api/v1/taxii2/api/collections/` -- 2 collections (IOCs + Campaigns)
  - `GET /api/v1/taxii2/api/collections/{id}/objects/` -- STIX 2.1 objects with `added_after` delta sync
- JWT authentication + `#[IsGranted('ioc:read')]` on all TAXII endpoints
- Content-Type `application/taxii+json;version=2.1`, pagination headers (`X-TAXII-Date-Added-First/Last`)
- STIX pattern mapping for 8+ IOC types (domain, URL, IPv4/6, email, SHA256, MD5, SHA1)
- 9 new integration tests
- Full documentation: `docs/16_taxii_server.md` with OpenCTI/MISP/SIEM integration guides

#### MFA TOTP (Feature 040 -- MT-11)
- **Two-Factor Authentication** via TOTP (Time-based One-Time Password) for admin accounts
- `POST /api/v1/2fa/setup` -- Generate secret + QR URI for authenticator app
- `POST /api/v1/2fa/verify` -- Validate 6-digit code from authenticator
- `POST /api/v1/auth/2fa/login` -- Full login with email + password + TOTP code
- LoginController returns `requires_2fa: true` when TOTP is enabled (instead of tokens)
- **Not enabled by default** -- demo users unaffected, opt-in activation only
- Backward compatible: users without TOTP log in normally
- 6 new integration tests

#### Fine-Grained RBAC (Feature 039 -- CT-1)
- **`#[IsGranted]` annotations** on 37 controllers with 12 Permission enum values
- Per-method annotations on multi-action controllers (ConversationController, MessageController, ReplyController, AttachmentController)
- PermissionVoter updated to handle both User entity and InMemoryUser (test environment)
- UserFixtures grants all permissions to standard user for n8n compatibility

#### Infrastructure Hardening (Feature 039 -- CT-5/6/9/10/11)
- **Dependabot** enabled (monthly, 5 PRs max, direct deps only)
- **n8n image pinned** to 1.114.3 (was `latest`)
- **Trivy + CycloneDX SBOM** in CI pipeline (new `container-security` job)
- **GOVERNANCE.md** + **MAINTAINERS.md** + **.github/FUNDING.yml** created

### Changed

#### IocHandler Decomposition (Feature 039 -- CT-0)
- **IocHandler.php** (1277 LOC) decomposed into 4 services:
  - `IocQueryService` (445 LOC): list, detail, co-occurrence, conversation IOCs
  - `IocUpsertService` (320 LOC): upsert, dedup, header extraction
  - `IocExtractorOrchestrator` (274 LOC): regex/LLM/hybrid extraction + derivation
  - `IocEnrichmentService` (171 LOC): risk scoring, enrichment updates
- IocHandler (165 LOC) is now a thin facade delegating to the 4 services

#### IngestHandler Decomposition (Feature 040 -- MT-1)
- **IngestHandler.php** (891 LOC) decomposed into 3 services:
  - `EmailParsingService` (200 LOC): RFC822 parsing, HTML-to-text, language detection
  - `ThreadResolverService` (337 LOC): threading, conversation create/reopen
  - `IngestPostProcessor` (285 LOC): IOC extraction, classification, risk scoring, injection detection
- IngestHandler (341 LOC) is now an orchestrator

#### ReplyHandler Decomposition (Feature 040 -- MT-2)
- **ReplyHandler.php** (941 LOC) decomposed into 3 services:
  - `ReplyContextService` (276 LOC): conversation context, persona assignment
  - `ReplyCadenceService` (191 LOC): kill switch, cadence, rate limits, safelist
  - `ReplyCompositionService` (337 LOC): compose headers, mark sent, send email
- ReplyHandler (287 LOC) is now an orchestrator

#### DDD Architecture (Feature 039 -- CT-7/CT-8)
- **EntityManager removed** from all 6 controllers that had it (dedicated handlers created)
- **5 repository interfaces** added in Domain/ (Conversation, Message, ObservedIoc, Persona, Campaign) with Doctrine implementations

### Fixed

#### Security (Feature 039 -- CT-2)
- **Login rate limiting** now Redis-backed via `RateLimiterFactory` (was inoperant `static $attempts`)
- `Makefile` stan target uses `--memory-limit=512M` (matches CI)

#### Compliance (Feature 039 -- CT-3)
- **GDPR retention** aligned with constitution: soft-delete at 6 months (was 2 years), hard-delete at 12 months (was 5 years)

#### Monitoring (Feature 040)
- **PipelineTraceHandler** uses dynamic direction lookup (was hardcoded ID `2`, should be `4`)

---

## [2.2.1] - 2026-04-03

### Fixed

#### STIX Export
- **IOC Explorer STIX export**: new "Export STIX" button exports filtered IOCs as a STIX 2.1 bundle directly from the IOC Explorer page (not just per-conversation)
- Fixed UTF-8 encoding issue in STIX report names (em dash replaced with hyphen)
- CS-Fixer: single quotes for SQL strings without interpolation + PHPDoc alignment

---

## [2.2.0] - 2026-04-03

### Added

#### IOC Explorer UI Overhaul (Feature 037)
- **IOC Detail Page** with 3 tabs: Overview (scoring bars, MISP mapping, STIX pattern), Observations (linked conversations), Related IOCs (co-occurrence table)
- **Co-occurrence Graph**: custom SVG radial layout showing IOC relationships, colored by type, clickable nodes
- **Observation Timeline**: Recharts ScatterChart showing when each IOC was observed, colored by extraction method
- **Advanced Filters**: severity (High/Medium/Low), confidence threshold (>0.9/>0.7/>0.5), date range (7d/30d/90d), hide header IOCs toggle
- Direct navigation from IOC list to detail page (removed intermediate side panel)
- "View full IOC detail" link in Conversation Detail IOC panel
- `GET /api/v1/iocs/{indicator_id}/detail` endpoint
- `GET /api/v1/iocs/co-occurrence` endpoint (graph data)

#### STIX 2.1 Full Conformity (Feature 038)
- **StixBundleBuilder** service generating OpenCTI-compatible STIX 2.1 bundles
- TLP marking-definitions with OpenCTI well-known UUIDs
- Indicators with: name, valid_from, valid_until (from decay config), confidence, created_by_ref, OpenCTI extensions (x_opencti_score, x_opencti_main_observable_type)
- Relationship objects (related-to) for co-occurring IOCs
- `GET /api/v1/conversations/{conv_id}/export/stix` endpoint
- "STIX 2.1" download button on Conversation Detail page
- Refactored existing campaign STIX export to use shared builder
- Header IOCs excluded from STIX export

#### Demo Dataset v4
- 1025 IOCs across 9 types (added Telegram usernames, ETH wallets, SHA256)
- Indicator table populated with mock enrichment data (VT/URLScan scores)
- Outbound message uniqueness: 98.6% (was 82%)
- `injectVariation()` post-processor with persona-group-specific greetings, interjections, time references

### Fixed

#### IOC Extraction
- **Telegram username**: regex bug `\B@` (not-word-boundary) replaced with `(?<!\w)@` (negative lookbehind); validator now requires letter start per Telegram spec
- **CVE extraction**: added to LLM prompt (rule 8: Security Identifiers) with examples
- **Category always "Unknown"**: `upsertEnrichedIoc` bypassed categorizer due to hardcoded `'Unknown'` placeholder; now checks for placeholder before calling categorizer
- **Category display**: replaced IocCategorizer mini-taxonomy (3 values) with conversation scam type (13 values) for user display; MISP mapping kept separate
- **Extraction method**: fallback to `source` field when `extraction_method` missing from context

#### STIX Export
- Fixed `TLP:TLP_AMBER` double prefix (DB stores `TLP_AMBER` with underscore)
- Fixed campaign export 500 error after STIXExporter refactor (missing Uuid import)
- Updated all STIX-related tests for new bundle structure

---

## [1.8.0] - 2026-03-30

### Added

#### Quality Benchmark Suite (Feature 016)
- 3 evaluation commands: `app:evaluate:generate-corpus`, `app:evaluate:reply-quality`, `app:evaluate:bandit-analysis`
- 9 quality metrics across 6 dimensions (diversity, naturalness, language compliance, IOC elicitation, safety)
- Makefile targets: `evaluate-corpus`, `evaluate-quality`, `evaluate-bandit`, `evaluate-all`

#### Pipeline Monitoring Dashboard (Feature 020)
- PipelineTrace and ComponentTrace value objects for per-reply tracing
- 3 API endpoints: `/monitoring/pipeline-traces`, `/monitoring/pipeline-traces/{msgId}`, `/monitoring/pipeline-health`
- React page at `/monitoring/pipeline` with live feed, component waterfall, health table

#### Injection Monitoring (Feature 021)
- `app:detect-prompt-injection` added to scheduler (every 6h)
- API endpoint: `/monitoring/injection` with risk stats and recent alerts
- React page at `/monitoring/injection` with coverage bar and alert list

#### Semantic Embeddings (Feature 022)
- `EmbeddingService` using OpenAI text-embedding-3-small (1536 dimensions)
- `app:generate-embeddings` command added to scheduler (every 6h)

#### Actor Profiles (Feature 022)
- `ActorProfileGenerator` computes style_dna and infra_dna from campaign messages
- `app:generate-actor-profiles` command added to scheduler (daily)

### Fixed

#### Reply Pipeline Hardening (Feature 017)
- PolicyGuardConfig::fromContext() now wired into ReplyOrchestrator (was dead code)
- Forbidden patterns narrowed from 16 to 6 (removed "test", "suspect", etc.)
- Validator prompt simplified — PolicyGuard owns syntax, Validator owns semantics
- Best-of-3 fallback strategy replaces canned response when validator rejects
- First-attempt approval: 29% → 100%, Fallback rate: 30% → 0%

#### Feedback Loop (Feature 018)
- engagement_duration_sec computed from actual message timestamps (was always 0)
- turns_count computed from message count (was always 0)
- CalculateRewardsCommand fixed (was broken — idempotence check bypassed)
- ConversationEndedListener: removed redundant reward double-write
- Scheduler: removed `profiles: [production]` gate, added `SCHEDULER_ENABLED` env var

#### Pipeline Observability (Feature 019)
- Dedicated production LLM log handler bypassing fingers_crossed
- CostEstimator wired into ReplyOrchestrator (was using hardcoded 16x underestimate)
- REPLY_GENERATED audit event added to ReplyHandler
- Debug logging added to LanguageDetector, ContextAnalyzer, ReciprocityManager

#### Dead Wiring Fixes (Feature 021)
- IOC multi-observation boost: `boostConfidence()` now called after indicator upsert
- Complete audit trail: 8 additional event types wired (MESSAGE_INGESTED, IOC_EXTRACTED, CONVERSATION_CLOSED, PERSONA_SELECTED, REPLY_SENT, INJECTION_DETECTED, EXPORT_STIX, EXPORT_MISP)
- Dead methods removed from PersonaPerformanceStatsRepository

#### Language Compliance
- `neutralizeLocale()` strips French cultural markers for non-French replies
- Language override instruction in OBJECTIVE section
- Persona labels migrated from French to English

---

## [1.7.0] - 2026-03-25

### Added

#### CI Pipeline Restoration (CT-1)
- Backend unit + integration tests now run in CI via Docker containers
- PHPUnit CI config with bootstrap_ci.php (include_once wrapper for Kernel)
- Comprehensive test suite passing in GitHub Actions

#### Security Headers (CT-2)
- Content-Security-Policy and Strict-Transport-Security headers on all API responses
- Relaxed CSP for Swagger UI page only (unsafe-inline/unsafe-eval)

#### Dependency Audit (CT-3)
- composer audit now blocking in CI (fails on new CVEs)
- 2 known CVEs ignored with documentation (Symfony 7.2, PHPUnit)

#### MISP/ATT&CK Taxonomy (CT-4)
- All 13 scam types mapped to MISP RSIT taxonomy and ATT&CK techniques

#### Community (CT-5, CT-6, CT-7)
- CODE_OF_CONDUCT.md (Contributor Covenant v2.1)
- GitHub Release v1.0.0 with release notes
- GitHub Discussions enabled (6 categories)

#### DPIA (CT-8)
- GDPR Article 35 Data Protection Impact Assessment v1.1

#### PII Masking (CT-9)
- PiiMaskingProcessor for Monolog (masks emails, IPs in logs)

#### PostgreSQL Backup (CT-10)
- Automated daily backup via scheduler service (pg_dump + verification)
- Restore documentation

#### OpenAPI 3.0 (MT-3)
- 100% API endpoint coverage with #[OA\*] annotations (43+ endpoints)
- Swagger UI at /api/doc with interactive documentation
- 7 endpoint tags: Auth, Communication, Campaign, Scambaiting, Monitoring, User, Meta

#### PHPStan Full Coverage (MT-6)
- Removed excludePaths for Infrastructure/ and UI/ layers
- 100% of src/ analyzed at level 6 bleeding edge, 0 errors

#### IOC Confidence Scoring & Decay (MT-10)
- Confidence score per IOC (0.0-1.0) based on extraction method
- Temporal decay with configurable half-life per IOC type
- Effective score = confidence x decay factor
- Frontend IOC Explorer updated with confidence column

#### SIEM Connector (MT-7)
- Pluggable SiemExporterInterface (hexagonal port/adapter)
- 3 adapters: NullSiemExporter, FileSiemExporter, SyslogSiemExporter
- 3 formatters: CEF (Common Event Format), ECS (Elastic Common Schema), JSON
- 16 audit event types with severity mapping
- CLI: app:siem:test + app:siem:export
- Complete integration guide: docs/15_siem_integration.md

#### Data Consistency Fixes
- Dashboard/Conversations active count aligned with Monitoring (31 vs 20 bug)
- Settings exploration rate, best persona, unique IOC types fixed
- Conversation list message count column populated
- All scam types shown in Monitoring (including 0-count)
- Convergence history section on Personas page
- Rate limits section on Monitoring page

#### Campaign Radar Frontend
- Campaign Detail page with metadata, messages, profile, actions
- Clickable campaign IDs in list
- Generate Profile (LLM), Promote Rule, Export STIX buttons
- Run Hunt button for admin users

### Changed
- Conversation lifecycle policies for all 13 scam types
- Per-sender rate limiting + flood detection
- Human delay simulation in n8n (log-normal distribution)

### Fixed
- CI Kernel double-include issue resolved (Docker-based test execution)
- Mock test MetaConfig missing llm_provider/llm_model fields
- Campaign route conflict (/campaign/{id} vs /campaign/candidates)

---

## [1.5.0] - March 2026

### Added — Security by Design

Based on the [security-by-design](https://github.com/laugiov/security-by-design) reference framework:

- **OWASP Security Headers**: 6 headers on all responses (X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy, COOP, X-Permitted-Cross-Domain-Policies)
- **Structured Audit Trail**: `audit_log` table with 16 event types, `AuditLogger` service, `GET /api/v1/monitoring/audit` endpoint (paginated, filterable)
- **Request Trace ID**: `X-Trace-Id` header on every request/response, Monolog processor injects trace_id in all logs, audit events auto-capture trace_id
- **JWT RS256**: Migrated from HS256 (symmetric) to RS256 (asymmetric), TTL reduced from 1h to 15min
- **Key Management**: `generate-jwt-keys.sh` + `rotate-jwt-keys.sh` with zero-downtime rotation, `docs/14_key_management.md`
- **RBAC Permissions**: 12 fine-grained permissions via `PermissionVoter`, `Permission` enum, permissions JSON on User entity
- **Payload Size Limit**: Reject requests > 1MB (413 Payload Too Large)
- **CI Security**: `composer audit` (PHP SCA) + Gitleaks (secret detection) in GitHub Actions

### Fixed — PII Removal
- Removed all `error_log()` calls from production code (7 occurrences)
- Truncated debug logs: no more full LLM prompts or generated text in logs
- LLM providers log metadata only (lengths, token counts), never content

---

## [1.4.0] - March 2026

### Added

#### Multi-LLM Provider Support
- **AnthropicClient**: Claude Haiku, Sonnet, Opus via Messages API (system message as separate parameter)
- **OllamaClient**: Local inference with llama3, mistral, phi3 (zero cost, full privacy)
- **MockLLMClient**: Static responses for demo mode (no API key required)
- **LLMProviderCompilerPass**: Automatic provider selection via `LLM_PROVIDER` env var
- 14 unit tests for Ollama + Anthropic clients (HTTP mock, payload, headers, errors)

#### LLM Cost Tracking
- **LlmCallCompletedEvent**: Dispatched by each provider with real token counts from API responses
- **LlmUsageRecord**: Doctrine entity + `llm_usage` table for cost persistence
- **CostEstimator**: Per-model pricing (OpenAI, Anthropic; Ollama/Mock = free)
- **LlmUsageListener**: Event-driven, non-blocking cost recording
- **GET /api/v1/monitoring/llm-cost**: Monthly totals, per-purpose breakdown, daily trend
- 17 unit tests for CostEstimator + LlmUsageRecord

#### Demo Mode
- `LLM_PROVIDER=mock` bypasses all external API calls
- `scambuster:demo:load` command loads 123 synthetic conversations (1,034 messages, 382 IOCs)
- `make demo-load` Makefile target

#### Monitoring & Observability
- **GET /api/health**: Database + Redis connectivity checks with latency measurement
- **GET /api/metrics**: Prometheus text format (conversations, messages, IOCs, kill switch, health)
- `make validate` script checks all services, auth, and environment

#### MISP Integration
- `docs/13_misp_integration.md`: Export methods, attribute mapping, troubleshooting
- `scambuster:misp:test` console command for connectivity testing
- `make misp-test` Makefile target

#### Documentation
- `docs/11_database_schema.md`: Complete schema extracted from live database
- `docs/12_api_quick_reference.md`: All endpoints with curl examples
- `docs/13_misp_integration.md`: MISP integration guide

#### Open Source Readiness
- GitHub issue templates (bug report, feature request, question) with YAML forms
- Pull request template with DDD architecture checklist
- GitHub Actions CI: frontend job (TypeScript, ESLint, Vitest, Vite build)
- `scripts/check-env.sh`: Environment variable validation
- `scripts/validate-install.sh`: Full installation health check
- `docker-compose.override.yml.example` for local customizations

#### Infrastructure
- Redis healthcheck in Docker Compose
- `depends_on: condition: service_healthy` for reliable startup order
- Vite proxy corrected to target backend-dev
- `role_hierarchy: ROLE_ADMIN -> ROLE_USER` in security config

### Fixed
- 4 pre-existing test failures (auth headers, detached entity cleanup, unique constraint)
- ESLint error: `statusToBadgeVariant` extracted to separate file for React Fast Refresh
- Frontend Dockerfile: removed silent `npm ci` failure

### Removed
- `scripts/manage-workflows.sh` (n8n credentials must be configured manually in UI)

---

## [1.3.0] - March 2026

### Added
- English synthetic dataset generation (100+ conversations)
- n8n workflow anonymization for public preview
- Documentation update for preview repository

---

## [1.2.0] - January 2026

### Added
- A/B testing validation framework (2,221 synthetic conversations)
- Statistical analysis: p < 0.001, Cohen's d = 0.37
- Test suite expanded to 1,039 automated tests

---

## [1.1.0] - December 2025

### Added
- Prompt injection detection via InjectionDetector agent (two-layer forensic)
- Scaled platform to 1,000+ active conversations

---

## [1.0.0] - 21 November 2025

### Added - Adaptive Scambaiting Module

#### Database
- **Migration 1**: ALTER TABLE `conversation` - 3 new columns:
  - `engagement_duration_sec` (INTEGER): Conversation duration in seconds
  - `turns_count` (INTEGER): Number of conversation turns
  - `reward_value` (NUMERIC(5,4)): Normalized reward [0.0, 1.0]
  - Indexes: `idx_conversation_reward`, `idx_conversation_duration`

- **Migration 2**: CREATE TABLE `persona_performance_stats`:
  - Composite key: `(persona_id, scam_type_id)` for contextual bandit
  - Columns: `sessions_count`, `reward_sum`, `reward_avg`, `last_updated`
  - CHECK constraints: `sessions_count >= 0`, `reward_avg BETWEEN 0.0 AND 1.0`
  - Indexes: `scam_type_id`, `reward_avg DESC`, `last_updated DESC`
  - Foreign keys CASCADE to `persona` and `lkp_scam_type`

#### Domain Layer
- **Value Object** `ConversationMetrics`: Conversation metrics (duration, IOCs, completion)
- **Value Object** `PersonaPerformance`: Persona performance stats (reward_avg, sessions_count)
- **Domain Event** `ConversationEndedEvent`: Dispatched when a conversation ends

#### Application Layer
- **Service** `PersonaOptimizer`: Epsilon-greedy algorithm (80% exploitation, 20% exploration)
  - `selectPersona(string $scamTypeCode): ?string` -- Optimal selection
  - `getSelectionStats(string $scamTypeCode): array` -- Selection stats
  - Cold start handling: < 3 sessions = pure exploration
  - Tie-breaking by sessions_count

- **Service** `ConversationMetricsCollector`: Metrics collection
  - Reuses existing `IocHandler` and `MessageHandler`
  - Returns a `ConversationMetrics` Value Object

- **Service** `ConversationClosureService`: Conversation closure
  - `closeConversation(string $convId): void` -- Single closure
  - `closeConversationsBatch(array $convIds): int` -- Batch closure (CRON)
  - Dispatches `ConversationEndedEvent` after reward computation

#### Infrastructure Layer
- **Entity** `PersonaPerformanceStatsEntity`: Doctrine entity for persistence
- **Repository** `PersonaPerformanceStatsRepository`: Doctrine repository
  - Methods: `findOrCreate`, `findBestPerformingPersona`, `findTopPerformingPersonas`, `findAllByScamType`, `countColdStartPersonas`
- **Event Listener** `ConversationEndedListener`: Updates `persona_performance_stats` with new reward (moving average)

#### UI Layer (REST API)
- **POST** `/api/v1/scambaiting/conversation/{convId}/close` -- Close a conversation
- **GET** `/api/v1/scambaiting/stats/{scamTypeCode}` -- Epsilon-greedy stats for a scam type
- **GET** `/api/v1/scambaiting/stats` -- Aggregated stats across all scam types
- **GET** `/api/v1/scambaiting/persona/{personaCode}/performance` -- Persona performance
- **POST** `/api/v1/scambaiting/select-persona` -- Test endpoint for selection

All endpoints require JWT authentication.

#### n8n Workflow
- **Workflow** `WF-SCAMBAITING-END-CONVERSATION`:
  - Daily CRON at 03:00 UTC
  - Queries PostgreSQL: conversations with `status='open'` and `created_at < NOW() - 48h`
  - Loops over conversations (limit 500)
  - Calls API `POST /close` for each conversation
  - Aggregates results (success, failed)

#### Tests
- **10 unit tests (Domain)**: ConversationMetrics, PersonaPerformance, ConversationEndedEvent
- **5 unit tests (Application)**: PersonaOptimizer selection, cold start, tie-breaking
- **7 integration tests**: Repository, Event Listener, Controllers
- **9 regression tests**: ReplyHandler (no breakage of existing behavior)
- **3 E2E tests**: Full end-to-end workflow
- **6 fixture scenarios**: Exploitation, exploration, cold start, edge cases

**Total**: 40+ tests, 100+ assertions, ~95% coverage

### Changed

#### Application Layer
- **`ReplyHandler.php`**: Replaced `assignRandomPersona()` with `PersonaOptimizer->selectPersona()`
  - Before: Uniform random selection
  - After: Epsilon-greedy selection (80% exploitation, 20% exploration)

#### Symfony Services
- **services.yaml**: Autowiring configuration for `PersonaOptimizer`

### Fixed
- **ConversationEndedEventTest**: Type mismatch `conversationId` (int to string)
- **Controller permissions**: `chmod 644` on all UI Layer controllers
- **Symfony cache**: Cache invalidation after controller creation

---

## [0.9.0] - 15 November 2025

### Added
- Conversation history summary for LLM context enrichment
- Endpoint: `POST /api/v1/communication/conversation/{convId}/history-summary`
- Service: `ConversationHistoryService` with LLM-generated summary

### Changed
- `ReplyHandler`: Integrated history summary into LLM context

---

## [0.8.0] - 10 November 2025

### Added
- Post-IBAN strategy for capturing additional IOCs
- Optimized reply generation after IBAN capture

### Changed
- `ReplyOrchestrator`: Added high-value IOC capture logic

---

## [0.7.0] - 5 November 2025

### Added
- Multi-conversation support with the same sender email
- Multiple active conversations per sender handling

### Fixed
- Duplicate attachment upload error

---

## [Unreleased] - Before November 2025

Earlier versions not documented in this changelog.
History available via `git log`.

---

## Change Types

- **Added**: New features
- **Changed**: Changes to existing features
- **Deprecated**: Features to be removed soon
- **Removed**: Removed features
- **Fixed**: Bug fixes
- **Security**: Security fixes

---

**Format**: [Keep a Changelog](https://keepachangelog.com/)
**Versioning**: [Semantic Versioning](https://semver.org/)

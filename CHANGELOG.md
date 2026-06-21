# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

---

## [2.27.0] - 2026-06-21

### Fixed — Spec 112 (No out-of-band channel in outbound replies)

User audit on conv `ab075b53-...` caught a reply where the bait
shared a WhatsApp number `"0612345678"` — sequentially-numbered
fake (FR mobile prefix `06` + `12345678`) that any half-attentive
scammer would spot as automation.

Root cause: PolicyGuard had a deliberate carve-out for phone numbers
(`"Phone numbers are ALLOWED (we provide fake ones to attackers)"`)
and the runtime persona rules in `BasePromptRules::getRules()`
contained no prohibition on out-of-band channels at all. Combined
effect: the bait was free to invent fake phones, and the validator
approved them on the way out.

### Why this is wrong even with FAKE values

1. **Tell-tale of automation** — a human doesn't share `0612345678`.
   The LLM has no rule about plausible digit distributions and emits
   trivially-fake sequences. A scammer spotting this drops the conv.
2. **Breaks the bait container** — replies asking the scammer to
   WhatsApp/Telegram pull the conversation outside our IMAP honeypot;
   we lose attribution, observability, and the recording.
3. **Hallucination risk** — an LLM can generate a number / handle
   that happens to belong to a real third party. Sending a scammer
   toward an innocent person's contact is harm we should not risk.
4. **Zero defensive value** — providing a contact channel does not
   advance bait goals. The scammer asking for our number is HIS
   social engineering; refusing is exactly the right move.

### What ships

**Backend `PolicyGuard.php`** — new `OUT_OF_BAND_CHANNEL_PATTERNS`
constant + sibling validation loop. Patterns:
- Phone-shaped sequences (E.164 or freeform with separators)
- Telegram-style `@username` handles
- Skype `live:` / `skype:` URIs
- Signal.me / Discord invite links
- Crypto wallets: BTC (bech32 `bc1…` + base58 `1…`/`3…`), ETH
  (`0x…40hex`), XMR (`4…`/`8…` 95 chars)

New audit flag string `out_of_band_channel:<kind>` (distinct from
`pii_detected` so audit consumers tell at a glance which category
triggered the rejection). IBAN + postal address keep their existing
`pii_detected` flag for backwards compatibility.

Pattern order: crypto/handles FIRST, phone catch-all LAST — otherwise
the broad phone regex shadows ETH addresses (`0x…0123456789…`).

**Runtime prompt rule (`BasePromptRules::getRules()`)** — new
sentence appended to every persona's system prompt at runtime
(via `PromptBuilder`), so the fix applies to all 27 seeded personas
without a Doctrine migration:

> *Keep everything on this email thread. Never give a phone,
> WhatsApp, Telegram, Skype, Signal, Discord, crypto wallet, IBAN
> or postal address — even fictional ones reveal automation. If
> asked, politely decline and ask to stay on email.*

The rule rides alongside the existing payment-cue / no-honeypot /
greeting-format rules in the same shared block. Word-count cap
in `BasePromptRulesTest` raised from 120 to 170 to fit it.

Persona DB seeds (`PersonaFixtures.php`) are NOT modified — the
runtime injection means no DB write is required on existing
deployments, and the rule is guaranteed identical across personas.

### Tests

`PolicyGuardTest`:
- Reversed the existing `it_allows_fake_phone_numbers` test —
  becomes `it_rejects_phone_numbers_as_out_of_band_channel`.
- New parametrised `it_rejects_each_out_of_band_channel_kind` with
  8 data sets covering each pattern (phone variants, Telegram,
  Skype, Discord, BTC, ETH).
- New `it_approves_reply_without_any_out_of_band_channel` negative
  test confirms clean replies pass.
- Total: 49 PolicyGuard tests, 106 assertions, all green.

`BasePromptRulesTest`:
- New `testRulesBanOutOfBandChannels_Spec112` asserts the runtime
  rule mentions phone / WhatsApp / crypto wallet / IBAN / fictional.
- `testRulesAreUnder120Words` renamed to `testRulesAreUnder170Words`
  to reflect the new cap.
- 8 tests, 22 assertions, all green.

### Honest limits

- Aggressive phone regex produces false positives on long order or
  invoice numbers ("Invoice #1234567"). Accepted trade-off: a false
  rejection costs ONE orchestrator retry (already capped at 3); a
  leaked channel costs permanent damage to the bait.
- Crypto wallet patterns cover BTC/ETH/XMR (95%+ of scam traffic on
  the platform today). Solana, Cardano, etc. not yet blocked.
- Runtime rule is in English (matches the rest of
  `BasePromptRules`). Personas seeded by `PersonaFixtures.php` are
  unmodified — the rule is appended at prompt-build time, so all
  27 personas inherit it without DB migration.

---

## [2.26.0] - 2026-06-20

### Fixed — Spec 111 (Unified conversation counts + explicit labels)

User audit on conv `ab075b53-6715-4118-8c0b-1b21eacf67e4` surfaced
three different counts for the same conversation depending on the view:

| View    | Showed       | Actual DB state for this conv |
| ------- | ------------ | ----------------------------- |
| List    | 50 msgs, 171 IOCs | 50 messages, 57 distinct indicators, 192 observations |
| Detail  | 20 msgs, 54 IOCs  | (truncated by limit=20, different exclusion list) |
| Theater | 25 indicators extracted | (correct under the "actionable" definition) |

Two distinct root causes:

#### A — Messages count truncated to first 20

Frontend hook `useConversationMessages` didn't pass `?limit=` to the
backend, which defaults to `limit=20` (page 1). The Detail page then
displayed `messages.data.length` = 20 instead of the real count.
Affected 177 of 640 conversations on dev.

**Fix**: hook passes `?limit=1000` (pragmatic cap, real pagination
deferred until any conv grows beyond).

#### B — Three different "IOCs" semantics, one label

List counted observations (`obs_id`), Detail counted distinct
indicators with a narrow exclusion (dmarc/spf/dkim only), Theater
counted distinct indicators with a wider "actionable" exclusion.
All mathematically consistent with their own definition, but
indistinguishable from the UI.

**Fix**: new
`App\Domain\Communication\Policy\IocActionablePolicy` as the single
source of truth for "non-actionable" types (header metadata,
auth results, WHOIS, file metadata, ref identifiers, file hashes).
All three count surfaces refactored to use it:
- `ConversationHandler::getIocCountsForConversations` — now
  `COUNT(DISTINCT indicator_id)` excluding the policy list (was
  `COUNT(DISTINCT obs_id)` with narrow exclusion).
- `IocQueryService::getConversationIocs` — new
  `bool $actionableOnly = false` parameter. Detail controller passes
  `true`. Other consumers (Theater, MISP export, reply pipeline,
  metrics) keep `false` (full set).
- `TheaterAssemblyService::countActionableIocs` — refactored to call
  the policy (no behaviour change since the policy mirrors its
  existing hardcoded list).

Frontend mirrors the list at
`frontend-react/src/lib/iocActionable.ts` with a parity test (sibling
to the PHP `IocActionablePolicyTest`) — any drift fails both suites.

#### Explicit labels (user-requested)

- Conversations list column: "IOCs" → **"Actionable IOCs"** / **"IOCs actionnables"** with `ⓘ` tooltip explaining the exclusion list.
- Conversation detail field: "IOCs Found" → **"Actionable IOCs"** with the same tooltip.
- Detail messages format: `"50 (10 exch.)"` → `"50 messages (25 turns)"`
  — clearer label and `Math.ceil(N/2)` instead of `Math.floor(N/2)`
  so a 51-message conv reads 26 turns instead of 25.
- Theater "indicators extracted" stays unchanged (already accurate).

After fix on `ab075b53`: all three views show **25 actionable IOCs**.
Detail shows **50 messages (25 turns)**.

### Honest limits

- "Actionable" is a value judgement biased toward the scam-baiting
  threat model. File hashes (md5/sha1/sha256) ARE actionable for
  malware-delivery IOCs; we exclude them because the platform doesn't
  do payload analysis today and including them inflates counts with
  hashes of legitimate attachments scammers copy-pasted from real
  corporate emails. Revisit if attachment analysis is added.
- The list-view `ioc_count` API field keeps its name but its
  **semantic shifts** from "observations" to "actionable distinct
  indicators". Only frontend consumer; no external breakage.
- Conversations beyond 1000 messages will be truncated silently in
  the detail view. None exist today; if they appear, follow-up spec.

---

## [2.25.0] - 2026-06-20

### Fixed — Spec 110 (`/risk` endpoint waits 30s for parallel IOC extraction)

User audit on 2026-06-19 root-caused 14 stuck open conversations where
ScamBuster never replied to the scammer's follow-up. The cause is a
race in the n8n `WF-INTAKE-EMAIL-V2` workflow:

After `Ingest Email`, two branches run in parallel:
- `WF-EXTRACT-AND-ENRICH-IOC` (LLM body-IOC extraction, ~5–10s)
- `Get Risk Assessment` → `Decision Gate` → reply trigger / skip

The Decision Gate route calls `GET /api/v1/communication/message/{msgId}/risk`
within the first ~5s of the parallel race, before body IOCs land in
`observed_ioc`. At that moment the risk endpoint sees ONLY header IOCs
(3 types: `message_id`, `email`, `subject`). The intrinsic score
formula for an `UNKNOWN` scam_type with 3 IOC types yields:

  30 (UNKNOWN baseline) + 0 (no financial/phone/url) + 9 (3×3 diversity, no >=4 bonus) = 39

39 falls **one point** below the medium threshold of 40 → `level=low` →
`should_reply=false` → n8n routes to `Skip Reply` → conversation stuck.

9 seconds later, body extraction adds `url`/`domain` (4 types), the
intrinsic score would be 52 → `medium` → `should_reply=true`. But by
then the Decision Gate has already routed past the choice point.

### Fix (per user direction: do not touch n8n workflows)

`GetMessageRiskController` now sleeps `app.risk_extraction_wait_sec`
seconds at the start of `__invoke()` before delegating to the handler.
This lets the parallel extraction branch finish populating body IOCs
before the score is computed.

- Production / dev: **30s** (cap; covers the observed ~10s worst case
  with 3x safety margin).
- Test / e2e environments: **0s** (otherwise every PHPUnit test
  hitting the route would block 30s).

The override lives in `config/services_test.yaml` and
`config/services_e2e.yaml` (NOT `config/packages/{env}/services.yaml`,
because `services_{env}.yaml` loads AFTER `services.yaml` and actually
wins the override — the `packages/{env}` path loads before and is
silently shadowed).

### Honest limits

- Pure `sleep()` blocks an FPM worker for ~30s per inbound. At current
  traffic (~5–20 emails/min) this is fine. Under sustained burst
  > ~10/s, workers would saturate; in that case either expand the FPM
  pool or move to a polling-based wait (option B from the audit
  conversation).
- 30s is a coarse cap. If extraction takes longer than 30s (e.g. LLM
  provider hiccup), the race re-emerges. Operator can raise the
  parameter via service config.
- The only HTTP caller of `/risk` is n8n's `WF-INTAKE-EMAIL-V2`
  (verified via grep on `frontend-react/src` — frontend never reads
  this endpoint). No UI latency impact.

### Defensive test coverage

`MessageRiskControllerTest` gained two assertions:
- The bound `app.risk_extraction_wait_sec` parameter MUST be 0 in the
  test/e2e container.
- An actual request to `/risk` in the test env MUST complete in under
  2 seconds (defensive timing check, catches the case where the
  parameter is correctly 0 but the controller doesn't read it).

---

## [2.24.0] - 2026-06-19

### Added — Spec 109 (`postal_address` IOC type, first-class)

User audit on the conversation detail view spotted a clear scammer
postal address ("Plot No 1 & 2, Mamram Towers, ... Delhi 110096") that
was never extracted as an IOC. Investigation confirmed: 33 IOC types
covered (email, infra, hashes, finance, contact channels, files,
security identifiers, logistics) — none for postal/street addresses.

Postal addresses are a meaningful threat-actor pivot:
- Scammers re-use the same fake "registered office" addresses across
  campaigns; same operator → same pivot
- MISP `address` and STIX 2.1 `location` are native concepts —
  downstream consumers (OpenCTI, MISP, SOC) ingest them natively

### What ships (full stack)

- **Extraction**: `postal_address` added to `IocExtractor::ALL_IOC_TYPES`.
  LLM system prompt extended with a new section instructing extraction
  of full postal addresses as single-string blobs (no decomposition into
  street/city/zip). Examples included in the prompt for shape.
- **Normalization** (`IocNormalizer`): lowercase + collapse internal
  whitespace + strip trailing `.,;`. Two messy variants of the same
  address dedup to one canonical via the `(type, value_norm)` unique
  constraint on `indicator`.
- **Validation** (`IocValidator`): length-bounded 10..500, multiline
  allowed (`s` modifier). No structural regex — addresses too varied
  internationally.
- **MISP export** (`IocExportMapper`): `category='Person', type='other',
  to_ids=false`. Soft attribute / pivot, not a blocklist entry.
- **STIX 2.1 export**: custom SCO `x-scambuster-postal-address`,
  consistent with existing `x-scambuster-phone`/`-iban`/etc. Pattern:
  `[x-scambuster-postal-address:value = '...']`.
- **Frontend label**: `'Postal Address'` in `iocTypeLabels.ts`.

### Clustering anchor — opt-in only, default OFF

`IocConfidenceCalculator` left **unchanged**: postal_address falls
through to the LOW catch-all, which means
`IocClusteringService::resolveAnchorTypes()` does NOT pick it up.
Default behaviour: postal addresses are NOT used as clustering anchors.

To promote to anchor (opt-in), add ONE line to
`IocConfidenceCalculator::HIGH_VALUE_TYPES`:
```php
'postal_address' => 'HIGH',
```
Recommended only after observing dedup quality in production for a
few weeks.

### Honest limits (in the spec, the tooltip, and this entry)

- Postal address ≠ verified location. It is the textual address
  observed in the conversation.
- Risk of innocent-party defamation if a scammer uses a real
  third-party address. Treat as PIVOT, not blocklist.
- No automatic dedup beyond exact normalized-string match. Fuzzy
  matching deferred to a future spec.
- No automated existence check (no OpenStreetMap / Google Maps API
  call). Deferred.

### Acceptance verification

- Backend tests: 92 unit tests across 4 touched files passed —
  IocExtractor (whitelist + round-trip), IocNormalizer (canonicalization
  + multiline), IocValidator (length bounds + isSupportedType), and
  IocExportMapper (MISP + STIX provider).
- No DB migration needed: `indicator.type` is a plain text column with
  no FK on the lkp table.

---

## [2.23.0] - 2026-06-16

### Changed — Spec 108 (Impact tile reframe: Engagement Time → Scammer Replies Elicited)

Final iteration on the leftmost Impact tile. After spec 107 phase 1
relabeled "Criminal Time Wasted" → "Engagement Time" with a
qualified-conv filter, user audit pushed further: any time-from-
timestamps metric is structurally inflated by overnight + weekend
gaps. Four methodologies were debated:

| Methodology              | Headline on dev | Issue |
| ------------------------ | --------------: | ----- |
| Wall-clock (spec 107)    | 6278h           | Overclaim, includes idle time |
| Session-based (30min)    | 21h             | Too small for any demo punch |
| Cap each gap at 6h       | ~2200h          | 6h is arbitrary, indefensible |
| Effort estimate (5min/reply) | 70h         | Defensible but rests on per-message-time assumption |

Conclusion: **the time concept itself doesn't map cleanly to
measurable data**. Switching to a direct count of inbound messages
("scammer replies") removes the inference layer and the overclaim
risk entirely.

### What replaces it

```
SCAMMER REPLIES ELICITED
836                    ▲ 12% vs prev. period
across 473 conversations
```

- Direct count of inbound messages (`direction = 'in'`) in qualified
  conversations (`turns_count >= 2` filter from spec 107 phase 1
  preserved).
- 100% measured, 0% inferred — no methodology debate available.
- Respects page-level period + scam_type filters.
- Trend chip ▲/▼ shown on 7d/30d/90d; hidden on `All` (no comparison
  applies on cumulative).
- New API fields on `wasted_time` payload:
  `scammer_replies_count`, `scammer_replies_prev_count` (null on All),
  `scammer_replies_delta_pct` (null on All or cold start).

### What's untouched (out of scope)

- The "Hours Wasted per Week" chart at the bottom of Impact still shows
  wall-clock hours — same overclaim issue, deferred to a future spec
  (likely will be reframed as "Scammer Replies per Week" for
  consistency).
- Legacy `wasted_time.total_hours`, `avg_hours`, `max_hours`,
  `longest_scam_type`, `weekly_trend` fields untouched (chart consumer +
  backwards compat). `trends.wasted_hours_delta_pct` still computed but
  unused — both deferred.

### Process note

Frontend test gates (`npm test`, `npm run build`) run as part of the
pre-commit checks in addition to the backend 8-gate preflight, per the
lesson learned from spec 106 CI red. The local `preflight-check.sh`
script only covers backend.

---

## [2.22.0] - 2026-06-16

### Changed — Spec 107 (Impact tile honesty fix: Criminal Time Wasted → Engagement Time, phase 1)

Follow-up to spec 106's honesty audit. The "Criminal Time Wasted" tile
on the Impact dashboard had two related problems:

1. The label "Criminal Time Wasted" implies measured scammer attention,
   but the SQL is just
   `SUM(engagement_duration_sec)` with `engagement_duration_sec` = wall-clock
   from `MAX(ts_msg) - MIN(ts_msg)` per conversation. Wall-clock duration
   ≠ active scammer effort.
2. The denominator counted ALL closed/open/abandoned conversations,
   including 57 1-turn conversations on dev where the scammer's first
   email arrived but no reply was ever exchanged. They contribute 0h to
   the sum, but pollute the "across N conversations" subtitle and skew
   `avg_hours`.

Distribution on dev DB (528 closed convs, 6254h headline) showed 96.6%
of the time came from 109 multi-turn conversations (turns_count >= 4);
the 57 1-turn convs contributed 0% but inflated the conv count.

### What changed (phase 1, this commit)

- **Backend filter**: `getWastedTime()` + weekly trend + `computeTrends`
  current/prev hours queries all gain `AND turns_count >= 2`. A
  qualified conversation is one where the scammer actually replied at
  least once. The 1-turn rows already summed to 0h, so the headline
  number barely shifts (e.g. dev: total_hours unchanged at 6278.59).
- **Conversation denominator** correctly drops to qualified count
  (dev: 576 → 473). `avg_hours` also rises because we no longer divide
  by 0-hour rows.
- **Label rename**: `Criminal Time Wasted` → `Engagement Time` (EN) /
  `Temps d'engagement` (FR). Factual framing of what is actually
  measured.
- **Tooltip rewritten**: explicit about wall-clock methodology,
  qualified-conv filter, and that this is NOT a measure of active
  scammer typing effort.
- API field names unchanged (`wasted_time.total_hours` etc.) — no
  breaking change for other consumers.

### Out of scope (phase 2, deferred)

- **Active wait time** measurement (sum of
  `scammer_reply.ts − our_prev_reply.ts` per conversation) — the true
  "time the scammer spent waiting on us" signal. Heavier SQL via
  `LAG`/window functions, bigger semantic shift, scoped separately.
- **Reply-gaps subtitle** — exposing avg active time per turn.

---

## [2.21.0] - 2026-06-16

### Changed — Spec 106 (Impact tile honesty fix: Novel IOCs → Fresh IOCs)

User audit caught the Impact dashboard's "Novel IOCs" tile overclaiming
hard: `95.3% novel` where the SQL computes "novel" as "VirusTotal didn't
return ≥3 detections OR was never queried". Decomposition on dev DB
(1846 actionable IOCs):

- **3** truly unenriched
- **1503** "novel" simply because VirusTotal isn't applicable to the IOC
  type (email, phone, telegram, iban, crypto wallets — the pipeline only
  ever queries VT on url/domain/ipv4/sha256)
- **253** the only honest fraction — VT queried, < 3 detections
- **87** flagged by VT ≥ 3

85% of the headline number was "VT doesn't apply", not novelty. The
label would not survive scrutiny from a CTI expert in the audience.

### What replaces it

A **dual-face tile** picked by the page-level period selector:

- **Fresh IOCs (last Nd)** when period = `7d`/`30d`/`90d`: count of
  indicators whose `first_seen` is within a rolling window of that
  length, with `▲/▼ {delta}%` vs the previous window.
- **Total IOCs** when period = `all`: cumulative count of all
  actionable IOCs ever observed, no trend. Matches the cumulative-state
  pattern of the other tiles on the page.

Backend signals which face to render by populating or nulling four
new fields on `ioc_value`:
`fresh_iocs_count`, `fresh_iocs_prev_count`, `fresh_iocs_delta_pct`,
`fresh_iocs_window_days`. All null on `period=all` → frontend renders
the Total face using the existing `total_iocs` field.

Windowed face example:
```
FRESH IOCS (LAST 30D)
1,027   ▲ 97.9%
vs previous 30 days
```

Total face example (period=All):
```
TOTAL IOCS
1,847
across all time
```

Design decisions:
- **Window follows the page-level period selector** (7d/30d/90d). On
  "All", the tile changes identity rather than picking an arbitrary
  fallback window — coherent with how Criminal Time Wasted, Cost per
  IOC, and Actor Dedup all behave on All.
- Respects the page-level `scam_type` filter on both faces.
- `fresh_iocs_delta_pct` is `null` (not 0 or ∞) when prev window is empty.
- Tooltip explicit about which face is rendered and why.
- Excludes header-metadata types (message_id, subject, SPF/DKIM/DMARC,
  x-mailer, return-path) consistent with the rest of the dashboard.
- No mention of external lookups (VirusTotal etc.) anywhere on the tile.

### What's left untouched (out of scope, deferred)

- The Impact page's bottom chart "IOCs per Day (novel vs known)" still
  uses the same flawed VirusTotal-based novelty classification.
- The IOC Explorer page (`/iocs`) also computes `novel_iocs/novel_pct`
  using the same SQL.

Both kept working to avoid scope creep. The legacy `novel_iocs` /
`novel_pct` / `novel_pct_delta` fields are still in the API for those
consumers. A follow-up spec will address them together.

---

## [2.20.0] - 2026-06-16

### Added — Spec 105 (STIX export coverage for human-factor data)

Closes the gap between what ScamBuster captures internally (LLM
enrichment from spec 102, Cognitive Mirror from spec 104) and what a
downstream OpenCTI / MISP / SOC consumer actually receives via the
STIX export surface. An audit on 2026-06-15 found that the export
pipeline had drifted behind two preceding specs: 4 LLM-derived fields
were missing, 5 structural fields were never serialized, the
Cognitive Mirror narrative had no STIX surface at all, and the TAXII
path had its own SQL extractor that risked diverging from the HTTP
one over time.

Four atomic slices, each behind an 8-gate preflight, shipped to main +
demo:

#### Slice 1 — `x_scambuster_context` extension field coverage (P1+P2)

`schema_version` bumped from 1.0 → 1.1. Nine new keys, all additive:

- **LLM fields** (only when `enrichment_status = enriched`):
  `hesitation_detected`, `language_switch`, `enrichment_model`
- **Structural fields** (surfaced when present): `misp_taxonomy`,
  `persona_label`, `stimulus_msg_id`, `reward_value`, `campaign_id`,
  `co_revealed_count`

Affects both HTTP (`POST /api/v1/iocs/export/stix`,
`GET /api/v1/conversations/{id}/export/stix`,
`GET /api/v1/clusters/{id}/export/stix`) and TAXII
(`/api/v1/taxii2/.../objects/`). Builder's null-filter intentionally
keeps boolean `false` so "we looked, the answer is no" reaches the
consumer.

#### Slice 2 — TAXII vs HTTP equivalence regression guard (P4)

New integration test seeds an enriched ioc_context row, runs the same
indicator through both export paths, asserts the
`extensions.x_scambuster_context` blocks are byte-equal after canonical
sort. Future SQL refactors that touch only one extractor now break the
gate.

#### Slice 3 — Cognitive Mirror as STIX 2.1 Note SDO (P3)

`CognitiveMirrorNoteBuilder` emits an OASIS §4.13 Note SDO carrying:
- `content`: human-readable analyst framing (hunted victim profile +
  cognitive lever + mirror analysis)
- `abstract`: short label ("ScamBuster Cognitive Mirror — persona X
  against Y")
- `object_refs`: `[threat-actor-id]`
- `x_scambuster_mirror`: structured property block with generation
  provenance (`generated_by_model`, `prompt_version`, `generated_at`)

Note id is deterministic UUIDv4-shape over `(threat-actor-id,
scam-type-code)` so re-exports yield stable ids consumers can dedup.
Wired into `ConversationStixExportHandler` (singleton + cluster
branches) and `TaxiiService::enrichIocsWithThreatActors`. Silent skip
when no mirror is cached — no error, just no Note.

`PersonaMirrorReaderInterface` extracted from `PersonaMirrorQueryService`
as a test seam (the concrete service is final readonly).

#### Slice 4 — JSON Schema validation for the custom extensions (P6)

Two schemas (`config/stix-schemas/x_scambuster_context.schema.json`,
`config/stix-schemas/x_scambuster_mirror.schema.json`) +
`ExtensionSchemaValidator` (~200 LOC, hand-rolled JSON Schema subset:
type, required, properties, enum, additionalProperties — no
third-party dep). Bundle-level test pulls a real export response and
asserts it validates clean. A future spec that adds a field without
updating its schema fails the gate.

Validator is `public: true` in `services.yaml` but **not** wired into
production export paths — validation runs in the test gate, prod
skips the parse cost.

### Out of scope

- **P5 — Campaign STIXExporter** explicitly dropped per user direction
  (the campaign export path is not used in production).

### Honest limits accepted

- Cluster threat-actors exported via different conversations may carry
  different Notes (keyed on each export's persona+scam_type). Consumers
  dedup via the deterministic note id.
- Custom `x_scambuster_*` extensions are namespaced; consumers that
  strictly enforce the official STIX 2.1 schema with no custom
  extensions will silently drop these fields.
- The Note `content` is editorial framing inferred by an LLM, not
  measured. `x_scambuster_mirror.generated_by_model` surfaces this.

---

## [2.19.0] - 2026-06-15

### Added — Spec 104 (Persona analytics for Black Hat USA)

Four additions and one correction to the Persona area of the app,
driven by a CTI expert review of the existing Performance and
Convergence screens. The fix addresses an honesty bug (P0); the new
views surface comparative claims the white paper makes about the
bandit which the existing UI did not show.

#### Backend

- `PersonaMatrixQueryService` + `GET /api/v1/scambaiting/persona-matrix`
  — single read for the full active-persona × active-scam-type
  matrix (351 rows today on a cartesian, empty cells included so the
  UI can dim them as "not yet sampled"). Bounded, no pagination.
- `PersonaMirrorGenerator` + `app:persona:compute-mirrors` CLI +
  `persona_scam_mirror` table (additive migration) — generates and
  caches the Cognitive Mirror editorial framing (hunted victim
  profile, cognitive lever, mirror explanation) per pair. Fail-safe:
  any LLM failure returns null and the row is simply not written,
  no exception propagates. Budget guard built into the command.
- `PersonaMirrorQueryService` + `GET /api/v1/personas/{code}/mirrors`
  + `GET /api/v1/scam-types/{code}/mirrors` — read paths backing the
  Cognitive Mirror panel.

#### Frontend

- **Persona × Scam Type Matrix** (`/personas/matrix`) — grid view
  with reward-colored cells, winner per column highlighted in
  emerald (only when the cell clears the min-session threshold),
  best-vs-worst gap shown per column header. Demonstrates the "no
  single best persona" claim in one image.
- **Cognitive Mirror** (`/personas/mirror`) — per scam type, shows
  the current winning persona + the LLM-generated editorial framing
  ("Investment fraud hunts retirees flattered to be called investors
  — we deploy 'Optimistic retiree, thrilled'"). Caveat footer says
  explicitly the text is inferred from definitions, not measured.
- **Convergence trajectory** — added a horizontal reference line at
  the convergence threshold + a state banner above the chart
  ("converged on YYYY-MM-DD" in emerald, or "still exploring, N
  sessions so far" in amber) so the viewer can tell convergence
  state from the visual alone.

#### Correction

- **Personas page KPI gate** (P0) — `bestPersona` reduce on the
  headline KPI now applies the `total_sessions >= 3` filter that
  the table rows below already use. Previously a 1-pull persona at
  0.76 reward could dominate the headline while the same row was
  dimmed in the table below. Asymmetry fixed; new test asserts a
  1-pull 0.95 persona never wins the KPI against a 15-pull 0.60
  one.

#### Refused from the audit

- "Demote per-IOC psychological signals" — refused, contradicts the
  shipped + validated spec 102 work. The two layers (per-IOC for
  SOC analysts on individual indicators, per-persona for strategy
  stakeholders on aggregate) serve different audiences and are
  complementary.
- "Strategy comparison (random vs fixed vs bandit)" — deferred,
  requires a controlled A/B/C experiment we have not run. The
  audit's "observational reconstruction" path is methodologically
  weak and dismantles easily under expert questioning.

#### Cost

LLM batch for the Cognitive Mirror cache cost $0.35 (350 successful
generations, 1 idempotent skip, 0 errors, gpt-4o-mini).

## [2.18.0] - 2026-06-15

### Changed — Spec 102 (Human Factor calibration)

Redesigned the ContextualEnricher LLM prompt to address six systematic
biases measured in a 100-IOC test set + 20-IOC gold-annotated subset
(see `phase-d-baseline.md`, `phase-e-s5-report.md`). Drop-in
replacement at constant model (`gpt-4o-mini`) — same JSON wire shape,
same field names, no consumer changes.

#### Measured deltas (prompt v2 vs v1, gpt-4o-mini both)

- `stimulus_type` PASSIVE share: 60% → 33% (target ≤45% met; matches
  the gpt-4o judge distribution of 28% within 5 pp).
- `semantic_role` accuracy vs gold annotations: 65% → **90%**. Top fix:
  marketing / notification / unsubscribe URLs (Apollo, Snov.io, Facebook
  notifications) now route to `INFRASTRUCTURE_DOMAIN` instead of
  `PHISHING_CREDENTIAL_URL`.
- `urgency_score` MAE vs gold: 0.13 → **0.036** (3.6× better). Replaced
  the 10-example calibration scale with 6 sparse anchors tagged
  "illustrative, not buckets".
- `context_excerpt` templating: generic openers banned; concrete pretext
  / alias / framing now required.
- `language_switch_detected` false-positive rate: 20% → **0%**. Strict
  redefinition — TRUE only on intra-message switch of meaningful
  sentences (entire non-English emails = FALSE).
- `hesitation_detected` false-positive rate: 21% → **0%**. Strict
  redefinition — TRUE only on textual self-correction / reformulation.
  Honest caveat: v2 mirrors the gpt-4o judge's 0% TRUE rate; N=1 gold
  positive cannot resolve whether this is correct precision or
  under-detection. Follow-up spec 103 will calibrate with ≥30 targeted
  candidates.

#### Infrastructure additions

Four new CLI commands under `app:eval:*` for reproducible
human-factor evaluation:

- `app:eval:sample-test-set` — stratified train/test split with
  reproducible seed (mt_srand) + SHA256 integrity hashes baked into
  the docblock. 50/100 split, ≥25 per rare class (hesitation=true,
  language_switch=true, non-PASSIVE), ≥30 high-confidence.
- `app:eval:render-ioc` — single-IOC + 3-message-window renderer used
  as input for annotation OR judge harness. Outputs markdown or JSON.
- `app:eval:run-judge` — independent cross-validator that runs gpt-4o
  (or any model) with a separate prompt; saves verdict envelopes for
  metrics comparison.
- `app:eval:compute-metrics` — per-field accuracy, F1, MAE, Cohen's
  kappa, distribution comparison. Outputs Markdown + JSON.
- `app:eval:test-prompt-v2` — runs prompt v2 on a batch of IOCs,
  outputs judge-shape JSON so the metrics harness can ingest with
  `--judge-model enricher-v2-gpt-4o-mini`.

Plus an extension to the existing `app:ioc:compute-context` command:
`--force` now also applies to the LLM enrichment phase (re-enriches
rows already in `enrichment_status='enriched'`). This unlocked the
Phase F backfill of the 4934-IOC corpus with the v2 prompt.

#### Test coverage

`ContextualEnricherPromptTest` and `UrgencyFewShotTest` updated to
assert the v2 guardrails (anti-PASSIVE rule, strict hesitation /
language_switch defs, URL tightening, excerpt specificity, 6-anchor
calibration scale). Both test files retain their original intent
(Spec F3 / 075a urgency calibration coverage) — assertions updated to
match the new mechanism, with rationale in the docblocks.

8/8 preflight gates green on both commits (`ecebd3ee`, `1bb24434`).

## [2.17.0] - 2026-06-13

### Added — Spec 097 (Live Bait Theater)

A new "Replay extraction" experience accessible from any conversation
detail page. Plays back the conversation message-by-message with the
extracted indicators appearing on the right panel as their parent
message is revealed, and a separate Human Factor panel surfacing the
deterministic + LLM-derived signals captured during extraction.

#### Backend

- `GET /api/v1/communication/conversation/{convId}/theater` — composite
  endpoint returning meta + ordered messages + deduplicated IOCs (each
  with its `revelation_context` joined from `ioc_context`) + a
  structured `human_factor` block. Single round-trip, reuses the
  official IOC attribution from `IocHandler::getConversationIocs` (no
  parallel filtering).
- `App\Application\Communication\TheaterAssemblyService` orchestrates
  the assembly. Enforces spec invariants: IOC dedup by `value_norm`,
  orphan IOC exclusion (parent message deleted), 100-message cap with
  `long_conversation_truncated` flag, `stimulus_msg_id` validated to
  belong to the conv.
- `App\Application\Communication\TheaterHumanFactorCalculator` —
  pure deterministic aggregator. Split into two sub-blocks:
  - **deterministic**: `total_turns`, `engagement_hours`,
    `first_financial_turn/_ratio`, `scammer_response_times_hours`
    (median), `cascade_events` (computed on DEDUPED set per spec
    rule #7), `language_switch_count/_turns` (computed from
    `message.lang_detect` deltas — NOT from LLM),
    `persona_pressure_profile`.
  - **exploratory_llm_signals**: aggregates of LLM-classified
    fields (`urgency_score`, `hesitation_detected`, `stimulus_type`)
    with `enrichment_confidence` average + median for transparency.
- `App\Domain\Communication\IocCategory` — pure helper mapping IOC
  type to `financial`/`contact`/`infrastructure`/`other` with an
  EXPLICIT default bucket so future types render without code changes.
- `App\UI\Http\Communication\GetConversationTheaterController` — single
  `__invoke`, `conversation:read` permission, 404 on unknown/deleted.

#### Frontend

- New route `/conversations/:id/theater` (full-screen, outside
  AppLayout but inside AuthGuard).
- New page `pages/Theater.tsx` orchestrating the experience.
- Entry point: "▶ Replay extraction" button in `ConversationDetail`
  header, visible only when the conversation has at least one message.
- 7 new components under `components/theater/`:
  `TheaterHeader`, `TheaterThread` (in/out bubbles + typing indicator),
  `TheaterIntelligencePanel`, `TheaterIocCard` (per-IOC psychological
  footprint with stimulus badge, urgency bar, hesitation chip,
  semantic role with inline confidence + muted style when < 0.40),
  `TheaterPsychologyPanel` (deterministic FIRST as headline,
  exploratory LLM signals SECOND with caveat header), `TheaterTransport`
  (play/pause/restart/skip/scrub/speed), `MaskedValue` (centralized
  sensitive-value rendering — single source of truth).
- 4 new hooks: `useTheaterReplay`, `useTheaterPlayer` (state machine:
  idle/playing/paused/finished + currentStep + speed + typing direction;
  setTimeout-driven, cleanup on unmount), `useMaskMode` +
  `MaskModeProvider` (centralized mask state), `useReducedMotion`
  (matchMedia listener).
- Keyboard shortcuts: Spacebar = play/pause, M = mask toggle.
- 2 new lib helpers: `lib/iocCategory` (mirror of backend, explicit
  default bucket), `lib/iocMask` (length-bucketed mask function with
  4 buckets; phone gets prefix+"*****"+suffix; non-phone < 6 = "***",
  6-11 = prefix+"***", ≥ 12 = prefix+"***"+suffix).
- ~45 new i18n keys under `theater.*` and 1 under
  `conversationDetail.replayExtraction` (EN + FR with parity test).

### Construct validity rules (mandated in code + UI)

- Per-IOC `enrichment_confidence` ALWAYS surfaced inline next to the
  semantic role.
- When confidence < 0.40: muted color + low-confidence icon. NOT
  hidden — visually de-emphasized.
- Psychology panel headline says "deterministic", LLM sub-section
  explicitly labeled "Exploratory LLM signals" with the average
  confidence in its header.
- "Limited coverage" banner shown when `enrichment_coverage_pct < 50%`.
- Causal verbs FORBIDDEN throughout the UI: use "preceded",
  "co-occurred", "labelled as" — never "triggered", "caused", "made".
- Per-conv aggregates ALWAYS prefixed with "In this conversation: …"
  to remind the viewer of single-case scope.
- Footnote referencing ScamBuster persona design (Spec 095) so
  adjacency-vs-causation is explicit.

### Tests (97 new total, all green)

Backend (33):
- 6 unit on `IocCategory` (including the EXPLICIT default bucket
  invariant)
- 12 unit on `TheaterHumanFactorCalculator` (cascade dedup,
  deterministic language_switch, active stimuli dedup by msg_id, etc.)
- 9 functional on the controller (auth, 404, dedup invariant vs
  existing `/iocs`, structure, sort order, human_factor presence)
- 6 integration on `TheaterAssemblyService` (orphan IOC exclusion,
  `stimulus_msg_id` validation rule #9, enrichment coverage match
  between meta and human_factor, empty IOC graceful output)

Frontend (24):
- 5 on `lib/iocCategory`
- 9 on `lib/iocMask` (all 4 length buckets + sensitive type detection
  + end-to-end displayValue)
- 4 on `MaskedValue` — CRITICAL default-masked DOM-search assertions
  (raw BIC and raw phone NEVER appear in the rendered DOM in default
  state, even outside a provider)
- 1 on i18n EN/FR parity (preventive against drift)
- 11 on `useTheaterPlayer` state machine (transitions, cleanup,
  reduced-motion branch, speed multiplier, auto-restart, scrub clamp)
- 4 on `useReducedMotion` (matchMedia mock + listener cleanup)

### Pre-flight investigations

- **Survival bias check** (`specs/097-live-bait-theater/survival-bias-check.md`):
  on the IOC types the Theater actually displays (domain, email, url,
  phone, sha256, iban, ipv4, bic, wallet_*, bank_account, etc.),
  enrichment coverage is 100 %. The 57 % global gap originally
  observed comes from header artifacts that are excluded by
  `IocHandler` anyway. The "limited coverage" UI safety net stays
  but will rarely fire on production data.

### Curated demo conversation set

- `specs/097-live-bait-theater/curated-demo-convs.md` lists 6 hand-
  picked conversations to use in public demos. Never demo a random
  conv blind.

### Spec-kit

- `specs/097-live-bait-theater/` (local, gitignored): spec.md,
  plan.md, tasks.md, self-review.md (15-point adversarial
  self-challenge yielding 11 improvements applied), external-
  review-response.md (response to a separate AI review of the spec,
  8 corrections applied + 5 rejected with justifications),
  survival-bias-check.md, curated-demo-convs.md.

### Preflight gates (8/8 — backend ~500s, frontend ~24s)

Backend: PHPStan max, PHP-CS-Fixer, Unit 3223, Integration 647,
Functional, CompilerPass, E2E, Composer audit — all green.
Frontend: typecheck 0 errors, lint 0 errors, 85 test files / 675
tests green.

---

## [2.16.2] - 2026-06-12

### Fixed — Spec 096 C5 (period filter coverage)

- **`ImpactHandler::getWastedTime.weekly_trend`** previously had a
  hardcoded "12 weeks" window — now respects the period threshold when
  set, so the "Hours Wasted per Week" chart actually narrows when the
  user picks 7d/30d/90d.
- **`ImpactHandler::getIocUniqueness.daily_trend`** previously had a
  hardcoded "30 days" window — same fix on the "IOCs per Day" chart.
- **`ClusterQueryService::getStats`** now accepts an optional `period`
  filter. The 3 conversation-related metrics (`total_conversations`,
  `clustered_conversations`, `singleton_conversations` and the derived
  `taxii_noise_reduction_pct`) restrict to the selected window. Cluster-
  level metrics (`total_clusters`, `largest_cluster_size`, etc.) remain
  unfiltered because a cluster is a long-lived entity that doesn't
  semantically narrow to a 7-day window.
- Frontend: `useClusterStats` now takes a `period` argument, plumbed
  from `pages/Impact.tsx` alongside `scamType`.

### Tests

5 new functional tests covering all three gaps + the regression
guarantee that `total_clusters` remains period-invariant.

---

## [2.16.1] - 2026-06-12

### Added — Spec 096 (Impact dashboard: scammer engagement metric + page-level filter)

- **Scammer engagement (real rate)** card on the Impact page: bias-corrected
  per-real-sender response rate, fixing three biases identified on one month
  of production data (~9 points of underestimation in the naive metric):
  1. **Technical noise** (bounces, DMARC, postmaster) filtered via a
     dedicated `ScammerEngagementNoiseConfig`.
  2. **Right-censoring** of recent engagements (default 96h, covers p95
     of observed reply latency).
  3. **Conversation fragmentation** (outbound `external_message_id` is
     empty in DB): metric computed per real sender across conversations,
     not per conversation.
- **Page-level `scam_type` filter** on the Impact dashboard. A new
  dropdown next to the existing `7d / 30d / 90d / All` period buttons
  drives ALL 5 widgets simultaneously (Criminal Time Wasted, Novel IOCs,
  Cost per IOC, Actor Dedup, Scammer engagement). `period` and `scam_type`
  combine orthogonally (AND).
- **`/api/v1/monitoring/analytics/scammer-engagement`** — new endpoint
  with `censoring_hours`, `scam_type`, `period` query parameters.
  Returns global rate + breakdown by scam type with response/observable
  counts. Single PostgreSQL CTE, leverages existing `headers->>'from'`
  btree index.
- **Optional `scam_type` parameter** on existing analytics endpoints
  (`/api/v1/impact/summary`, `/api/v1/impact/ioc-uniqueness`,
  `/api/v1/clusters/stats`) — when null, response is byte-identical to
  pre-spec-096 behavior (regression-safe).

### Internal

- New `App\Application\Monitoring\ScammerEngagementCalculator` service
  + `ScammerEngagementNoiseConfig` value object.
- `App\Application\Monitoring\ImpactHandler::getSummary` and
  `getIocUniqueness` accept optional `?string $scamType`; all 6
  sub-queries (`getWastedTime`, `getIocValue`, `getCostEfficiency`,
  `getCampaigns`, `computeTrends`, `getIocUniqueness`) filter by scam
  type via `lkp_scam_type` sub-query when set.
- `App\Application\Clustering\ClusterQueryService::getStats` accepts
  `?string $scamType`; uses `primary_scam_types ANY` match on the
  `threat_actor_cluster` table.
- Frontend: `useImpactSummary`, `useIocUniqueness`, `useClusterStats`,
  `useScammerEngagement` all accept the new filters and include them in
  React Query keys for proper cache invalidation.
- New `ScammerEngagementCard` React component (`components/impact/`).
- TODO comments documented in code for future improvements (persist
  outbound `external_message_id` at send time, merge
  `ScammerEngagementNoiseConfig` into the live ingest pre-filter,
  consider a generated `counterpart_email` column for index acceleration
  on long windows).

### Tests

- 33 new backend tests (13 functional + 5 integration ScammerEngagement
  + 4 ImpactSummary C2 + 4 IocUniqueness C3 + 3 ClusterStats C4 + 4
  ScammerEngagement C2b period).
- 5 new frontend component tests; full suite 644/644.
- All 8 preflight gates pass on the feature branch (489s).

### Spec-kit

`specs/096-impact-page-scam-type-filter-and-scammer-engagement/`
(spec.md + plan.md + tasks.md, local-only / gitignored).

---

## [2.16.0] - 2026-06-02

This release bundles two streams of work shipped to `demo` together:
incremental specs 083-093 (auto-mail pre-filter, mailbox UI, threading
fixes, in-flight bandit tracking, CVE patches) AND the audit 095
campaign — 15 fixes derived from the pipeline behavioral audit, lifting
the system from "responds reliably" to "engages effectively".

### Added — Audit 095 (Fix #1-15, May-June 2026)

#### Engagement + IOC harvesting (Fix #5, #6, #7, #8)
- Stage-aware IOC-pull directive in the meta-prompt (BasePromptRules + PromptBuilder), preserving persona-character while pushing for channel-specific information (BIC/SWIFT, postal address, phone) at the right conversation stage
- `IOCLikelihoodScorer` now i18n-aware (FR + EN keywords across channel patterns, proactive triggers, generic-phrase penalties)
- `RetryCoordinator` enforces an IOC-likelihood floor (`iocThreshold` config): replies scoring below threshold trigger a retry; on the 3rd attempt, accept the reply rather than fall back to canned

#### Audit log observability — research-grade introspection (Fix #13, #14)
- `SCAM_CLASSIFIED` event — every classifier call (success or failure) writes a row with `scam_type`, `confidence`, `detected_language`, `message_count`, `error`
- `REPLY_RETRY` event — every gate rejection emits a row identifying which gate (policy_guard | validator | leak_detector | ioc_threshold | validator_error) and the attempt number
- `REPLY_REJECTED` event — every fallback emission identifies the exhausting gate
- `BANDIT_DECISION` event — every persona selection writes the full decision context (selected persona + ALL candidates with UCB1 scores + random_value + epsilon + converged flag), enabling live debugging and academic-grade analysis

#### Reopen policy — recover late scammer follow-ups (Fix #15)
- `allow_reopen=true` with 72h window added to 8 scam types previously denied: PHISHING, PHISH_CREDENTIALS, PHISH_MALWARE, TECH_SUPPORT, JOB_OFFER, INVOICE_FRAUD, CEO_FRAUD, UNKNOWN. Measured loss before fix: 17 % for PHISHING (volume leader), 21-33 % for the others.

### Changed — Audit 095

- `ScamClassifier` confidence threshold lowered 0.75 → 0.55 across 4 callsites + OpenAPI default (Fix #2) — accepts hybrid/ambiguous scams that previously routed to UNKNOWN
- `ReplyValidator` `approved` expression tightened: replies with `ti_value < 3` now rejected (Fix #3), preventing passive engagement that wastes scammer attention
- `REPLY_GENERATED` audit_log payload enriched with validator scores (`naturalness`, `persona_fit`, `ti_value`, `security_pass`, `ioc_likelihood`, `attempts`, `fallback_used`) via a 4-hop propagation chain ValidationResult → ReplyValidator → RetryCoordinator → ReplyHandler (Fix D)
- LLM prompt language migrated FR → EN across ScamClassifier (Fix #4), ConversationHistoryService, ConversationAnalyzer (264-line meta-prompt + tone enum), Campaign/PromptBuilder profiler + rule compiler (Fix #12). Eliminates LLM code-switching on EN-dominant corpus per Chen 2023
- `tone_recommendation` enum FR → EN: `inquiet→worried`, `méfiant→suspicious`, `rassuré→reassured`, `confiant→confident`, `agacé→annoyed`, `déstabilisé→unsettled`, `offensé→offended`
- `ConversationClosureService::closeConversation()` and `closeConversationsBatch()` now actor-aware (Fix #15 part C). Default actors stay `user` for backward compat; CloseStaleConversationsCommand passes `cron`/`system`; CloseConversationController passes the authenticated user identifier. `closure_reason` is propagated through batch close (previously discarded, every cron close mis-tagged as `manual`)

### Fixed — Audit 095

- `ScamClassifier` no longer invents new `scam_type` codes (Fix #1) — disabled belt+suspenders in prompt instructions + parser, novel patterns map to existing 13 codes or UNKNOWN
- `ThreadResolverService::reopenIfNeeded()` now persists the status change via explicit `$em->flush()` (Fix #15 bonus). Pre-existing latent bug: Doctrine's enum-typed status change-tracking was silently dropping the OPEN write. Affected ROMANCE/INVESTMENT/ADVANCE_FEE since their reopen-allowed era — they reopened in memory but never in DB
- `CONVERSATION_CLOSED` audit `actor_id` no longer set to `$convId`; uses the passed-in actor identifier (resource_id still carries the conv_id correctly)

### Added — Specs 083-093 (retroactively documented)

- **Spec 083** — automated-mail pre-filter (DMARC, noreply, postmaster, mailer-daemon) + RFC 2822-compliant sender parser. Introduces `NOT_A_SCAM` scam_type for clean tagging
- **Spec 084** — intrinsic risk scoring `reason` field annotates which trigger fired
- **Spec 085** — outbound message-id persisted in headers for accurate inbound threading
- **Spec 086** — pre-filter writes a structured decision marker, `/risk` endpoint short-circuits on it
- **Spec 087** — operator-facing `/mail-accounts` endpoint + mailbox label/email surfaced on conversation DTOs; MAILBOX column + filter on Conversations UI + SESSION METADATA row in ConversationDetail
- **Spec 091** — `/risk` endpoint returns `should_reply=false` on closed conversations (avoids redundant reply attempts)
- **Spec 092** — `PersonaOptimizer` accounts for in-flight pulls in the UCB1 effective N; eliminates the "stuck persona" feedback loop where bursts of selections weren't deflating the exploration bonus

### Fixed — Specs 083-093

- **Spec 089** — provider_msg_id chevrons normalized in `markAsSent` (closes thread-resolution edge case)
- **Spec 091** — closed conversations no longer trigger phantom reply generation

### Security — Specs 090, 093

- **Spec 090** — Symfony 7.2 → 7.4 LTS + Twig 3.21 → 3.26 (CVE remediation, May 2026 batch)
- **Spec 093** — Symfony 7.4.x patches + Twig 3.27 (CVE remediation follow-up)

### Operational

- `persona_performance_stats` table TRUNCATED on 2026-06-01 20:59 UTC after the P4 bandit audit confirmed it was polluted by demo-fixture seed data (rewards inflated 2-3× vs production reality). Companion table `bandit_convergence_log` (408 historical snapshots) also truncated for symmetry. Bandit is now re-learning organically with measurement checkpoints scheduled at 2026-06-15 (2-week) and 2026-06-29 (4-week).
- No data migration. In-flight conversations safely transition: `PersonaPerformanceStatsRepository::findOrCreate()` handles missing rows transparently; the next closure becomes the first organic reward for that persona × scam_type pair.

### Tests

- 21 new unit tests for Audit 095 fixes (PolicyGuardConfig, ConversationAnalyzer, ConversationClosureService, ConversationLifecycleConfig, PersonaOptimizer, RetryCoordinator)
- 11 new spec-kit `test_cases.sh` scripts for end-to-end live-pipeline validation (one per Fix that touches behavior)
- All 8 preflight gates green (PHPStan max + CS-Fixer + Unit + Integration + Functional + CompilerPass + E2E + Composer audit) across each Fix's merge

### Migration notes

- `ConversationClosureService::closeConversationsBatch()` signature changed from `array<string>` to `array<array{conv_id: string, reason: string}>` + optional `$actorId`/`$actorType`. Only known caller (CloseStaleConversationsCommand) updated; PHPStan max catches missed call-sites
- `ScamClassificationHandler` constructor: optional `?AuditLogger $auditLogger` added as 7th param (backward-compatible)
- `PersonaOptimizer` ctor unchanged; `BANDIT_DECISION` emits if AuditLogger is injected (already wired in production)

---

## [2.15.0] - 2026-05-09

### Added — Spec 050: Multi-Account SMTP Routing

- `mail_account` table extended with 3 nullable columns: `email_address`, `smtp_dsn_encrypted`, `label`
- New `SmtpDsnEncryptor` service: authenticated encryption (sodium XSalsa20-Poly1305) of per-account SMTP DSNs at rest, using a key derived from `APP_SECRET`
- New `SmtpTransportResolver` service: routes outbound replies to the correct SMTP transport based on the conversation's mail account, with request-scoped caching
- New `SmtpDsn` and `EmailAddress` immutable Value Objects (Domain layer)
- New `TransportFactory` (Infrastructure layer) wrapping Symfony Mailer's `Transport::fromDsn()`
- 4 new CLI commands:
  - `app:mail-account:add` — register a mailbox with optional encrypted SMTP DSN
  - `app:mail-account:list` — list mailboxes (NEVER reveals SMTP credentials)
  - `app:mail-account:disable` — soft-disable (sets `is_active = false`)
  - `app:mail-account:rotate-smtp` — replace SMTP DSN with fresh encryption
- `ReplyCompositionService::sendEmail()` now resolves the right mailer per account
- Comprehensive test coverage: 11 unit tests for VOs, 12 for encryptor, 6 for resolver, 11 for manager, 13 integration tests for CLI commands, 9 integration tests for end-to-end routing

### Changed

- `MailAccountRepositoryInterface` extended with `findAll()` and `save()` methods
- `services.yaml` binds `$appSecret` to `APP_SECRET` env var

### Backward compatibility

- Single-mailbox installs (`MAILER_DSN` only) work unchanged
- Existing `mail_account` rows with NULL `smtp_dsn_encrypted` use the global `MAILER_DSN` fallback
- All new columns are nullable; no data migration required

### Security

- Encrypted DSNs use authenticated encryption (tampering throws RuntimeException)
- Decryption failure NEVER falls back silently to global SMTP (prevents leak)
- DSN never logged, never returned by API, never displayed by `list` command
- Rotation impact documented in `docs/14_key_management.md`

---

## [2.14.0] - 2026-04-10

### Added

- Attachment SHA256 hashes now generate observed IOC rows, visible in IOC Explorer and STIX exports
- Actor Deduplication stat card on the Impact page showing cluster noise reduction metrics

---

## [2.13.0] - 2026-04-10

### Added

- End-to-end email attachment capture via IMAP intake pipeline with SHA256 hashing
- Backend attachment parser fallback for producers that forward raw RFC822 without pre-extracted attachments
- Path-aware payload size limits: 50 MB for mail ingestion endpoints, 1 MB default elsewhere
- n8n workflow updated to download and hash attachments at intake time
- 18 tests added

---

## [2.12.0] - 2026-04-10

### Changed

- Refreshed MITRE ATT&CK mappings: replaced deprecated T1534 and retired T1566.004 with T1566.002 and T1656 (Impersonation) for 8 scam types
- Minimum compatible OpenCTI version is now 5.10 (for T1656 support)
- 5 tests added

---

## [2.11.0] - 2026-04-10

### Added

- Direction guard preventing IOC extraction from outgoing (honeypot-generated) messages
- Honeypot email identity filter blocking platform addresses quoted back by scammers
- One-time cleanup command for historical platform contamination in the IOC catalogue
- Configurable `HONEYPOT_EMAIL_ADDRESSES` environment variable for operator-defined filtering
- 15 tests added

---

## [2.10.0] - 2026-04-10

### Changed

- Removed O(n^2) indicator-to-indicator relationship mesh from conversation and bulk IOC STIX exports
- Clustered conversations now attribute indicators to the shared cluster threat-actor in STIX bundles
- Unclustered conversations use a readable "Unattributed Scam Actor (Type)" naming convention
- 27 tests added

---

## [2.9.0] - 2026-04-10

### Added

- Threat Profile section on cluster detail page with dominant stimulus, urgency, and behavioral aggregations
- Campaign Excerpts section showing deduplicated context excerpts with occurrence counts
- Per-anchor IOC behavioral pills (semantic role, stimulus, urgency)
- Navigation links from anchor IOCs to IOC Detail page
- 25 tests added (zero new LLM cost)

---

## [2.8.0] - 2026-04-09

### Added

- Real-time threat actor clustering via Union-Find on financial IOCs (IBAN, crypto wallets, phone numbers)
- Three new database tables for cluster storage and IOC-to-cluster mapping
- STIX 2.1 threat-actor export for clusters with deterministic UUIDs
- TAXII 2.1 third collection (`threat-actors`) for automated CTI feed consumption
- Five API endpoints for cluster listing, stats, detail, STIX export, and IOC-to-cluster lookup
- Frontend cluster list page with KPI cards and cluster detail page with anchor IOCs
- Scheduler-driven backfill every 30 minutes and real-time clustering at ingestion
- Mega-cluster guard flagging clusters exceeding 50 conversations as SUSPECT
- IOC normalization (IBAN whitespace, ETH lowercase, phone digits-only) and whitelisting for known contract addresses
- 125 tests added

---

## [2.7.0] - 2026-04-09

### Added

- IOC severity system: HIGH (IBAN, crypto, phone), MEDIUM (URL, domain, email, IP), LOW (metadata)
- Dominance Evolution chart for persona performance over time
- IOC count column in conversation list (infrastructure IOCs excluded)
- Faceted filters (status, scam type) with URL persistence on conversation list
- Column sorting across conversation list, IOC Explorer, and IOC Detail

### Changed

- Comprehensive UX overhaul across all frontend screens based on CTI/UX expert audit
- Scam type colored badges, risk score progress bars, and precise timestamps throughout
- CLOSED conversation badge changed from red to neutral gray
- Infrastructure IOCs (DMARC/SPF/DKIM) collapsed into a dedicated section in conversation detail
- Campaign UI hidden from frontend (pipeline disconnected)

### Fixed

- Conversation header counters mismatch (abandoned conversations not counted)
- IOC Detail aggregate score reference breaking production build
- ObservedIoc array access bug in ReplyContextService

---

## [2.6.0] - 2026-04-07

### Added

- Threat Actor card on conversation detail showing sophistication, goals, and MITRE ATT&CK mapping
- Threat Actor summary card on IOC detail aggregating attribution across linked conversations
- TAXII IOC feed now includes threat-actor and attack-pattern objects alongside indicators

### Changed

- Removed `attributed-to` STIX relationship (incompatible with OpenCTI)
- TAXII limit parameter now applies to indicators only; enrichment objects are additional

### Removed

- Deleted obsolete GenerateDemoDataCommand (replaced by LoadDemoDataCommand)

---

## [2.5.0] - 2026-04-06

### Added

- STIX 2.1 threat-actor objects in conversation exports with sophistication scoring and behavioral profiles
- MITRE ATT&CK attack-pattern objects and indicator-to-threat-actor relationships
- Deterministic UUIDs for OpenCTI/MISP deduplication across exports

### Fixed

- ActorProfileGenerator column reference and direction ID lookup bugs

---

## [2.4.0] - 2026-04-06

### Added

- Structural IOC context computed at extraction time (revelation turn, scam type, co-revealed IOC types)
- LLM semantic enrichment per message: semantic role, stimulus type, urgency score, behavioral signals
- PII anonymization before LLM analysis with post-analysis validation
- IOC Context API endpoint and frontend Context tab in IOC Detail
- IOC Explorer "Has context" filter and visual indicator
- STIX extension and TAXII feed enrichment with contextual metadata
- Batch backfill command with configurable USD budget cap
- Full English and French translations for all context UI labels

---

## [2.3.0] - 2026-04-04

### Added

- TAXII 2.1 server with 4 endpoints for automated CTI feed consumption (OpenCTI, MISP, TheHive, SIEM)
- STIX pattern mapping for 8+ IOC types with delta sync support
- MFA via TOTP for admin accounts (opt-in, backward compatible)
- Fine-grained RBAC with 12 permissions across 37 controllers
- Dependabot, Trivy container scanning, and CycloneDX SBOM generation in CI
- Governance documentation (GOVERNANCE.md, MAINTAINERS.md, FUNDING.yml)

### Changed

- IocHandler decomposed from 1277 LOC monolith into 4 focused services
- IngestHandler decomposed from 891 LOC into 3 services plus orchestrator
- ReplyHandler decomposed from 941 LOC into 3 services plus orchestrator
- EntityManager removed from all 6 controllers that had direct access
- 5 domain repository interfaces added following hexagonal architecture

### Fixed

- Login rate limiting now backed by Redis (was inoperative static counter)
- GDPR retention aligned: soft-delete at 6 months, hard-delete at 12 months
- Pipeline trace handler direction lookup (was hardcoded, now dynamic)

---

## [2.2.1] - 2026-04-03

### Added

- "Export STIX" button on IOC Explorer page for bulk filtered export

### Fixed

- UTF-8 encoding issue in STIX report names

---

## [2.2.0] - 2026-04-03

### Added

- IOC Detail page with Overview, Observations, and Related IOCs tabs
- Co-occurrence graph with interactive SVG radial layout
- Observation Timeline chart showing IOC sightings over time
- Advanced IOC filters: severity, confidence threshold, date range, hide header IOCs
- STIX 2.1 bundle builder with OpenCTI-compatible extensions and TLP markings
- Conversation STIX export endpoint and download button
- Demo dataset v4 with 1025 IOCs across 9 types

### Fixed

- Telegram username regex (false negatives on word boundaries)
- CVE extraction added to LLM prompt
- IOC category always showing "Unknown" due to hardcoded placeholder bypass
- STIX TLP double-prefix issue and campaign export error

---

## [1.8.0] - 2026-03-30

### Added

- Quality benchmark suite with 3 evaluation commands and 9 quality metrics
- Pipeline monitoring dashboard with per-reply tracing and component waterfall view
- Prompt injection monitoring with scheduled detection and alert dashboard
- Semantic embedding generation using OpenAI text-embedding-3-small
- Actor profile generation (style and infrastructure DNA)

### Fixed

- PolicyGuard configuration now properly wired into ReplyOrchestrator (was dead code)
- Forbidden pattern list reduced from 16 to 6 (too many false positives)
- Reply validation: first-attempt approval improved from 29% to 100%
- Feedback loop: engagement duration and turn count now computed from real data (were always 0)
- Reward calculation command fixed (idempotence check was bypassed)
- LLM cost estimator wired correctly (was using 16x underestimate)
- IOC multi-observation confidence boost now applied after indicator upsert
- 8 additional audit event types wired into the audit trail
- Language compliance: French cultural markers stripped for non-French replies

---

## [1.7.0] - 2026-03-25

### Added

- CI pipeline with Docker-based test execution in GitHub Actions
- Security headers (CSP, HSTS) on all API responses
- Dependency audit blocking in CI on new CVEs
- MISP/ATT&CK taxonomy mapping for all 13 scam types
- Community files: Code of Conduct, GitHub Discussions, first release
- GDPR Data Protection Impact Assessment
- PII masking in logs (emails, IPs)
- Automated PostgreSQL daily backup via scheduler
- OpenAPI 3.0 documentation covering 43+ endpoints with Swagger UI
- PHPStan level 6 full coverage (0 errors on entire src/)
- IOC confidence scoring with temporal decay and configurable half-life
- SIEM connector with CEF, ECS, and JSON formatters (pluggable adapter pattern)
- Campaign detail page with LLM profile generation and STIX export
- Convergence history and rate limits on monitoring pages

### Changed

- Per-sender rate limiting with flood detection
- Human delay simulation in outbound replies (log-normal distribution)

### Fixed

- Dashboard and conversation list count mismatches
- Settings page: exploration rate and best persona display

---

## [1.5.0] - March 2026

### Added

- OWASP security headers on all responses (6 headers)
- Structured audit trail with 16 event types and filterable API endpoint
- Request trace ID (`X-Trace-Id`) on all requests with Monolog integration
- JWT migrated from HS256 to RS256 with key rotation support
- Fine-grained RBAC with 12 permissions via PermissionVoter
- Payload size limit (1 MB) on all API requests
- CI security scanning: composer audit and Gitleaks secret detection

### Fixed

- Removed all `error_log()` calls from production code
- LLM providers no longer log prompt or response content, only metadata

---

## [1.4.0] - March 2026

### Added

- Multi-LLM provider support: Anthropic (Claude), Ollama (local inference), and Mock (demo mode)
- LLM cost tracking with per-model pricing, monthly totals, and daily trend API
- Demo mode (`LLM_PROVIDER=mock`) with 123 synthetic conversations
- Health check and Prometheus metrics endpoints
- MISP integration guide and connectivity test command
- Complete API and database schema documentation
- GitHub issue/PR templates and CI for frontend (TypeScript, ESLint, Vitest)
- Environment validation scripts

### Fixed

- 4 pre-existing test failures (auth headers, detached entities, unique constraints)
- Frontend Docker build and ESLint configuration issues

### Removed

- Manual n8n workflow management script (credentials must be configured via UI)

---

## [1.3.0] - March 2026

### Added

- English synthetic dataset generation (100+ conversations)
- n8n workflow anonymization for public preview

---

## [1.2.0] - January 2026

### Added

- A/B testing validation framework (2,221 synthetic conversations, p < 0.001, Cohen's d = 0.37)
- Test suite expanded to 1,039 automated tests

---

## [1.1.0] - December 2025

### Added

- Prompt injection detection via two-layer forensic analysis
- Scaled platform to 1,000+ active conversations

---

## [1.0.0] - 2025-11-21

### Added

- Adaptive scambaiting module with epsilon-greedy persona selection (80/20 exploit/explore)
- Conversation metrics: engagement duration, turn count, normalized reward scoring
- Persona performance tracking per scam type with cold-start handling
- Automated conversation closure with batch support and event-driven reward updates
- 5 REST API endpoints for conversation lifecycle and persona statistics
- n8n workflow for daily conversation closure (48h inactivity threshold)
- 40+ tests with ~95% coverage

---

## [0.9.0] - 2025-11-15

### Added

- Conversation history summary for LLM context enrichment

---

## [0.8.0] - 2025-11-10

### Added

- Post-IBAN capture strategy for eliciting additional IOCs from scammers

---

## [0.7.0] - 2025-11-05

### Added

- Multi-conversation support per sender email

### Fixed

- Duplicate attachment upload error

---

**Format**: [Keep a Changelog](https://keepachangelog.com/)
**Versioning**: [Semantic Versioning](https://semver.org/)

# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

---

## [Unreleased]

### Threat-intelligence taxonomy & extraction fixes
- **ATT&CK mapping for `CEO_FRAUD` and `INVOICE_FRAUD` corrected to `T1656` (Impersonation)**,
  aligning them with the other impersonation-first scam types — MITRE ATT&CK T1656 explicitly
  covers business email compromise. Both were mapped to `T1566.002` (Spearphishing Link), a
  weaker fit, and the value had drifted between the live database and the seed/fixture sources.
  A corrective idempotent migration converges every source — the taxonomy table, the fixtures
  and both seed files, and the denormalized `ioc_context` snapshot copies read by the IOC-level
  STIX/TAXII exports — and a full seed-consistency test locks the mapping against future drift.
- **TTP extraction hardened against truncation and malformed input**: the extractor's
  output-token ceiling is raised (2000 → 4000) so a multi-label message tagging many tactics
  no longer truncates and silently loses TTPs, and inbound text is scrubbed to valid UTF-8
  before the LLM call so an undeclared Japanese (JIS) body can no longer fail the request.

### TTP analyst experience
- **TTP Explorer restructured into four deep-linkable tabs** (`/ttps?tab=taxonomy|analytics|playbooks|review`):
  the searchable, sortable taxonomy table (rows now open a per-TTP detail page), an analytics tab
  (kill-chain phase distribution plus an 8-week phase-evolution trend, bucketed on message time),
  the cluster × TTP shared-playbook matrix, and the review queue.
- **Per-TTP detail page** (`/ttps/{code}`): overview (definition, kill-chain phase, usage counters,
  first/last seen, the taxonomy's example formulations and external ATT&CK references), co-occurring
  IOCs, clusters practicing the TTP, and a server-paginated conversation list that keeps review-only
  conversations visible (confirmed/review split per row).
- **Read-only review queue**: below-threshold observations listed newest message first, sortable by
  TTP, confidence, conversation, message date and provenance; capped at the 500 most recent with the
  true total always shown. This is triage v1 — inspection only, no confirm/reject action yet.
  Expanding a row reconstructs the quoted evidence **client-side from the stored character offsets**
  over the message body (fetched through the existing conversation endpoints): the evidence text
  itself still never leaves the database through any API. Excerpts are masked by default (IOC values
  and the honeypot address) with an explicit reveal toggle, and a row whose extraction returned no
  verbatim offsets shows an honest "paraphrased" state instead of a fabricated quote.
- **Conversation thread annotations**: neutral stimulus chips on outbound messages (temporal wording,
  no causal claim), a "preceded by" link from revelation-carrying inbound messages back to the
  attributed outbound stimulus (scroll + highlight, rendered only where enrichment produced the
  attribution), dashed chips plus a legend for awaiting-review observations, and revealed-IOC chips
  now filtered server-side to actionable types so header/transport noise no longer clutters the
  timeline.
- **Four new read-only endpoints** — `GET /ttps/review-queue`, `GET /ttps/phase-trend`,
  `GET /ttps/{code}/clusters`, `GET /ttps/{code}/conversations` — and the taxonomy payload now
  carries each entry's example formulations and external references. All aggregate time axes use
  the message timestamp. New analyst guide: `docs/26_reading_the_ttp_screens.md`.
- **Analytics and Playbooks tabs are now sub-tabbed** (a second `?view=` deep-link scoped to the
  active `?tab=`; unknown/absent falls back to the tab's first sub-view without rewriting the URL):
  Playbooks splits into *Matrix | Sequences | Phase transitions* and Analytics into *Phase activity
  | Persona × TTP | Stimulus × TTP*. Only the active sub-view's panel mounts, so heavy matrices load
  on demand.
- **TTP sequences**: cross-message tactic bigrams rendered as ordered `A → B` chips, grouped by
  cluster or by scam type. Pairs are formed only across message boundaries (same-message TTPs are an
  unordered co-occurrence set). **Support is the number of distinct conversations** a pair appears in
  — a pair must recur in at least 2 conversations to surface (the honest "shared playbook" sense) —
  and each chip shows both its occurrence count and its conversation count; the minimum-support
  threshold and any group-cap truncation are stated on the panel.
- **Kill-chain phase-transition matrix**: a 6 × 6 from-phase × to-phase grid aggregating those
  cross-message bigrams by the phase of each endpoint, shaded by volume with the total shown.
- **Persona × TTP matrix**: per-conversation counts (a chatty conversation cannot inflate a persona);
  rows below the headline threshold are dimmed as provisional and never shaded; null-persona
  conversations are excluded from the grid and reported in a footnote; the persona set is capped
  widest-first with an explicit truncation note.
- **Stimulus × TTP matrix**: outbound stimulus × TTP, scoped honestly to revelation messages that
  carry **both** an enriched stimulus type and a confirmed TTP (a stimulus-only or TTP-only message
  is in no cell); the population size *n* and that scope are stated under the matrix, and the
  no-signal `UNKNOWN` row is collapsible and sinks to the bottom.
- **Shared-playbook matrix improvements**: a raw / per-conversation normalization toggle, a "by
  playbook similarity" row ordering (client-side, alongside the existing size sort), and column
  headers that show the abbreviated TTP label with the full code + definition on hover.
- **Cluster Detail page restructured into four deep-linkable tabs** (`?tab=overview|ttps|indicators|campaigns`):
  Overview (psychological profile + activity), TTPs (the cluster TTP panel, including its tactic
  sequences), Indicators & Conversations (kept together so anchor-IOC selection still filters the
  conversation list), and Campaigns & Abuse; Export STIX stays a header action on every tab.
- **Four further read-only endpoints** (all `ioc:read`) — `GET /ttps/sequences`,
  `GET /ttps/phase-transitions`, `GET /ttps/persona-matrix`, `GET /ttps/stimulus-matrix` — and the
  cluster × TTP matrix payload now carries per-cluster and per-cell conversation totals for the
  normalization toggle.
- **Richer, honestly-seeded demo coverage**: per-message stimulus variation (a deterministic arc
  keyed to each message's turn, with `stimulus_msg_id` pointing at the real preceding outbound) so
  the Stimulus × TTP matrix and its collapsible `UNKNOWN` row have content, and full taxonomy
  coverage so all 27 codes surface at least one demo observation and every per-TTP detail page
  renders. Demo rows remain deterministic seeded approximations stamped `demo-seed` (never model
  output), with every stored evidence quote an exact verbatim substring.
- **Scammer TTPs in the Live Bait Theater**: the replay now overlays the scammer's confirmed
  tactics as the conversation unfolds — per-turn phase-coloured chips on each revealed inbound
  message, the verbatim evidence span highlighted in the body (reconstructed from offsets only,
  with PII masking preserved over the highlight), and a kill-chain progress strip that fills as
  each phase is reached. The Theater payload gains a confirmed-only `ttps_by_msg` field in the
  same single round-trip; review-status observations and verbatim quotes are never sent.

### Scammer TTP intelligence
- **The platform now extracts the scammer's tactics (TTPs) from inbound messages**: a
  closed 27-entry taxonomy across a six-phase scam kill chain (hook, trust-building,
  payment-request, escalation, channel-switch, exit), seeded in the database with
  per-entry definitions, example formulations, persona-stimulus affinities and MITRE
  ATT&CK references where an honest mapping exists. TTPs describe the SCAMMER's
  observable behaviour and stay strictly separate from the existing stimulus categories,
  which describe our own personas' actions.
- **Extraction runs in-process right after ingestion** (the same post-ingest hook the
  scam classifier and injection detector use — no new external workflow, n8n untouched):
  a multi-label LLM agent tags each inbound message against the closed vocabulary, with
  one feedback retry on format/vocabulary failures and a strict whitelist so an invented
  label can never be persisted. Outgoing messages — our own replies — are never tagged
  (hard direction guard + regression test). A manual per-message endpoint remains for
  operators.
- **Observations are idempotent and review-aware**: one row per (message, TTP) with
  confidence, status (below the configurable threshold: queued as `review`, never
  silently dropped), taxonomy version and model/prompt provenance. The verbatim evidence
  quote is stored for analysts and law-enforcement handover but is STRICTLY internal: it
  never appears in any API response, audit payload or export — API consumers only ever
  see the character offsets.
- **Consolidated read models and analyst UI**: per-conversation ordered TTP sequences and
  per-cluster frequencies / first- and last-seen / top sequences are computed on read (no
  cron, no staleness) and exposed through read endpoints (`GET /conversations/{id}/ttps`,
  `/clusters/{id}/ttps`, `/ttps`, `/ttps/cluster-matrix`, `/ttps/{code}/iocs`,
  `/iocs/{id}/ttps`). The analyst UI adds a cluster TTP panel, a per-conversation
  stimulus → TTP → IOC elicitation timeline, and a TTP Explorer (phase distribution,
  cluster-overlap matrix, IOC ↔ TTP pivots). Aggregate and cluster payloads never carry
  the evidence text.
- **CTI export**: each taxonomy entry maps to one stable STIX 2.1 attack-pattern
  (deterministic UUIDv5, created once and reused everywhere) carrying `kill_chain_phases`
  over the six scam phases; cluster aggregates drive `threat-actor uses attack-pattern`
  relationships (with start/stop times) and per-cluster sightings, and MISP events gain
  `scambuster:ttp` tags plus verified MITRE ATT&CK galaxy tags. No evidence text is ever
  included in any STIX, TAXII or MISP output.
- **Backfill and audit tooling**: `scambuster:ttp:backfill` extracts TTPs over historical
  inbound messages (preview by default, batched, budget-capped, resumable, idempotent,
  with a `--force` recompute). `scambuster:ttp:audit-sample` exports a random sample of
  observations — the ONLY path by which evidence text leaves the database — to a clearly
  labelled internal CSV for a human precision audit; it computes no precision figure,
  because no such number is meaningful until the sample has been scored by hand.
- **Demo seeding**: `scambuster:ttp:demo-seed` populates plausible observations for the
  keyless public demo (which runs the mock LLM, so the real extractor cannot run there) by
  phrase-matching the demo message bodies against a scam-type-aware tactic map. Evidence
  quotes are genuine verbatim substrings with correct offsets, but the rows are deterministic
  approximations stamped `extraction_model = demo-seed` — never mistaken for real extractions;
  the seed is idempotent (`ON CONFLICT DO NOTHING`) with `--purge` / `--dry-run`.
- The whole module sits behind `TTP_EXTRACTION_ENABLED` (default on) and fails safe:
  disabled or failing extraction never affects ingestion, IOC extraction or replies.

### Runtime safety guards
- **PolicyGuard now rejects messenger-link and redirect-email pivots at send time** (`t.me` /
  `telegram.me` / `wa.me` links and email addresses), closing the gap where these concrete
  off-thread channels were detected by the offline safety oracle but not blocked by the runtime
  guard. The patterns are byte-identical to the oracle's (drift-tested) and block none of the
  reference reply set — reply quality and the fallback rate are unchanged there; a live thread
  carrying a scammer-supplied email may occasionally route a reply to a retry (the generator is now
  given an actionable reason to drop the channel and stay on the thread). Bare platform names
  are intentionally still allowed (naming a platform to elicit the scammer's handle is desired
  intelligence-gathering, not a leak).
- **The reply prompt now tells the persona never to hand out an alternate email address**, matching
  the runtime redirect-email block above. Previously the shared safety rule listed phone / messaging
  apps / crypto wallet / IBAN / postal address but not a second email, so a model that offered one
  was only caught after generation (a wasted retry); the instruction and the guard now agree.

### Operations
- **The self-contained demo now runs under its own isolated Docker Compose project**
  (`scambuster-demo`, declared directly in `docker-compose.demo.yml`). Because the demo and
  development compose files share the `postgres`, `redis` and `frontend` service names, running the
  demo under the repository's default project could previously recreate the shared database container
  and rebuild the shared frontend image; the dedicated project keeps the demo's containers, volume
  and images fully separate from any development stack, whether started with `make demo-up` or a bare
  `docker compose -f docker-compose.demo.yml`. The demo dashboard's host port is now overridable
  (`DEMO_FRONTEND_PORT`, default `3002`) so it can run alongside a development frontend. The commands
  themselves are unchanged.
- **Weekly cleanup now purges old prompt-canary jobs** (`app:cleanup:weekly --canary-days`, default
  30): terminal (succeeded/failed) validation jobs older than the window are deleted so the
  `prompt_canary_job` table — which stores a candidate prompt body and a verdict JSON per row —
  no longer grows unbounded. Pending/running jobs are never touched, and the sweep is `--dry-run`-able.

### Continuous integration
- **Weekly real-LLM GUARD regression run** (`.github/workflows/guard-nightly.yml`): a scheduled
  (and manually-dispatchable) workflow generates replies over the fixed scenario set with a real
  model and diffs the candidate against the frozen baseline, catching generation/prompt drift on
  the default branch that the per-PR offline guard checks cannot. It is skipped gracefully unless an
  `LLM_API_KEY` repository secret is set, fails fast if the provider is not a real model, and never
  writes the secret to an artifact.

### Operator-customizable reply prompts
- **Persona voice & style rules are now editable from the UI** (`persona_style_rules`): greeting,
  tone, name-handling, signing, anti-repetition — so each install can give its personas a distinct
  voice. It is the first prompt that shapes the actual replies, so the regression gate genuinely
  exercises it and the "Validate this prompt" button is offered for it. The safety rules (no
  out-of-band channel, stay-on-email, careful-buyer, language fidelity) are enforced separately in
  code and can never be relaxed by an override.
- **The Conversation Director is now tunable from the UI** (`conversation_director_strategy`,
  `conversation_director_tone`): operators can reshape how the Director infers each scam's goal and
  varies reply shape, and swap its tone palette — so each install runs a distinct strategy. Both
  are exercised by the reply canary, so "Validate this prompt" is offered for them. The Director's
  JSON output contract, the anti-unmask / never-re-ask rule, hostile-scammer detection and language
  fidelity stay locked in code and can never be edited by an override.

### Prompt regression gate
- **Regression gate** for prompt changes: it runs the real reply pipeline over a fixed scenario
  set, scores every generated reply, and compares against a committed baseline — flagging any
  safety or behaviour regression before a prompt reaches production. It checks per-invariant
  safety-violation rates and the two-sided fallback rate, fails closed on an empty/thin/errored
  run, and integrity-checks the baseline before use. The decision is deterministic and offline
  (no LLM, no human judge); the tolerance is code-owned, not an operator setting.
- **In the admin UI**: a "Validate this prompt" button on each prompt runs the check on the
  candidate asynchronously and shows the verdict (safe, or the list of regressions) before the
  operator activates it — no command line. Served by an always-on `canary-worker` container;
  `config:write`-gated and audit-logged. The candidate is used only for the check, never activated.
- **From the CLI/CI**: `make guard` (or `scambuster:guard:check` for the fast offline decision)
  runs the same gate and exits non-zero on regression. Opt-in pre-push hook
  (`make guard-hook-install`) reminds — or, with `GUARD_ON_PUSH=1`, blocks — when a push changes
  prompt-affecting files. See
  [Prompt Customization](docs/25_prompt_customization.md#validating-a-prompt-change-regression-gate).
- **Precision, not keywords**: the gate now flags only a concrete safety leak the persona *gives
  out* (a wallet, a contact handle/link/phone). *Asking* the scammer for their payment details or
  *naming* a platform to elicit their handle is desired intelligence-gathering and no longer
  counts as a regression — keeping the verdicts high-signal. A build-time lock keeps the frozen
  baseline in sync with the oracle rule set.
- **Validation survives a refresh**: the "Validate this prompt" card re-attaches to a running
  validation on load (via a new latest-job lookup) and restores the candidate it is checking into
  the editor — even an unsaved one — so a refresh or navigation no longer drops the in-progress run.
  A finished verdict re-appears only when the saved prompt still matches what was validated, so a
  stale result for a since-replaced prompt is never shown as current.
- **Availability-aware, UI and server-side**: the "Validate this prompt" button is shown only where
  the canary can actually produce a verdict — a reply-path prompt *and* a live model provider in
  this deployment (OpenAI / Anthropic / Ollama with its credentials; keyed off `LLM_PROVIDER`). A
  mock or keyless deployment (e.g. a public demo) hides it with a short "validation unavailable"
  note instead of offering an action that could only hang. The request endpoint enforces the same
  rule as a backstop: a direct API call on such a deployment is refused with `503 Service
  Unavailable` rather than enqueuing a job that could never complete.

---

## [1.0.0] - Initial public release

First public, open-source (MIT) release of ScamBuster — an automated scambaiting
honeypot and threat-intelligence platform. This entry summarizes the feature set
at first release rather than the internal development history.

### Ingestion & threading
- IMAP intake of inbound scam email via n8n workflows, with RFC 5322 threading,
  deduplication, and attachment capture.
- Per-account mailbox support with encrypted, per-account SMTP for correctly
  aligned outbound replies.
- Prompt-injection forensics on inbound messages (pattern matching + LLM-as-judge).

### Multi-agent LLM pipeline
- **Classifier** — categorizes each scam into one of 13 scam types (plus an UNKNOWN fallback) and detects language.
- **Generator** — writes persona-driven replies aimed at maximizing intelligence yield.
- **Conversation director** — an LLM reasoning step that steers the generator each
  turn: it tracks what the correspondent has already revealed (so the persona never
  re-asks), infers the extraction objective from the scam's own mechanics, and
  signals when an exchange is no longer productive so it can be closed rather than
  continued.
- **Validator** — two-layer safety and quality gate (deterministic PolicyGuard +
  LLM validator) run on every outbound message.
- **Extractor** — extracts and normalizes 36 IOC types with contextual enrichment.
- **Injection detector** — classifies hijack attempts against a defined taxonomy.
- **Orchestrator** — coordinates the pipeline, tracks per-reply traces and cost.
- Additional guard stages prevent the persona from initiating payment topics or
  leaking operational details, and score replies for intelligence value.
- Multi-provider LLM support (OpenAI, Anthropic, Ollama for fully local operation,
  and a Mock provider for demos) selectable with one environment variable.

### Adaptive strategy selection
- Epsilon-greedy contextual bandit with UCB1 exploration learns which persona
  works best per scam type, updating after every conversation.
- Cold-start handling and per-scam-type convergence tracking.
- Hybrid learning signal: an LLM outcome judgement (was high-value intelligence
  obtained, was the persona exposed?) blended with the mechanical engagement
  metrics, so the bandit learns from real outcomes rather than raw activity.

### Intelligence output
- Contextual IOC enrichment: semantic role, stimulus type, urgency, and a
  PII-free context excerpt attached to each indicator.
- STIX 2.1 export per conversation (indicators, threat-actor, attack-pattern,
  relationships), validated for OpenCTI import.
- TAXII 2.1 server with delta sync, and MISP Event JSON export.
- Real-time threat-actor clustering (Union-Find on financial IOCs) with
  behavioral profiling and campaign views.
- Pluggable SIEM connector (CEF / ECS / JSON).

### Safety & operations
- Inbound-only engagement, content filtering, hard rate limits, and a
  multi-level kill switch.
- Human-delay simulation for realistic response cadence.
- Append-only audit log with an HMAC-SHA256 integrity chain and a verification command.
- LLM cost tracking with a configurable monthly budget cap.
- MITRE ATT&CK and MISP taxonomy mapping for scam types.

### Interface
- React dashboard: conversations, IOC explorer, threat-actor clusters, personas,
  pipeline and cost monitoring, and STIX export.
- Bilingual UI (English / French) via i18n.

### Operator prompt customization
- Operator-configurable LLM prompts without editing code: override the generative
  prompts (IOC-context enrichment, outcome-scoring rubric) from a **Prompt Customization**
  admin page or from git-tracked files, with the shipped default as a fail-safe fallback.
- Resolution precedence: database override (UI) → file override → shipped default; an
  absent, empty, or invalid override degrades safely to the next source.
- The admin page shows the shipped default read-only, explains every placeholder, validates
  required tokens, and supports enable / disable and one-click revert — `config:write`-gated
  and audit-logged.
- Overrides steer generative prompts only and can never relax the deterministic safety
  guards, which run independently of any prompt.
- `scambuster:prompt:diag` console command reports which override is active.

### Project
- DDD backend (PHP 8.3 / Symfony 7) with a comprehensive automated test suite.
- Docker Compose for local install and production deployment.
- Full documentation set, quickstart, and demo mode (no API key required).

[1.0.0]: https://github.com/laugiov/scambuster/releases/tag/v1.0.0

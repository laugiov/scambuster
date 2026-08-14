# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

---

## [Unreleased]

### Safety & observability fixes

- **The inbound mail no longer chooses who receives our reply.** The reply path picked its
  recipient from `reply_to` before falling back to `from`. That was believed to be inert,
  on the reasoning that the parser stores the header as `reply-to` with a hyphen so the
  underscore key could never match. **The reasoning was wrong and we verified it against
  the real parser.** Header names are lowercased but never normalised — the parser splits
  on the first `:` and validates nothing — so a scammer who writes `Reply_To:` with an
  underscore lands a literal `reply_to` key and picks who receives a deceptive mail sent
  from the operator's mailbox. Combined with the safelist default below, that was a live
  path from an inbound email to arbitrary outbound mail. The recipient is now taken from
  `from` and nothing else. Note this also means a *legitimate* hyphenated `Reply-To:` is
  not honoured — it never was, since only the underscore key was ever read, so nothing
  changes for real traffic; it is recorded here as a known limit rather than left implicit.
  **What this does not close**: a scammer mailing from infrastructure they own presents a
  legitimate `from`, and replying to them is the product working as intended. And the
  outbound `From:` — hence the SMTP envelope sender — is still derived from the inbound
  `To:`/`Delivered-To:` headers, so a sender can still influence what address the relay
  emits from. That is pre-existing, untouched here, and next in line.

- **A reply addressed to ourselves is refused, and the check no longer trusts the attacker
  to define "ourselves".** The first version of this guard compared the inbound `From:`
  against a honeypot address read from the inbound `To:` — two values written by the same
  hand. Review caught it and a proof-of-concept confirmed it: a `To:` naming a decoy first
  (the parser keeps only the first address of a multi-address `To:`) walked straight past
  it, and an empty value turned the guard off silently. The comparison is now against
  addresses we know are ours — the mail account's own address and `HONEYPOT_EMAIL_ADDRESSES`
  — and both bypasses are pinned by tests.

  The first attempt at this **refused outright** when no identity was configured, on the
  principle that a guard which cannot run must not pass. CI settled that: ten end-to-end
  tests went red, because `HONEYPOT_EMAIL_ADDRESSES` is empty by default and the fixture
  mail accounts carry no address — which is exactly what a fresh deployment looks like. It
  was the wrong trade. The risk being prevented is a self-loop: contained, and requiring
  the sender to spoof our own address. The cost was every reply the honeypot would ever
  send. It now proceeds and logs an error naming the variable to set, and the inbound
  `To:`/`Delivered-To:` are added as *extra* comparison candidates — never the only ones,
  which is what the decoy defeated, and as extra entries they can only add refusals.

- **Automated mail no longer gets an answer, and the rule is deliberately narrow.** Inbound
  messages carrying RFC 3834 `Auto-Submitted` or `List-Id` are refused before the model is
  called rather than after — an auto-responder ping-pong would otherwise have cost one
  generation per round. `Precedence: bulk` was in this set during review and was **removed**:
  it marks mass mail, and mass-mailed advance-fee fraud is precisely what this honeypot
  exists to engage, so refusing on it would have silenced the product against its main
  input. This does not overlap the existing ingest pre-filter, which matches on local-parts
  and known domains and reads none of these headers.

- **A refused reply answers 200 with `skipped`, not an error.** The intake workflow calls
  reply generation inside a batch loop whose node has no error branch, so a non-2xx
  response aborts the loop and the remaining IMAP items of that batch are never ingested.
  A refusal is permanently unsatisfiable for that message; dropping a batch to report it
  would be worse than the refusal. Refusals now carry a machine-readable `reason`
  (`auto_submitted`, `self_addressed`, `no_sender`) via a dedicated exception type, so a
  caller can tell a safety refusal from a failure.

- **`SCAMBUSTER_SAFE_DOMAINS` no longer defaults to `*` in `.env.dist`, and the safelist now
  reads addresses the way the sender does.** `*` disables the recipient check entirely and
  was the *recommended* production value, on the reasoning that a honeypot only ever hears
  from scammers. That reasoning does not survive contact with the send path, where the
  recipient comes from the inbound mail. The `.env.dist` default is now empty; `*` remains
  supported and is documented as a decision to make rather than a recommendation, because
  neither `*` nor a strict allowlist is honestly defensible for a honeypot that answers
  strangers by definition. The demo stack keeps `*` on purpose and says why — its
  `MAILER_DSN` is `null://null`, so nothing leaves it. Separately, the check extracted the
  domain with `strrchr` on the raw header while delivery parses with Symfony's `Address`:
  `victim@target.example@gmail.com` read as `gmail.com` to the safelist and went to the
  literal string on the wire. Both sides now parse identically. Note this also **loosens**
  one case: `Bob <user@allowed.example>` used to fail the check (`strrchr` returned
  `allowed.example>`, bracket included) and now passes, which is the intended reading.

- **Duplicate headers resolve to the first occurrence, not the last.** RFC 5322 §3.6 allows
  at most one `From`, `To`, `Reply-To`, `Subject`, `Message-ID` and friends, so a second is
  malformed and usually forged. Reading the last made the backend disagree with MTAs and
  every upstream parser about who sent the mail. **This is a behaviour change**: an ingest
  test that pinned the old last-wins result was updated, not worked around. Which header
  names were duplicated is recorded on the message as `x-scambuster-duplicate-headers`
  rather than dropped — it is a forgery signal, not noise. That marker is **stripped from
  inbound mail** before ours is written: review found a sender could otherwise set it and
  manufacture a forgery signal on a mail with no duplicates at all. Headers RFC 5322
  permits more than once, such as `Received`, keep last-wins as before.

- **Known limit, stated rather than implied.** ScamBuster performs no SPF or DKIM
  verification of its own: it reads a textual `Authentication-Results`, preferring
  `ARC-Authentication-Results`, both of which travel inside the message and are therefore
  written by the sender. None of the guards above depend on that value, deliberately. Any
  future alignment check on this path is decorative until the edge MTA strips inbound
  `Authentication-Results`/`ARC-*` headers and rewrites its own.

- **Retention: the weekly job now reports what permanent erasure would remove, and the
  Article 30 record finally describes what actually runs.** An audit reported that GDPR
  retention did not execute. Verification found something narrower and more interesting:
  soft deletion *does* run weekly — at a 90-day threshold, from `app:cleanup:weekly`, more
  aggressively than the compliance record claimed — while permanent erasure never ran at
  all, because the routine implementing it sat behind a command attached to no schedule.
  The erasure stage is therefore added to the job that already runs, rather than scheduling
  the unscheduled command: doing the latter would have introduced a **second weekly
  soft-deletion pass** with a competing six-month threshold beside the ninety-day one
  already in force. Every run now reports how many conversations, and how many messages,
  are eligible for erasure. **Nothing is erased by default**: the deletion is irreversible
  and requires an explicit `--erase` flag that the scheduled invocation does not pass, and
  the existing `--dry-run` suppresses it like every other stage. `scheduler.sh` is unchanged.
  The record of processing was inaccurate on three counts — the threshold, the job
  responsible, and whether erasure happened at all — and is corrected, along with two
  scope statements it never made: only *closed* conversations are ever soft-deleted, and
  messages are removed at erasure time rather than at soft-delete time.

- **The protective ceilings now apply to the automatic reply flow.** A single `force` flag
  waived both the deliberate reply spacing *and* the three Redis ceilings (replies per
  conversation per day, model calls per hour, conversations per day). The automatic workflow
  sets that flag on every inbound message, so the ceilings never ran on the path that
  generates essentially all traffic — leaving only the monthly budget as a cost guard.
  The flag now waives the spacing alone, which is its legitimate honeypot purpose: a target
  who writes back deserves an answer, and the alternation invariant (never two consecutive
  outbound messages, unwaivable) already prevents any burst. Ceilings are governed separately
  by a new `warning|enforce` mode, mirroring the LLM budget cap. **It ships as `warning`, so
  this release refuses nothing**: a breach is recorded through the existing log and audit
  event and the reply proceeds, producing the evidence needed to decide whether to enforce.
  An unrecognised mode resolves to `warning` — enforcement can never be reached by a typo.
  Operators keep a full override through a new optional `bypass_rate_limits` request field,
  which the automatic workflow does not set; **n8n needs no change**.
  Two consequences worth knowing. The limiters are now *consumed* on the automatic flow where
  they previously were skipped, so breach counts are meaningful only from deployment onward
  and cannot be back-filled. And the third ceiling is misnamed: `active_conversations_per_day`
  consumes one token per *reply* against a global key, so it counts replies per day, not
  distinct conversations. It was inert before and is only now observable. Read its breach
  counts as "replies per day", and fix the semantics before ever switching it to `enforce`.

- **Abuse / takedown reports now honour the IOC export policy.** The cluster abuse report is
  addressed to an external recipient — an issuing bank, an exchange, a registrar, a national
  financial-crime unit — yet it was assembled from the unfiltered cluster anchor set, the only
  outgoing path that skipped the policy every other export already applies. An indicator an
  analyst had marked a false positive could therefore be reported to a bank, and a financial
  indicator nobody had confirmed could be named on unverified evidence. Reports are now built
  from an export-filtered anchor query, so their indicator set is identical to the STIX export
  for the same cluster. A report whose every indicator is withheld is still produced, with an
  empty indicator list — that is a valid outcome, not an error.
  **Behaviour change worth noting**: because the policy withholds *unconfirmed financial*
  indicators, reports will carry fewer indicators than before until an analyst confirms them.
  The internal cluster review screen is deliberately unchanged and still shows every indicator,
  including rejected and withheld ones — reviewers must be able to see and revise their own
  verdicts.

- **The kill-switch metric no longer reports a halted pipeline as operational.** The admin
  toggle writes a runtime flag and the reply pipeline enforces on that same flag, but the
  monitoring surfaces resolved the kill switch from the deployment environment variable
  alone. An operator who halted the system through `POST /api/v1/admin/llm/killswitch` would
  therefore still see `kill_switch_active: false` on `GET /api/v1/monitoring/autonomy`, a
  `status` of `operational`, and `scambuster_kill_switch 0` on the Prometheus surface — while
  replies were genuinely halted. Both surfaces now resolve through the same reader the
  pipeline enforces with, so the reported state can no longer disagree with the enforced one.
  Enforcement itself was already correct and is unchanged; this was a monitoring blind spot,
  not a safety hole. The deployment-level environment fallback is preserved, including its
  existing behaviour of degrading to that fallback if the runtime store is unreachable — the
  metric endpoint therefore gains one guarded cache read per call and no new failure mode.

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
- **The taxonomy is now mapped to MITRE F3, and the references travel to consumers.**
  Twenty-two of the twenty-seven techniques carried no external reference at all, and
  `mitre-f3` was allowed by the schema while being used nowhere — a vocabulary that
  connected to nothing. All 27 were mapped one by one against **F3 v1.1**, read from the
  official STIX 2.1 bundle pinned by commit and SHA-256, with counts obtained by parsing
  the file rather than from a summary. Two traps are worth recording for whoever repeats
  this: **F3 v1.1 has 8 tactics, not the 7 of v1.0** (Defense Evasion was split into
  Stealth and Defense Impairment, so a mapping built from the older list is wrong from the
  start), and the repository publishes no releases or tags, with `f3-v1.json` and
  `f3-v1.1.json` identical on `main` — only a commit SHA identifies a version. Every quoted
  id is checked by script against the closed set of 123, which is what caught `T1566`,
  `T1566.001`, `T1566.002` and `T1656`: they exist in ATT&CK but **are not in F3**. Only
  `T1598` is in both. Outcome: 2 covered, 13 partially aligned, 12 with no F3 description
  we could match. **The 12 empty `external_refs` record that no match was found, not that
  F3 has a hole** — the corpus cannot establish the latter, and `docs/standards-track.md`
  says so. The 15 with a match carry the canonical `ctid.mitre.org` url, which matters
  beyond convenience: consumers key external references on the url first, so a reference
  without one would not merge with the same technique arriving from the official F3 bundle.
- **The backfill writes all 27 codes, and a test now stops the class of bug that made it
  necessary.** The original seed migration inserts with `ON CONFLICT (code) DO NOTHING`, so
  editing its constant updates nothing on a database that already ran it — while the STIX
  export reads the table, not the seed. Changing a mapping therefore needs a data migration,
  and CI could not see its absence, because fixtures build test databases from the seed and
  never from migrations: the whole suite stayed green while production served the old
  references. `testExternalRefsHaveABackfillMigration` closes that. The backfill writes
  every code including the empty ones — writing only the non-empty ones would leave a
  reference the mapping has since dropped in place forever, which is the same silent-drift
  bug one level down. `taxonomy-v1.1.json` is published beside `taxonomy-v1.0.json`, which
  is left untouched so anything that pinned it keeps working.
- **What this is not.** The evidence base does not support proposing techniques to a
  standards body, and the record says so plainly: 98.2% of TTP observations in the database
  are synthetic (`demo-seed`), real extraction has produced 6 observations over 6 techniques
  at one conversation each, and no technique reaches the five-conversation threshold that
  was written down before the measurement. This is a vocabulary alignment, not a claim about
  the world.

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

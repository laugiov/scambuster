# Reading the TTP screens

A field guide to the **TTP intelligence surfaces**: the TTP Explorer (`TTP Explorer`
in the sidebar), the per-TTP detail page, the review queue, and the TTP annotations
inside a conversation thread.

A **TTP** (tactic, technique, procedure) describes the **scammer's** observable
behaviour -- "creates urgency", "asks to switch channel", "requests a wire transfer" --
tagged on inbound messages against a **closed 27-entry taxonomy** across a six-phase
scam kill chain (hook, trust-building, payment-request, escalation, channel-switch,
exit). TTPs are kept strictly separate from *stimulus* categories, which describe what
**our persona** did; the analytical value is the crossing **stimulus → TTP → IOC**.

> **Honesty first.** The TTP module postdates the white-paper evaluation window, so no
> published metric applies to it. Extraction quality is auditable (random-sample CSV
> export for manual scoring) but not yet scored. On the public demo, observations are
> deterministic seeded approximations, not real model extractions -- see the
> [demo note](DEMO.md#how-it-works).

---

## 1. TTP Explorer (`/ttps`) -- four tabs

The Explorer is split into four tabs, each deep-linkable via the URL
(`/ttps?tab=taxonomy|analytics|playbooks|review`), so a specific view can be bookmarked
or shared. The two analysis-heavy tabs -- Analytics and Playbooks -- each carry a row of
secondary **sub-tabs**, themselves deep-linkable with a second `?view=` parameter scoped
to the active tab (an unknown or missing `?view=` simply falls back to the tab's first
sub-view, and the URL is left untouched). Only the active sub-view's panel loads, so a
heavy matrix is fetched on demand rather than all at once.

### Taxonomy (default)

The full closed taxonomy as a table: code, label, kill-chain phase, observation and
conversation counts, review backlog and last-seen date. Search covers code, label and
definition; phase pills filter by kill-chain phase; the observation, conversation,
review and last-seen columns sort. Counters
count **confirmed observations only** -- the amber *Review* column tallies unvalidated
extractions separately, and the *awaiting review* pill jumps straight to the review
queue. **Clicking a row opens the per-TTP detail page.** Zero-observation entries stay
visible (dimmed): the taxonomy is always shown complete.

### Analytics -- three sub-tabs (`?view=activity|persona|stimulus`)

- **Phase activity** (`activity`, the default) -- two charts, both over **confirmed**
  observations and both on **message time** (when the scammer wrote it, not when we
  processed it): *Observations by kill-chain phase* (where the collected corpus sits on
  the scam kill chain) and *Phase evolution (last 8 weeks)* (weekly stacked bars per
  phase, current week included, zero-filled server-side).
- **Persona × TTP** -- personas (rows) × TTP codes (columns). Each cell is the number of
  the persona's **conversations** that carried the tactic -- a per-conversation count, so
  a single chatty conversation cannot inflate a persona. Rows below the headline
  threshold (3 TTP-carrying conversations) are dimmed as *provisional* and never shaded:
  too thin to read as a pattern. Conversations with no persona are excluded from the grid
  and reported in a footnote; the persona set is capped (widest volume first) with an
  explicit truncation note. This is a first-party record of what our own personas
  encountered -- never a claim that a persona *causes* a tactic.
- **Stimulus × TTP** -- outbound stimulus types (rows) × TTP codes (columns): the crossing
  of what our persona did against the tactic tagged on the scammer's reply. **Population,
  stated honestly under the matrix:** the cells count only **revelation messages that
  carry both an enriched stimulus type and at least one confirmed TTP** -- a message with
  a stimulus but no confirmed TTP (or the reverse) sits in no cell, so this is *not* the
  full corpus; the exact population size *n* is displayed. The `UNKNOWN` stimulus row
  carries no signal, so it is collapsible (shown by default -- nothing is hidden silently)
  and sinks to the bottom. The wording stays temporal and non-causal, as everywhere else.

### Playbooks -- three sub-tabs (`?view=matrix|sequences|phases`)

- **Matrix** (the default) -- the **shared-playbook matrix**: confirmed observation counts
  per threat-actor cluster (rows) × TTP (columns). Overlapping columns across rows reveal
  clusters running the same playbook. Ordered widest playbook first and capped at the top
  50 clusters -- when the cap bites, an explicit note reports the full population
  (truncation is never silent). Two analyst controls sit on top:
  - a **normalization toggle** -- *raw counts* (the confirmed observation count, shaded by
    the busiest cell; the default, so existing readers are not surprised) or
    *per-conversation* (each cell as the share of the cluster's TTP-carrying conversations
    that exhibit the tactic, shown as a percentage) so a high-volume cluster cannot blow
    out the picture;
  - a **row-ordering** option -- *by size* (the backend's widest-first order) or *by
    playbook similarity* (clusters running similar playbooks are chained adjacent,
    computed client-side from the normalized rows), which makes a shared playbook jump out
    visually;
  - column headers now show the **abbreviated TTP label** with the code kept in mono
    beneath it, and the full code, label, phase and definition appear on hover.
- **Sequences** -- cross-message tactic **bigrams** rendered as the house ordered chips
  *"A → B"*, grouped either **by cluster** or **by scam type** (a toggle). Pairs are
  formed **only across message boundaries** -- same-message TTPs are an unordered
  co-occurrence set and never form a pair. **Support is the number of distinct
  conversations** a pair appears in, and a pair must recur in **at least 2 conversations**
  to show -- the honest "this is a shared playbook, not one talkative thread" sense of
  support. Each chip carries **both** its occurrence count and its conversation count
  (e.g. *A → B (×7 · 3 conversations)*); the minimum-support threshold is stated under the
  panel, and any groups beyond the cap are flagged.
- **Phase transitions** -- a **6 × 6 kill-chain phase matrix** (from-phase rows ×
  to-phase columns, in canonical kill-chain order) that aggregates the same cross-message
  bigrams by the kill-chain phase of each endpoint: how tactics move through the kill
  chain in aggregate. Cells are shaded by volume and the total bigram count is shown; zero
  cells render dimmed.

### Review queue

See section 3.

---

## 2. Per-TTP detail page (`/ttps/<code>`)

One page per taxonomy entry, with four tabs:

| Tab | What it shows |
|-----|---------------|
| **Overview** | Definition, kill-chain phase, usage counters (observations / conversations / awaiting review), first and last seen, the taxonomy's **example formulations**, and its **external references** (MITRE ATT&CK, linked when a URL exists). A never-observed entry renders honestly with zero counters. |
| **Co-occurring IOCs** | Indicators observed in the **same messages** as this TTP (confirmed observations only), with the distinct-message co-occurrence count and conversation span. |
| **Clusters** | Live threat-actor clusters whose conversations carry confirmed observations of this TTP (top 50, widest conversation span first, explicit truncation note). Rows link to the cluster detail page. |
| **Conversations** | Server-paginated (20 per page) list of conversations where the TTP was observed -- including **review-only** conversations, whose confirmed/review split is shown per row so triage candidates stay visible. Rows link to the conversation. |

---

## 3. Review queue -- read-only triage

Extractions whose confidence falls below the configured threshold are stored with
status `review` instead of being silently dropped. The review queue lists them, newest
message first, sortable by TTP, confidence, conversation, message date and provenance
(extraction model). The response is capped at the **500 most recent** items; the true
queue total is always displayed, so a bitten cap is visible.

> **Read-only in this version.** The queue is a triage and inspection surface: there is
> no confirm/reject action yet. Aggregates (cluster profiles, matrix, trends, exports)
> already exclude review-status rows, so an unvalidated extraction never distorts
> published intelligence.

**Expanding a row reconstructs the evidence on demand.** The API never returns the
stored verbatim evidence -- responses carry **character offsets only**. The UI fetches
the conversation's messages through the existing endpoints (normal access control
applies), locates the anchored message, and rebuilds the quoted span client-side from
the offsets, highlighted inside a short window of surrounding context.

- **Masked by default.** IOC values and the honeypot address are masked in the excerpt;
  *Reveal* is a deliberate toggle, scoped to this tab and never persisted.
- **Paraphrased state.** When the model returned no verbatim offsets, the row says so
  explicitly instead of displaying a fabricated quote. Offsets that cannot be located
  in the message (or that point into the subject line) are labelled just as honestly.

---

## 4. TTP annotations in the conversation thread

Inside a conversation (`Conversations → pick one`), the message bubbles carry the
TTP-level annotations:

- **TTP chips on inbound messages** -- the tactics tagged on that message, coloured by
  kill-chain phase, with confidence in the tooltip. **Dashed chips are awaiting
  review** (a legend appears in the thread header whenever any are present).
- **Stimulus chip on outbound messages** -- the stimulus type attributed to that reply
  by contextual IOC enrichment (e.g. *Direct request*, *Trust building*). The wording
  is deliberately neutral and temporal: it describes what the outbound message
  contained, never a causal claim that it *made* the scammer reveal anything.
- **"Preceded by" chip on inbound messages** -- when a revealed IOC carries a stimulus
  attribution, the chip links back to that outbound message (click to scroll and
  briefly highlight it). The linkage comes from enrichment data only; when no
  attribution exists (first contact, unenriched IOCs), no chip is shown -- there is no
  positional guessing.
- **Revealed-IOC chips** are restricted server-side to actionable IOC types, so
  transport metadata (SPF/DKIM results, header noise) never clutters the timeline.

---

## 5. TTPs in the Live Bait Theater

The **Live Bait Theater** (`Conversations → pick one → Theater`) replays a bait turn by
turn, and now overlays the scammer's confirmed tactics as the conversation unfolds:

- **Per-turn tactic chips** -- each revealed inbound message shows its confirmed TTP
  chips, coloured by kill-chain phase, with confidence in the tooltip. Only confirmed
  tactics appear here -- this showcase view does not surface review-status observations.
- **Verbatim-evidence highlight** -- the exact span each tactic was inferred from is
  highlighted in the message body. The evidence is reconstructed client-side from
  offsets only (the quote never crosses the API), and PII masking still applies to the
  highlighted text, so the mask banner's "safe to share" guarantee holds even over a
  highlight.
- **Kill-chain progress strip** -- above the intelligence panel, the six kill-chain
  phases (hook → exit) fill as the replay reveals a confirmed tactic in each, so you
  watch the chain the scammer walked build in real time.

Honesty notes: only confirmed tactics are shown; verbatim evidence stays internal
(offsets only over the wire); the highlight is exact on the normal (valid-UTF-8) corpus
and best-effort for the rare malformed body. On the keyless demo the TTPs -- **and the
confidence values in the chip tooltips** -- are deterministic seeded approximations
(`demo-seed`), not a live extractor's measured output, and no published precision metric
applies to the module.

---

## Where the same data goes

Confirmed observations feed the cluster TTP profile, the STIX 2.1 `attack-pattern` /
`threat-actor uses` export, TAXII and the MISP `scambuster:ttp` tags -- see the
[API quick reference](12_api_quick_reference.md#ttp-intelligence) for every endpoint.
Verbatim evidence stays strictly internal: it appears in no API response and no export;
the only path out of the database is the operator-run
`scambuster:ttp:audit-sample` CSV for internal precision audits.

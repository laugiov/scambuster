# Roadmap

> **Last updated**: 2026-08-13 · Status: post-hardening, pre-Black Hat USA 2026.
> ScamBuster is a working platform (multi-agent LLM scambaiting + real-time CTI).
> This page records what shipped and states, transparently, what is next -- and what
> is deliberately out of scope.

---

## Where it stands

The core research artefact (Phases 1–6) is complete: multi-agent LLM pipeline,
adaptive contextual bandit, 36 IOC types (hybrid regex + LLM), real-time threat-actor
clustering, and a STIX 2.1 / TAXII CTI surface. Since then, a large hardening and
intelligence cycle turned it into something a SOC / CERT / CTI analyst can actually
run and trust. The forward roadmap below is re-derived from what a practitioner would
expect next, not from the original research plan.

```
Research core   →   CTI + enterprise + OSS/prod   →   Multi-channel · resilience ·
✅ complete          hardening ✅ shipped                actionable artefacts · CTI ecosystem
```

---

## Phases 1–6 -- Research core ✅ COMPLETE

Condensed; each remains as delivered.

| Phase | Delivered |
|-------|-----------|
| **1 · Foundation** | Multi-agent LLM architecture (8 agents); hybrid IOC extraction (36 types); double validation (PolicyGuard + LLM Validator); Dockerized deployment; DDD backend; JWT RS256 (lexik) + refresh-token rotation. |
| **2 · Adaptive V1** | ε-greedy contextual bandit; 4-component reward; conversation/persona metrics schema; event-driven updates; isolated experimental validation. |
| **3 · Optimization + scale** | UCB1 exploration bonus; convergence detection; operational dashboards (Impact, Persona, LLM Cost, Monitoring). |
| **4 · A/B validation** | Random / best-fixed / adaptive arms; hypotheses H1–H4; methodology in [Evaluation Methodology](05_evaluation_methodology.md). Thompson Sampling deferred (ε-greedy + UCB1 is sufficient). |
| **5 · Publication & OSS** | Security-by-design controls; per-scam-type lifecycle policies; rate limiting; weekly cleanup; SIEM connector (CEF/ECS/JSON); OpenAPI; PII log masking; DPIA; MISP/ATT&CK mapping; contextual IOC enrichment; threat-actor STIX export. |
| **6 · CTI hardening** | Real-time threat-actor clustering (Union-Find on financial IOCs); clustered STIX export + TAXII `threat-actors` collection; Clusters/ClusterDetail frontend; behavioural profiles; attachment capture + SHA256 pivots; ATT&CK refresh. |

---

## Recently shipped ✅

The hardening and intelligence cycle since the research core -- grouped by theme.
All merged and running in production.

### Threat intelligence (the largest cycle)
- **Scammer TTP intelligence** -- inbound-only LLM tagging of scammer tactics against a closed 27-entry taxonomy across a six-phase scam kill chain; consolidated per-conversation and per-cluster read models with a TTP Explorer, cluster panel and stimulus → TTP → IOC timeline; STIX 2.1 attack-patterns (`kill_chain_phases`) + `threat-actor uses` + sightings and `scambuster:ttp` / ATT&CK MISP tags; backfill + random-sample audit CLI + deterministic demo seeding for the keyless demo. Verbatim evidence stays strictly internal (never exported); the module is feature-flagged (`TTP_EXTRACTION_ENABLED`) and postdates the white-paper window, so no published metric applies to it yet. The analyst surface has since been restructured into a tabbed Explorer (taxonomy / analytics / playbooks / read-only review queue) with per-TTP detail pages and offsets-only evidence reconstruction, then extended with sub-tabbed analytics (persona × TTP and revelation-scoped stimulus × TTP matrices), cross-message tactic sequences and a kill-chain phase-transition matrix, and a tabbed Cluster Detail page, and surfaced in the Live Bait Theater replay (per-turn tactic chips, offsets-only evidence highlight and a kill-chain progress strip) -- still post-white-paper, so no published metric applies yet; see [Reading the TTP screens](26_reading_the_ttp_screens.md).
- **Threat-actor psychological profiling** with Cialdini-lever signals, surfaced on the actor screen.
- **STIX 2.1 conformity**: sightings + observed-data SDOs; standards-compliant custom-extension keying (ext-def UUIDs); Cognitive-Mirror Note SDO; enriched `x_scambuster_context` fields; build-time JSON-schema validation.
- **Analyst feedback loop → IOC confidence**: confirm / false-positive verdicts now *authoritatively drive* `observed_ioc.confidence_score` and propagate into TAXII, MISP and CSV/NDJSON feeds.
- **Fuzzy / simhash clustering** for near-duplicate and rotated-wallet matching.
- **TLP + PAP markings** with per-IOC TLP inheritance; TLP:RED campaigns excluded from shared collections.
- **Intelligence feed exports**: TAXII cursor pagination + CSV / NDJSON IOC feeds; multi-label taxonomy; temporal analysis; abuse-report drafts.
- **Clusters UI redesign**: dedup hero card, freshness sorting, anchor styling, heat-map + temporal + abuse panels, auto-generated takedown report.

### Enterprise / compliance
- **OIDC / SSO** authenticator (Keycloak / Azure AD / Okta / Google), opt-in, off by default.
- **Audit HMAC fail-closed**; metrics/health endpoint auth; security-monitoring dashboard + alert rules.
- **Refresh-token hardening**: SHA-256 at rest, rotated-token reuse detection with family revoke, audit events.
- **GDPR / compliance pack**: DPA reference, Art. 30 register, Art. 33 breach procedure, risk register / RACI / PSSI, NIST IRP + tabletop, data-classification schema.
- **CORS allowlist** hardening on the authenticated API (env-driven).
- **Retention made measurable and honest**: the weekly cleanup job now reports how many conversations and messages are eligible for permanent erasure on every run, while erasing nothing without an explicit flag; the Art. 30 record was corrected on three counts (real 90-day soft-delete threshold, the job actually responsible, and the fact that erasure had never run).
- **Cost/abuse ceilings restored on the automatic flow**: waiving the reply spacing no longer waives the three Redis ceilings, which now run in an observation mode (`warning`, the default -- nothing is refused) so real breach volumes can be measured before enforcement is considered. Operators keep an explicit full override.
- **Export policy applied to abuse reports**: takedown reports sent to banks, exchanges and national units are now filtered by the same policy as STIX and TAXII, so an analyst-rejected or unconfirmed-financial indicator can no longer be named to an external recipient. The internal review screen still shows everything.
- **Kill-switch reporting made truthful**: the autonomy and Prometheus surfaces now resolve the kill switch through the same reader the reply pipeline enforces with, so a pipeline halted through the admin toggle can no longer report itself as `operational`. Enforcement was already correct -- this closed a monitoring blind spot found by a production audit.

### Reply quality / prompt engineering
- Out-of-band-channel blocking; payment-instigation guard (multilingual LLM judge); careful-buyer pushback; scam-type-aware objectives; Cialdini-lever mirroring; anti-repetition; reply role-coherence checks; retry-feedback loop; and a fallback-rate remediation pass.

### Operator configuration (config-over-code)
- **Operator-configurable prompts** -- generative prompts can be tailored per deployment from a **Prompt Customization** admin UI or from git-tracked files: IOC-context enrichment, outcome-scoring rubric, the **persona voice/style rules that shape every reply**, and the **Conversation Director's strategy & tone** (how it infers each scam's goal, varies reply shape, and picks a tone). Every reply-path prompt is regression-gated with a "Validate this prompt" verdict before activation. Resolution is database → file → shipped-default and fail-safe; the deterministic safety guards, the JSON output contract and the safety rule-set remain untouchable by any override. Rolling out prompt-by-prompt. See [Prompt Customization](25_prompt_customization.md).

### Data quality / IOC
- `postal_address` IOC type (full stack: extract → normalize → validate → MISP → STIX SCO); honest impact tiles ("Fresh IOCs", "Scammer Replies Elicited"); unified conversation counts; soft-delete filtering; risk-endpoint extraction wait; per-actor engagement-time rollups.

### Architecture / DDD
- Repository-interface ports (Campaign / PersonaStats / SMTP / AuditLogger); framework-agnostic handler inputs; Reflection removed in favour of domain methods; single-action controllers; `scoreRisk` invariant at construction.

### OSS publication + production
- **OSS readiness** (MIT): full-history secret scan, honeypot-identifier removal, English-only sweep, <30-min installability, out-of-the-box mailbox registration.
- **Fresh-install validation** + AI-agent deployment runbook.
- **Production single-image path**: self-contained php-fpm + nginx image, `docker-compose.prod.yml`, auto-migration entrypoint, tested runbook.
- **User-management CLI** with audit logging; **CVE remediation** + blocking Trivy gate across dev / prod / demo images.

### Live Bait Theater / demo
- Animated conversation-replay demo tool with synced intelligence + human-factor panels; forensic-grade BEC case-study seed for external demos.

---

## Now / Next -- forward roadmap

Re-derived from the practitioner lens (what a SOC / CERT / CTI analyst expects next).
Every item below is verified **not yet built**. Intent, not promises.

### ★ Flagship direction -- Multi-channel expansion

Today ScamBuster is an **email** honeypot. Scam operations are not: they run over
**SMS (smishing), voice (vishing), and social / instant-messaging platforms**
(WhatsApp, Telegram, Instagram, Facebook Marketplace, dating apps). The single most
impactful expansion is to make the platform **omnichannel** -- the same multi-agent
engine, adaptive personas, and CTI pipeline, fed by new channel adapters:

- **SMS / smishing** -- inbound + outbound via an SMS gateway; the richest near-term
  win (high volume, actionable sender/shortcode IOCs, low integration cost).
- **Voice / vishing** -- speech-to-text on inbound calls, LLM reply, text-to-speech
  outbound; captures phone-based fraud that email never sees.
- **Social & instant messaging** -- connectors for the platforms where romance,
  marketplace, and investment scams actually operate.

The architecture already separates channel from logic (the `Channel` abstraction and
recipient-resolution layer exist), so channels plug in without touching the core
engine. This is the direction that turns a research artefact into a
cross-channel anti-fraud platform.

### Tier 1 -- Resilience & actionable artefacts

| Item | Why it matters | State |
|------|----------------|-------|
| **Retire n8n from the IMAP hot path** (native Symfony Messenger consumer) | A SOC will not trust a low-code tool on the critical ingestion path. n8n stays for enrichment / STIX / notifications; mail intake becomes native PHP IMAP + Messenger (doctrine/redis backend already deployed). | Not started -- intake still in n8n. |
| **Vision OCR for attachments** | Fake invoices, IDs and bank receipts are the most actionable artefacts. Extract IBAN / BIC / phone / names / addresses from images + PDFs via a vision model, into the existing IOC catalogue. | Not started -- attachment *capture* + SHA256 pivots already shipped; extraction is the gap. |
| **Attachment sandbox detonation** | Malware attachments → network IOCs (C2 domains, dropped-file hashes, persistence keys). Async submission to a SaaS sandbox (Triage / any.run / VT Enterprise) via webhook -- no infra to run. | Not started. |

### Tier 2 -- Analyst experience & CTI ecosystem

| Item | Why it matters | State |
|------|----------------|-------|
| **Interactive actor ↔ IOC ↔ conversation graph** | Analysts pivot visually. Upgrade the current thin SVG IOC view into a real graph (Cytoscape / Sigma) over the existing clustering tables via PostgreSQL recursive CTEs -- no new datastore. | Partial -- a basic IOC↔IOC view exists; no actor/conversation nodes, no graph engine. |
| **True STIX / TAXII federation** | Today the platform *publishes* one-way (full TAXII 2.1 server). Practitioners expect to also *consume*: inbound feed ingestion, subscriptions, and a MISP round-trip for shared attribution. | Partial -- publish-only. |
| **ARF-conformant abuse reports + dispatch workflow** | The draft generator (HITL, no auto-send) is shipped; the remaining gap is RFC 5965/6471 ARF MIME output and an opt-in, audited dispatch step for HIGH-confidence clusters. | Partial -- structured draft shipped; standards format + dispatch pending. |

### Tier 3 -- Active defense & research contributions (novel; publishable)

| Item | Why it matters | State |
|------|----------------|-------|
| **Ground-truth contradiction injection** | Confront scammers in-conversation with verified evidence ("you say London, but your IP resolves to Lagos") by feeding GeoIP / VirusTotal / URLScan / WHOIS signals into the prompt builder. The enrichment data is already ingested for risk scoring -- this routes it into generation. | Not started -- data ingested, never reaches the prompt. |
| **Adversarial "the scammer noticed the AI" classifier** | A retrospective classifier over the corpus that identifies the moment a scammer realises they're talking to a bot. Real-time bot-accusation guards already exist; the dataset-level classifier (a second paper) does not. | Partial -- guardrail signals only. |

### Ongoing

| Item | Note |
|------|------|
| **Emerging scam-type coverage** | The threat landscape shifts; new classifiers are added to de-`UNKNOWN` recent inflow (e.g. cold-service / SEO spam, in progress). |
| **Prompt-change regression gate** | A gate that scores a candidate prompt (real-LLM canary over a fixed scenario set) against a frozen baseline and flags any safety/behaviour regression, so operators can validate a customization before it ships -- deterministic verdict, code-owned tolerance. It is precision-tuned to flag only a concrete leak the persona *gives out* (not the honeypot's own job of eliciting the scammer's details), so verdicts stay high-signal. Landed: the CLI/CI gate + opt-in git hook, and an operator **"Validate this prompt"** button (async, verdict shown before activation) served by an always-on worker. See [Prompt Customization](25_prompt_customization.md#validating-a-prompt-change-regression-gate). |
| **Honeypot identity health & rotation** | Detect degradation (response-rate drop, blocklist appearance, MX patterns) and trigger an operator rotation workflow. Not started. |

---

## Done or discarded (transparency)

| Old plan item | Verdict | Detail |
|---------------|---------|--------|
| **Abuse-report generator (HITL, no auto-send)** | ✅ **Shipped** | Draft + panel delivered. Only the ARF format + dispatch remain (see Tier 2). |
| **Multi-tenancy schema prep** | ❌ **Reversed** | The `tenant_id` column was *dropped* as cosmetic, not added. Full multi-tenancy stays deferred; there is no partial schema to preserve. |
| **Thompson Sampling** | Deferred | ε-greedy + UCB1 is a viable production algorithm; marginal gain unjustified pre-defense. |
| **Neo4j migration** | Skipped | PostgreSQL recursive CTEs cover traversal at current scale without a second datastore. |

---

## Out of scope (deliberate -- ethical / legal)

| Item | Reason |
|------|--------|
| **Auto-send abuse reports** | Requires a legal framework, ToS and CERT-equivalent statute. Reports stay draft + human-in-the-loop. |
| **Full multi-tenancy** | Deferred until commercial intent is committed. |
| **Synthetic document generation (fake receipts/IDs)** | French Code pénal Art. 441-1 (faux et usage de faux), trademark exposure, cross-victim weaponisation risk. Requires ethics-committee and legal review before any prototype. |

---

## Long-term vision

Aspirational directions beyond the flagship multi-channel work, gated on data volume,
research time, or partnerships.

| Direction | Prerequisite |
|-----------|--------------|
| **LinUCB** (contextual bandit features: time, language, country) | Larger conversation corpus |
| **Meta-learning** (transfer between scam types) | Large dataset |
| **In-conversation real-time adaptation** (switch persona mid-thread) | Research |
| **Thompson Sampling** | Research time post-publication |

### Research collaborations

Universities (joint research, dataset sharing) · CERTs (operational validation) ·
Law enforcement (campaign attribution support) · Industry (pilots, SIEM integration).

---

## Success criteria (qualitative)

| Criterion | How it's judged |
|-----------|-----------------|
| **Channel reach** | Coverage beyond email (SMS, voice, social) |
| **Resilience** | n8n off the critical ingestion path; monitored uptime |
| **Actionability** | Share of IOCs sourced from attachments (OCR + sandbox) |
| **Analyst adoption** | Graph-driven pivoting used in real triage |
| **Interoperability** | Bidirectional STIX/TAXII + MISP round-trip |
| **Reproducibility** | Independent validation on the released dataset + code |

---

## Risk factors

| Risk | Likelihood | Impact | Contingency |
|------|------------|--------|-------------|
| **n8n fragility on the intake hot path** | Medium | High | Tier-1 migrates IMAP intake to native Symfony Messenger. |
| **Honeypot identity burn** | Medium | Medium | Planned health scoring + rotation workflow. |
| **LLM cost growth at scale** | Low | Medium | Redis-cached enrichment, lightweight model defaults, monthly budget cap. |
| **Legal exposure on abuse reports** | Low | High | Draft + HITL only; dispatch gated behind a legal framework. |
| **Vision / sandbox API cost** | Low | Low | Per-item cost negligible at current volume; orchestrator budget cap. |

---

## Get involved

Research partnerships · open-source contributions · pilot programs · advisory.
[Contact us](../README.md#contact) to discuss.

---

[← Back to Main](../README.md)

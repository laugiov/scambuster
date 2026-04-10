# Roadmap

> **Last updated**: 2026-04-10
> **Current production version**: v2.14.0

## Project Timeline Overview

```
Oct 2025   Dec 2025   Jan 2026   Feb 2026   Mar 2026   Apr 2026   May-Jul 2026
   │          │          │          │          │          │             │
   ▼          ▼          ▼          ▼          ▼          ▼             ▼
┌──────┐  ┌──────┐  ┌──────┐  ┌──────┐  ┌──────┐  ┌──────┐  ┌────────────┐
│Phase │  │Phase │  │Phase │  │Phase │  │Phase │  │Phase │  │   Phase    │
│  1   │─▶│ 2/3  │─▶│  4   │─▶│  5   │─▶│ 5.x  │─▶│  6   │─▶│     7      │
│      │  │      │  │      │  │      │  │      │  │      │  │            │
│Found-│  │Adapt │  │ A/B  │  │Publi-│  │Hard- │  │ CTI  │  │ Production │
│ation │  │ +    │  │valid │  │cation│  │ening │  │Hard- │  │  Maturity  │
│      │  │Scale │  │ation │  │      │  │Pass  │  │ening │  │            │
└──────┘  └──────┘  └──────┘  └──────┘  └──────┘  └──────┘  └────────────┘
   ✅        ✅        ✅        ✅        ✅        ✅            🔄
```

---

## Phase 1: Foundation (October - December 2025) ✅ COMPLETE

### Objectives
Build the core platform with multi-agent LLM architecture and production-grade infrastructure.

### Deliverables

| Deliverable | Status | Details |
|-------------|--------|---------|
| Multi-agent LLM architecture | ✅ | 5 specialized agents |
| Hybrid IOC extraction | ✅ | High precision on audited samples, 34 types |
| Double validation pipeline | ✅ | 100% first-attempt approval |
| Production deployment | ✅ | Continuous operation, 0 incidents |
| Metrics collection | ✅ | Multiple unique IOCs/conv, high precision |
| DDD backend architecture | ✅ | Comprehensive automated test suite |
| JWT authentication | ✅ | HS256 (lexik/jwt-authentication-bundle), refresh token rotation |

### Key Achievements

| Metric | Value |
|--------|-------|
| **Unique IOCs per conversation** | Multiple unique IOCs per conversation (deduplicated) |
| **IOC Precision** | High precision on audited samples |
| **Persona variance** | 5.5x best vs worst |
| **Cost per IOC** | Low cost per IOC (with lightweight models) |
| **System uptime** | 100% (continuous operation) |

---

## Phase 2: Adaptive Scambaiting V1 (December 2025) ✅ COMPLETE

### Objectives
Implement ε-greedy contextual bandit for automatic persona optimization.

### Deliverables

| Deliverable | Status | Details |
|-------------|--------|---------|
| Database schema extension | ✅ | conversation metrics + persona stats |
| ε-greedy algorithm | ✅ | 80% exploitation, 20% exploration |
| Reward function | ✅ | 4-component weighted formula |
| REST API endpoints | ✅ | 5 endpoints operational |
| Event-driven updates | ✅ | ConversationEndedEvent + Listener |
| Experimental validation | ✅ | 2,221 conversations |
| Statistical analysis | ✅ | p < 0.001, Cohen's d = 0.37 |

### Key Achievements

| Metric | Value |
|--------|-------|
| **Validation conversations** | 2,221 |
| **Convergence CV** | 0.1703 |
| **Statistical significance** | p < 0.001 |
| **Documentation** | 7 major documents |
| **Delivery** | 10 days ahead of schedule |

---

## Phase 3: Adaptive Optimization + Scale (December 2025) ✅ COMPLETE

### Objectives
1. Optimize epsilon-greedy with UCB1 exploration bonus and convergence detection
2. Scale scam volume 4x (4.8 → 20 conversations/day)
3. Deploy operational dashboards

### Week 1 (Dec 1-7): Production Deployment + Adaptive Enhancements

| Task | Priority | Status |
|------|----------|--------|
| Production database migration | P0 | ✅ |
| UCB1 exploration bonus | P0 | ✅ |
| Convergence detection (60% threshold) | P0 | ✅ |
| Unit tests (15+) | P0 | ✅ |
| Integration tests | P0 | ✅ |
| Documentation | P0 | ✅ |

### Week 2 (Dec 8-14): Dashboards + Activation

| Task | Priority | Status |
|------|----------|--------|
| Grafana installation | P0 | ✅ |
| Operational dashboard (6 panels) | P0 | ✅ |
| Adaptive dashboard (6 panels) | P0 | ✅ |
| Threat Intelligence dashboard (6 panels) | P0 | ✅ |
| Prompt injection detection (two-layer forensic) | P0 | ✅ |
| Intermediate report | P1 | ✅ |

### Week 3 (Dec 15-21): Scale Volume

| Task | Priority | Status |
|------|----------|--------|
| Add 6 new scraping sources | P0 | ✅ |
| Increase frequency (1×/day → 3×/day) | P0 | ✅ |
| Create 3 new Gmail honeypots | P0 | ✅ |
| Active honeypots (classified ads) | P0 | ✅ |
| Deduplication service | P0 | ✅ |
| Anti-scam partnerships outreach | P1 | ✅ |

### Week 4 (Dec 22-31): Engagement Optimization

| Task | Priority | Status |
|------|----------|--------|
| Human-like response delays (1-6h) | P0 | ✅ |
| Micro-error injection (typos) | P0 | ✅ |
| Prompt improvements | P0 | ✅ |
| A/B testing configuration | P0 | ✅ |
| December report | P0 | ✅ |
| Presentation preparation | P1 | ✅ |

### Pilot Achievements (February 2026)

| Metric | Achieved | Target |
|--------|----------|--------|
| **Unique IOCs/conversation** | Multiple unique IOCs per conversation | >5 |
| **IOC Precision** | High precision on audited samples | >95% |
| **Persona variance** | 5.5x | Measured |
| **Scammer response rate** | 54% | >40% |
| **Cost per IOC** | Low cost (with lightweight models) | <EUR 0.001 |
| **System uptime** | 60+ days continuous operation | 30 days |

---

## Phase 4: A/B Testing & Validation (January 2026) ✅ COMPLETE

### Objectives
Scientifically validate adaptive strategy selection and collect publication-ready data.

### Experimental Groups

| Group | Strategy | Size |
|-------|----------|------|
| **A (Control)** | Random persona selection | 500 conversations |
| **B (Best-fixed)** | Best global persona (fixed) | 500 conversations |
| **C (Adaptive)** | Epsilon-greedy + UCB1 bandit | 500 conversations |

### Validated Hypotheses

| ID | Hypothesis | Result |
|----|------------|--------|
| H1 | Adaptive improves engagement duration vs random | Validated (p < 0.001) |
| H2 | Adaptive increases IOCs/conversation vs random | Validated (+51.3% median) |
| H3 | Adaptive reduces early abandonment | Validated (48.6% → 36.4%) |
| H4 | Per-scam-type policy converges in <100 sessions | Validated (9/13 types) |

### Thompson Sampling — deferred

Thompson Sampling was originally planned for this phase but was deferred. The current ε-greedy + UCB1 algorithm already demonstrates statistically significant improvements over random selection (p < 0.001, Cohen's d = 0.37), making it a viable production algorithm and a sufficient contribution for the academic deliverable. Thompson Sampling remains available as a future research direction.

---

## Phase 5: Publication & Open Source (February - March 2026) ✅ COMPLETE

### Objectives
Publish research findings, harden platform for production, and release anonymized dataset.

### Production Hardening (March 2026) ✅

| Deliverable | Status | Details |
|-------------|--------|---------|
| Security by Design (OWASP headers, audit trail, RBAC) | ✅ | 9 security controls implemented |
| Per-scam-type lifecycle policies (13 types) | ✅ | Timeout, max turns, max duration, reopen window |
| Sender rate limiting + flood detection | ✅ | 10/day cap, 5/5min burst quarantine, audit events |
| Bandit convergence logging + daily report | ✅ | `bandit_convergence_log` table, CLI command |
| Weekly cleanup (soft-delete + LLM purge) | ✅ | 90-day conversations, 180-day LLM usage |
| Conversation Monitoring frontend page | ✅ | Lifecycle KPIs, timeout alerts, by-scam-type |
| LLM Cost Monitor page | ✅ | Monthly budget, per-purpose breakdown, daily trend |
| Dynamic LLM provider in Settings | ✅ | Reads from backend config |
| Human delay simulation in n8n | ✅ | Log-normal distribution, per-scam-type profiles |
| Multi-email processing in n8n | ✅ | Loop Over Items + Merge Email Data pattern |
| Full i18n EN/FR | ✅ | All pages and components |

### Quality & Enterprise Integration Pass (March 2026) ✅

| Deliverable | Status | Details |
|-------------|--------|---------|
| OpenAPI 3.0 annotations (MT-3) | ✅ | 100% API coverage, Swagger UI at /api/doc |
| PHPStan 100% coverage (MT-6) | ✅ | Removed excludePaths, full codebase analyzed |
| IOC Confidence Scoring (MT-10) | ✅ | Temporal decay, confidence 0.0-1.0, frontend updated |
| SIEM Connector (MT-7) | ✅ | CEF/ECS/JSON, 3 adapters, 16 event types mapped |
| CI Pipeline restored (CT-1) | ✅ | Unit + integration tests in GitHub Actions |
| PII masking in logs (CT-9) | ✅ | Monolog processor, zero PII in log files |
| DPIA v1.1 (CT-8) | ✅ | GDPR Article 35 compliance documentation |
| PostgreSQL backup (CT-10) | ✅ | Automated daily backup via scheduler |
| MISP/ATT&CK mapping (CT-4) | ✅ | 13/13 scam types mapped |
| Community files (CT-5/6/7) | ✅ | CODE_OF_CONDUCT, Release v1.0.0, Discussions |

### Initial Threat Intelligence Features ✅

| Deliverable | Status | Details |
|-------------|--------|---------|
| Contextual IOC enrichment (Spec 043) | ✅ | ioc_context table, LLM semantic enrichment, STIX/TAXII context extensions |
| Threat-actor STIX export (Spec 044) | ✅ | ThreatActorStixBuilder, conversation-level STIX bundles, TAXII integration |

### Paper Outline

1. Introduction & Motivation
2. Related Work (Cognitive Honeypots, LLM Scambaiting)
3. System Architecture (Multi-Agent, Adaptive, Real-time Threat Actor Clustering)
4. Methodology (Bandit Formulation, Reward Design, Union-Find Clustering on Financial IOCs)
5. Experimental Results (A/B Testing, Cluster Quality)
6. Discussion (Limitations, Future Work)
7. Conclusion

### Dataset Contents

| Component | Size | Format |
|-----------|------|--------|
| **Conversations** | 1,000+ | JSON (anonymized) |
| **Messages** | 8,000+ | JSON (anonymized) |
| **IOCs** | 20,000+ | CSV (hashed where sensitive) |
| **Metadata** | N/A | JSON (scam types, personas, rewards, clusters) |

---

## Phase 6: CTI Hardening Cycle (April 2026) ✅ COMPLETE

### Objectives
Harden the threat intelligence pipeline end-to-end based on three independent CTI expert audits, deliver real-time threat actor clustering, and close production-grade gaps in the IOC pipeline and mail intake.

### Delivered specs

| Spec | Deliverable | Version | Status |
|------|-------------|---------|--------|
| **050** | Consolidation protocol — 8 quality gates per commit, atomic commits, audit reviews | v2.5 | ✅ pass 1 |
| **058a** | Real-time threat actor clustering — Union-Find on financial IOCs, 3 new tables, scheduler 30min backfill | v2.6 | ✅ |
| **058b** | Clustered threat-actor STIX export + TAXII 3rd collection (`threat-actors`) | v2.7 | ✅ |
| **058c** | Frontend Clusters + ClusterDetail pages, useClusters / useClusterStats hooks | v2.8 | ✅ |
| **059** | Cluster behavioral profile aggregation + campaign excerpts | v2.9 | ✅ |
| **060** | STIX export hardening — O(n²) mesh removal, cluster attribution for conversations | v2.10 | ✅ |
| **061** | IOC extraction direction guard + honeypot identifier filter (defense in depth) | v2.11 | ✅ |
| **062** | MITRE ATT&CK mapping refresh — alignment with current matrix (T1656 added) | v2.12 | ✅ |
| **063** | End-to-end attachment capture in IMAP intake pipeline (4-step delivery) | v2.13 | ✅ |
| **064** | Attachment SHA256 → observed_ioc linkage with cross-mail pivot capability | v2.14 | ✅ |

### Key Achievements

| Metric | Value |
|--------|-------|
| **Specs delivered in cycle** | 10 (050, 058a/b/c, 059-064) |
| **New backend tests** | 200+ (TDD strict per spec) |
| **Test suite size** | 1,473 unit + 41 EndToEnd ingest |
| **Quality gates per commit** | 8 (PHPStan L6, CS-Fixer, audit grep, unit, integration, smoke, manual review, atomic commit) |
| **Production version at cycle close** | v2.14.0 |
| **Threat actor clusters in production** | 5 active (33→5 deduplication, 84.8% TAXII feed reduction) |

---

## Phase 7: Production Maturity (May - July 2026) 🔄 PLANNED

### Objectives
Transform ScamBuster from an excellent research artefact into a production-grade tool that an analyst, SOC, CERT or MSSP would consider indispensable. Priorities driven by both academic publication needs and CTO-level expert review of operational gaps identified during Phase 6.

### Tier 1 — Pre-defense priorities

| ID | Feature | Effort | Goal |
|----|---------|--------|------|
| **7.1** | **Active Defense — CTI feedback loop in prompts** | 1-2 sprints | Inject real-time GeoIP / VirusTotal / Shodan / WHOIS contradictions into PromptBuilder so personas can confront scammer claims with verified ground truth ("you say you're in London but your IP resolves to Lagos"). Builds on the existing ContextualEnricher (spec 043) and the 3-attempt orchestrator loop. Publishable as a research contribution. |
| **7.2** | **Vision OCR for attachments** | 1 sprint | Extract IOCs (IBAN, BIC, phone, names, addresses) from images and PDFs via gpt-4o-mini vision. Builds directly on the spec 064 attachment foundation. Targets the most actionable scam artefacts (fake invoices, fake IDs, fake bank receipts). |
| **7.3** | **Adversarial detection — "scammer noticed the LLM"** | 1-2 sprints | Retrospective classifier on the existing 1,000+ conversation dataset that identifies the conversational moment when a scammer realises they are talking to an AI. Open research question with no published baseline; contributes a second standalone paper. |
| **7.4** | **Honeypot identity health & rotation** | 1 sprint | Detect honeypot degradation signals (sudden drop in scammer response rate, blocklist appearance, MX query patterns) and trigger an operator rotation workflow. |

### Tier 2 — Architecture & operational hardening

| ID | Feature | Effort | Goal |
|----|---------|--------|------|
| **7.5** | **Symfony Messenger consumer for IMAP hot path** | 3 sprints | Retire n8n from the critical mail ingestion path. n8n stays for IOC enrichment, STIX exports, notifications and external integrations. Closes the resilience class exposed by spec 063 (low-code fragility on the hot path). Native PHP IMAP transport, doctrine/redis Messenger backend (already deployed) — no new infrastructure. |
| **7.6** | **Sandbox detonation pipeline** | 1 sprint | Submit suspicious attachments to a SaaS sandbox API (Hatching Triage, any.run, VirusTotal Enterprise). Ingest network IOCs (C2 domains, dropped file hashes, persistence keys) back into the indicator catalogue. Async webhook integration, no infrastructure to maintain. |
| **7.7** | **Graph visualization frontend** | 1 sprint | Interactive actor ↔ IOC ↔ conversation graph rendered via Cytoscape.js (or Sigma.js) on top of PostgreSQL recursive CTEs over the existing clustering tables. No new datastore — leverages the spec 058 clustering schema. |
| **7.8** | **Multi-tenancy schema preparation** | 1-2 sprints | Add `tenant_id` column + composite indexes on all root entities, Doctrine automatic filter, PostgreSQL row-level security policies, scoped RBAC bind, `TenantContext` service. Defers full feature delivery while preserving the option for a future MSSP / CERT partner deployment. Avoids the worst-case retrofit of tenant isolation post-launch. |

### Tier 3 — Action & integration

| ID | Feature | Effort | Goal |
|----|---------|--------|------|
| **7.9** | **Abuse report draft generator with HITL validation** | 1-2 sprints | Auto-draft RFC 5965 / RFC 6471 ARF abuse reports for HIGH-confidence indicator clusters (recurring IBAN, identified phishing domains). Reviewer dispatches via opt-in workflow. No auto-send. Audit log immutable per dispatch. |

### Out of scope for Phase 7

| Item | Reason |
|------|--------|
| **Auto-send abuse reports** | Deferred to a v3 commercial release. Requires legal framework, ToS, operator opt-in agreements, and a CERT-equivalent statute. |
| **Full multi-tenancy** | Deferred until commercial intent is committed. Only the schema preparation is in scope for Phase 7. |
| **Synthetic document generation (fake bank receipts)** | Deferred indefinitely. French Code pénal Art. 441-1 (faux et usage de faux), trademark exposure on real bank logos, and risk of cross-victim weaponisation make this a feature that requires ethics committee review and legal counsel before any prototype work. |
| **Thompson Sampling** | Deferred. ε-greedy + UCB1 already produces statistically significant results (p < 0.001, Cohen's d = 0.37). The marginal gain does not justify the implementation cost in the pre-defense window. |
| **Neo4j migration** | Skipped. PostgreSQL recursive CTEs over the existing clustering tables provide equivalent traversal capability at the project's current scale (1,000+ conversations) without the operational burden of a second datastore. |

### Phase 7 success criteria

| Criterion | Target |
|-----------|--------|
| **Active defense impact on engagement** | Measurable lift on `avg_turns_per_conversation` for the Tier-1 cohort vs control |
| **Vision OCR IOC contribution** | At least 10% of new IOCs sourced from image/PDF extraction within 30 days of deployment |
| **n8n on critical path** | Zero — IMAP intake fully migrated to Symfony Messenger |
| **Sandbox-sourced IOCs** | Operational coverage on every persisted attachment with `mime_type` matching the sandbox eligibility list |
| **Multi-tenancy retrofit cost** | Zero new migrations needed when (if) the full feature is delivered later |

---

## Long-Term Vision (2026+)

### Potential Extensions

| Direction | Description | Prerequisite |
|-----------|-------------|--------------|
| **LinUCB** | Contextual features (time, language, country) for the bandit | 10k+ conversations |
| **Multi-channel** | SMS, voice, social media | Partnership |
| **Meta-learning** | Transfer between scam types | Large dataset |
| **Real-time adaptation** | Adapt persona during the conversation | Research |
| **Federation** | Multiple organizations sharing learnings via STIX/TAXII | Legal framework |
| **Thompson Sampling** | Bayesian exploration/exploitation | Research time post-publication |

### Commercial Opportunities

| Opportunity | Model | Status |
|-------------|-------|--------|
| **SOC-as-a-Service** | Subscription | Exploring |
| **IOC Feed** | Per-IOC or subscription | Possible |
| **SIEM/SOAR integration** | Plugin marketplace | TBD |
| **MSSP white-label** | Per-tenant licence | TBD — gated by Phase 7.8 schema prep |
| **Consulting** | Implementation support | Available |

### Research Collaborations

| Partner Type | Potential Collaboration |
|--------------|------------------------|
| **Universities** | Joint research, student projects, dataset sharing |
| **CERTs** | Operational data sharing, validation |
| **Law enforcement** | Campaign attribution support |
| **Industry** | Pilot programs, SIEM integration |

---

## Success Criteria

### Technical Success

| Criterion | Target | Measurement |
|-----------|--------|-------------|
| **System reliability** | >99% uptime | Monitoring |
| **IOC precision** | >98% | Manual validation |
| **Convergence speed** | <100 sessions | CV analysis |
| **Cost efficiency** | <€0.01/conversation | Tracking |
| **n8n on critical path** | Zero by end of Phase 7 | Architecture audit |

### Research Success

| Criterion | Target | Measurement |
|-----------|--------|-------------|
| **Statistical significance** | p < 0.05 for all hypotheses | Tests |
| **Effect sizes** | Cohen's d > 0.3 | Calculation |
| **Reproducibility** | Independent validation | Dataset + code |
| **Publication** | Accepted at Tier-1 venue | Review outcome |

### Impact Success

| Criterion | Target | Measurement |
|-----------|--------|-------------|
| **IOCs shared** | 20,000+ unique (achieved) | Export count |
| **Threat actor clusters** | Operational | Cluster table |
| **Partnerships** | 2+ organizations | Agreements |
| **Citations** | N/A (future) | Scholar tracking |
| **Industry adoption** | 1+ pilot | Engagement |

---

## Risk Factors & Contingencies

| Risk | Likelihood | Impact | Contingency |
|------|------------|--------|-------------|
| **n8n fragility on critical path** | Medium | High | Phase 7.5 retires n8n from the IMAP hot path. Spec 063 documented one occurrence; preventive refactor in progress. |
| **Honeypot identity burn** | Medium | Medium | Phase 7.4 introduces health scoring and rotation workflow. |
| **LLM cost growth at scale** | Low | Medium | Aggressive Redis caching on enrichment lookups, lightweight model defaults, monthly budget tracking already in place. |
| **Legal exposure on abuse reports** | Low | High | Phase 7.9 stays at draft + HITL validation only. Auto-send gated behind legal framework. |
| **Multi-tenant data leakage if commercial pivot before schema prep** | Low | High | Phase 7.8 schema preparation closes this risk preventively. |
| **Insufficient validation volume** | Low | Medium | Already mitigated — 2,221 validation conversations + 1,000+ production conversations. |
| **Vision API cost escalation** | Low | Low | Per-image cost on gpt-4o-mini is negligible at current volumes; budget cap enforced at orchestrator level. |

---

## Get Involved

Interested in collaborating on this roadmap?

- **Research partnerships**: Dataset sharing, methodology validation
- **Technical contributions**: Open-source components
- **Pilot programs**: Enterprise evaluation
- **Advisory**: Strategic guidance

[Contact us](../README.md#contact) to discuss opportunities.

---

[← Back to Main](../README.md)

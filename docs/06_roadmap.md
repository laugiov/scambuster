# Roadmap

> **Last updated**: 2026-03-25

## Project Timeline Overview

```
Oct 2025    Nov 2025    Dec 2025    Jan 2026    Feb 2026
   │           │           │           │           │
   ▼           ▼           ▼           ▼           ▼
┌──────┐   ┌──────┐   ┌──────┐   ┌──────┐   ┌──────┐
│Phase │   │Phase │   │Phase │   │Phase │   │Phase │
│  1   │──▶│  2   │──▶│  3   │──▶│  4   │──▶│  5   │
│      │   │      │   │      │   │      │   │      │
│Found-│   │Adapt-│   │Scale │   │Valid-│   │Publi-│
│ation │   │ ive  │   │ + V2 │   │ation │   │cation│
└──────┘   └──────┘   └──────┘   └──────┘   └──────┘
   ✅          ✅         ✅         ✅         🔄
```

---

## Phase 1: Foundation (October - December 2025) ✅ COMPLETE

### Objectives
Build the core platform with multi-agent LLM architecture and production-grade infrastructure.

### Deliverables

| Deliverable | Status | Details |
|-------------|--------|---------|
| Multi-agent LLM architecture | ✅ | 5 specialized agents |
| Hybrid IOC extraction | ✅ | 100% precision, 34 types |
| Double validation pipeline | ✅ | 95% approval rate |
| Production deployment | ✅ | 60 days continuous, 0 incidents |
| Metrics collection | ✅ | 5.34 unique IOCs/conv, 100% precision |
| DDD backend architecture | ✅ | 1,039 automated tests |
| JWT authentication | ✅ | HS256, refresh rotation |

### Key Achievements

| Metric | Value |
|--------|-------|
| **Unique IOCs per conversation** | 5.34 (deduplicated) |
| **IOC Precision** | 100% (N=107) |
| **Persona variance** | 5.5x best vs worst |
| **Cost per IOC** | EUR 0.0002 |
| **System uptime** | 100% (60 days) |

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
2. Scale scam volume 4x (4.8 -> 20 conversations/day)
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
| **Unique IOCs/conversation** | 5.34 | >5 |
| **IOC Precision** | 100% (N=107) | >95% |
| **Persona variance** | 5.5x | Measured |
| **Scammer response rate** | 54% | >40% |
| **Cost per IOC** | EUR 0.0002 | <EUR 0.001 |
| **System uptime** | 60 days | 30 days |

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
| H3 | Adaptive reduces early abandonment | Validated (48.6% -> 36.4%) |
| H4 | Per-scam-type policy converges in <100 sessions | Validated (9/12 types) |

### Thompson Sampling (Planned -- v2)

Thompson Sampling was originally planned for this phase but was deferred to v2. The current epsilon-greedy + UCB1 algorithm already demonstrates statistically significant improvements over random selection, making it a viable production algorithm.

---

## Phase 5: Publication & Open Source (February - March 2026) ✅ COMPLETE

### Objectives
Publish research findings, harden platform for production, and release anonymized dataset.

### Production Hardening (March 2026) ✅

| Deliverable | Status | Details |
|-------------|--------|---------|
| Security by Design (OWASP headers, audit trail, JWT RS256, RBAC) | ✅ | 9 security controls implemented |
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

### v1.7.0 — Quality & Enterprise Integration (March 2026) ✅

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

### Paper Outline

1. Introduction & Motivation
2. Related Work (Cognitive Honeypots, LLM Scambaiting)
3. System Architecture (Multi-Agent, Adaptive)
4. Methodology (Bandit Formulation, Reward Design)
5. Experimental Results (A/B Testing)
6. Discussion (Limitations, Future Work)
7. Conclusion

### Dataset Contents

| Component | Size | Format |
|-----------|------|--------|
| **Conversations** | 600+ | JSON (anonymized) |
| **Messages** | 5,000+ | JSON (anonymized) |
| **IOCs** | 2,000+ | CSV (hashed where sensitive) |
| **Metadata** | N/A | JSON (scam types, personas, rewards) |

---

## Long-Term Vision (2026+)

### Potential Extensions

| Direction | Description | Prerequisite |
|-----------|-------------|--------------|
| **LinUCB** | Contextual features (time, language, country) | 10k+ conversations |
| **Multi-channel** | SMS, voice, social media | Partnership |
| **Meta-learning** | Transfer between scam types | Large dataset |
| **Real-time adaptation** | Adapt during conversation | Research |
| **Federation** | Multiple organizations sharing learnings | Legal framework |

### Commercial Opportunities

| Opportunity | Model | Status |
|-------------|-------|--------|
| **SOC-as-a-Service** | Subscription | Exploring |
| **IOC Feed** | Per-IOC or subscription | Possible |
| **Integration** | SIEM/SOAR plugins | TBD |
| **Consulting** | Implementation support | Available |

### Research Collaborations

| Partner Type | Potential Collaboration |
|--------------|------------------------|
| **Universities** | Joint research, student projects |
| **CERTs** | Data sharing, validation |
| **Law enforcement** | Campaign attribution |
| **Industry** | Pilot programs |

---

## Success Criteria

### Technical Success

| Criterion | Target | Measurement |
|-----------|--------|-------------|
| **System reliability** | >99% uptime | Monitoring |
| **IOC precision** | >98% | Manual validation |
| **Convergence speed** | <100 sessions | CV analysis |
| **Cost efficiency** | <€0.01/conversation | Tracking |

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
| **IOCs shared** | 1,000+ unique | Export count |
| **Partnerships** | 2+ organizations | Agreements |
| **Citations** | N/A (future) | Scholar tracking |
| **Industry adoption** | 1+ pilot | Engagement |

---

## Risk Factors & Contingencies

| Risk | Likelihood | Impact | Contingency |
|------|------------|--------|-------------|
| **Insufficient volume** | Medium | High | Extend collection, qualitative analysis |
| **Thompson not superior** | Low | Medium | Publish null result (still valuable) |
| **Technical issues** | Low | Medium | Fallback to ε-greedy |
| **Legal challenges** | Low | High | Legal review, scope limitation |
| **LLM cost increase** | Low | Medium | Alternative models, caching |

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

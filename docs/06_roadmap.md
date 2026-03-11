# Roadmap

> **Last updated**: 2026-02-02

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
   ✅          ✅         🔄         📅         📅
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
| Metrics collection | ✅ | +1K conversations, +20K IOCs |
| DDD backend architecture | ✅ | 955 automated tests |
| JWT authentication | ✅ | HS256, refresh rotation |

### Key Achievements

| Metric | Value |
|--------|-------|
| **Production conversations** | +1K |
| **IOCs captured** | +20K |
| **System uptime** | 100% (60 days) |
| **Total cost** | €5.2 |
| **Cost per IOC** | €0.0002 |

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

## Phase 3: Thompson Sampling + Scale (December 2025) 🔄 IN PROGRESS

### Objectives
1. Upgrade to Thompson Sampling (Bayesian, zero hyperparameters)
2. Scale scam volume 4× (4.8 → 20 conversations/day)
3. Deploy operational dashboards

### Week 1 (Dec 1-7): Production Deployment + Thompson Sampling

| Task | Priority | Status |
|------|----------|--------|
| Production database migration | P0 | ✅ |
| Add alpha/beta columns for Thompson | P0 | ✅ |
| Implement ThompsonSamplingSelector | P0 | ✅ |
| Unit tests (15+) | P0 | ✅ |
| Integration tests | P0 | ✅ |
| Documentation | P0 | ✅ |

### Week 2 (Dec 8-14): Dashboards + Activation

| Task | Priority | Status |
|------|----------|--------|
| Grafana installation | P0 | 🔄 |
| Operational dashboard (6 panels) | P0 | 🔄 |
| Adaptive dashboard (6 panels) | P0 | 🔄 |
| Threat Intelligence dashboard (6 panels) | P0 | 📅 |
| Activate Thompson Sampling (20% → 100%) | P0 | ✅ |
| Prompt injection detection (two-layer forensic) | P0 | ✅ |
| Intermediate report | P1 | ✅ |

### Week 3 (Dec 15-21): Scale Volume

| Task | Priority | Status |
|------|----------|--------|
| Add 6 new scraping sources | P0 | 🔄 |
| Increase frequency (1×/day → 3×/day) | P0 | 🔄 |
| Create 3 new Gmail honeypots | P0 | 📅 |
| Active honeypots (classified ads) | P0 | 📅 |
| Deduplication service | P0 | ✅ |
| Anti-scam partnerships outreach | P1 | 📅 |

### Week 4 (Dec 22-31): Engagement Optimization

| Task | Priority | Status |
|------|----------|--------|
| Human-like response delays (1-6h) | P0 | 📅 |
| Micro-error injection (typos) | P0 | 📅 |
| Prompt improvements | P0 | 📅 |
| A/B testing configuration | P0 | 📅 |
| December report | P0 | 📅 |
| Presentation preparation | P1 | 📅 |

### Pilot Achievements (February 2026)

| Metric | Achieved | Target |
|--------|----------|--------|
| **Conversations** | +1K | 200+ |
| **IOCs extracted** | +20K | 2,000+ |
| **System uptime** | 60 days | 30 days |
| **Total cost** | €5.2 | <€5 |
| **Max engagement** | 48.7h | >24h |
| **Persona variance** | 5.5× | Measured |

---

## Phase 4: A/B Testing & Validation (January 2026) 📅 PLANNED

### Objectives
Scientifically validate Thompson Sampling superiority and collect publication-ready data.

### Week 1: Setup

| Task | Priority |
|------|----------|
| Configure Group A (ε-greedy control) | P0 |
| Configure Group B (Thompson Sampling) | P0 |
| Traffic splitting mechanism | P0 |
| Metrics collection automation | P0 |

### Weeks 2-4: Data Collection

| Target | Value |
|--------|-------|
| **Duration** | 60 days |
| **Conversations per group** | 200+ |
| **Statistical power** | 0.80 |
| **Significance level** | α = 0.05 |

### Week 5: Analysis

| Deliverable | Description |
|-------------|-------------|
| **Statistical report** | Hypothesis tests (t-test, χ², Mann-Whitney) |
| **Effect sizes** | Cohen's d for all metrics |
| **Convergence analysis** | Beta distribution visualization |
| **Academic report** | 15-20 pages, publication-ready |

### Hypotheses to Validate

| ID | Hypothesis | Expected Result |
|----|------------|-----------------|
| H1 | Thompson improves engagement duration | +100% median |
| H2 | Thompson increases IOCs/conversation | +30% |
| H3 | Thompson reduces early abandonment | -20% at 2nd turn |
| H4 | Thompson converges faster | <100 sessions vs ~200 |

---

## Phase 5: Publication & Open Source (February 2026) 📅 PLANNED

### Objectives
Publish research findings and release anonymized dataset.

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

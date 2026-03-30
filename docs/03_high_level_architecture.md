# High-Level Architecture

> **Note**: This document describes the conceptual architecture without implementation details. Operational specifics are available under NDA.

---

## System Overview

ScamBuster is designed as a **modular, event-driven system** with clear separation of concerns:

```
┌─────────────────────────────────────────────────────────────────────────┐
│                           INGESTION LAYER                                │
│              ┌───────────────┐  ┌───────────────┐                       │
│              │  Email (IMAP) │  │   Honeypots   │                       │
│              └───────┬───────┘  └───────┬───────┘                       │
│                      └──────────┬───────┘                               │
│                                 ▼                                        │
├─────────────────────────────────────────────────────────────────────────┤
│                         ORCHESTRATION LAYER                              │
│                    ┌─────────────────────┐                               │
│                    │   Workflow Engine   │                               │
│                    │       (n8n)         │                               │
│                    └──────────┬──────────┘                               │
│                               ▼                                          │
├─────────────────────────────────────────────────────────────────────────┤
│                           LLM PIPELINE                                   │
│  ┌─────────────┐ ┌────────────┐ ┌──────────────┐ ┌──────────┐         │
│  │ScamClassifier│→│IocExtractor│→│InjectionDet. │→│Generator │         │
│  └─────────────┘ └────────────┘ └──────────────┘ └────┬─────┘         │
│                               ▲                        ▼                │
│                    ┌──────────┴──────────┐   ┌──────────────┐          │
│                    │    Orchestrator     │   │  Validator   │          │
│                    │  (cost & quality)   │   │  (safety)    │          │
│                    └─────────────────────┘   └──────────────┘          │
├─────────────────────────────────────────────────────────────────────────┤
│                          BACKEND SERVICES                                │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  ┌──────────────┐  │
│  │Conversation │  │   Message   │  │     IOC     │  │   Adaptive   │  │
│  │  Manager    │  │   Handler   │  │  Extractor  │  │  (ε-greedy)  │  │
│  └─────────────┘  └─────────────┘  └─────────────┘  └──────────────┘  │
├─────────────────────────────────────────────────────────────────────────┤
│                          DATA LAYER                                      │
│  ┌─────────────────────┐  ┌─────────────────────┐  ┌─────────────────┐ │
│  │     PostgreSQL      │  │       Redis         │  │   Vault (KV)    │ │
│  │   (conversations,   │  │  (cache, sessions,  │  │  (credentials,  │ │
│  │   messages, IOCs)   │  │   rate limiting)    │  │   API keys)     │ │
│  └─────────────────────┘  └─────────────────────┘  └─────────────────┘ │
├─────────────────────────────────────────────────────────────────────────┤
│                          EXPORT LAYER                                    │
│         ┌─────────────┐  ┌─────────────┐                               │
│         │  STIX 2.1   │  │  REST API   │                               │
│         │   Export    │  │  (JSON)     │                               │
│         └─────────────┘  └─────────────┘                               │
└─────────────────────────────────────────────────────────────────────────┘
```

---

## Layer Descriptions

### 1. Ingestion Layer

Scam emails are received via monitored mailboxes:

| Source | Method | Volume |
|--------|--------|--------|
| **Email (IMAP)** | Real-time monitoring of honeypot accounts | Passive |
| **Honeypots** | Dedicated email accounts exposed on forums | Passive |

**Key features**:
- Deduplication (composite hash based)
- Risk scoring (integration with Rspamd)
- Automatic conversation reopening on new inbound message

### 2. Orchestration Layer

Workflow engine coordinates all processing steps:

- **Email intake**: Parse, score, classify, store
- **Response generation**: Select persona, generate, validate, send
- **IOC extraction**: Extract, deduplicate, enrich, store

**Technology**: n8n (self-hosted, 400+ integrations)

### 3. LLM Pipeline

Five specialized agents form the core pipeline, supported by one forensic module:

```
                    ┌─────────────────┐
                    │   Orchestrator  │
                    │ (coordination)  │
                    └────────┬────────┘
                             │
       ┌─────────────────────┼─────────────────────┐
       ▼                     ▼                     ▼
┌──────────────┐  ┌───────────────┐  ┌──────────────────┐
│ScamClassifier│  │ IocExtractor  │  │InjectionDetector │
│ (categorize) │  │  (extract)    │  │   (forensic)     │
└──────────────┘  └───────────────┘  └──────────────────┘

                  ┌───────────────┐
                  │   Generator   │
                  │  (respond)    │
                  └───────┬───────┘
                          ▼
                  ┌───────────────┐
                  │   Validator   │
                  │   (safety)    │
                  └───────────────┘
```

| Agent | Role | Phase |
|-------|------|-------|
| **ScamClassifier** | Categorize inbound scam emails (13 types) | Ingestion |
| **IocExtractor** | Extract IOCs from messages (34 types, hybrid regex+LLM) | Ingestion |
| **Generator** | Generate contextual replies using identity-focused persona prompts (27 personas, auto language detection) | Reply |
| **Validator** | Two-layer validation: PolicyGuard (dynamic thresholds) + LLM multi-criteria scoring (naturalness, persona_fit, ti_value) | Reply |
| **Orchestrator** | Coordinate generation loop (3 attempts, multilingual fallback, cost tracking) | Reply |

| Forensic Module | Role | Phase |
|-----------------|------|-------|
| **InjectionDetector** | Non-blocking prompt injection analysis (pattern + LLM-as-judge) | Ingestion |

**Design principles**:
- Single responsibility per agent
- Stateless (context passed explicitly)
- Retry with feedback on failure
- Cost tracking per call

### 4. Backend Services

Domain-Driven Design (DDD) architecture:

```
┌─────────────────────────────────────────────────────────┐
│                    UI / HTTP Layer                       │
│               (Controllers, REST API)                    │
├─────────────────────────────────────────────────────────┤
│                  Application Layer                       │
│    (Use Cases, Commands, Queries, Event Handlers)       │
├─────────────────────────────────────────────────────────┤
│                    Domain Layer                          │
│  (Entities, Value Objects, Domain Services, Events)     │
│            *** Zero framework dependencies ***           │
├─────────────────────────────────────────────────────────┤
│                 Infrastructure Layer                     │
│    (Repositories, External APIs, Persistence)           │
└─────────────────────────────────────────────────────────┘
```

**Key domains**:
- **Conversation**: Lifecycle management, per-scam-type policies, status, risk scoring
- **Message**: Threading, direction, deduplication
- **IOC**: Extraction, classification, enrichment
- **Adaptive**: Epsilon-greedy bandit, persona performance tracking, convergence logging
- **Monitoring**: Lifecycle alerts, rate limit stats, LLM cost tracking, audit trail (16 event types)
- **Evaluation**: Automated benchmark suite (9 quality metrics, 3 CLI commands)
- **Tracing**: Per-reply pipeline trace (component timing, cost, approval status)

### 5. Data Layer

| Store | Purpose | Key Features |
|-------|---------|--------------|
| **PostgreSQL** | Primary data | Access control, application-level audit trail |
| **Redis** | Cache, sessions | Rate limiting, temporary state |
| **Vault** | Credentials | IMAP passwords, API keys |

### 6. Export Layer

Standard formats for integration:

| Format | Details | Use Case |
|--------|---------|----------|
| **STIX 2.1** | Standard bundles | Threat intelligence platforms |
| **REST API** | JSON | Custom integrations |
| **SIEM** | CEF, ECS, JSON | Enterprise SIEM/SOAR integration |

---

## Data Flow: Email to Intelligence

```
1. INGEST
   Email arrives → Risk scoring → Classification → Injection analysis → Store

2. ENGAGE
   Select persona (ε-greedy bandit) → Generate response → Validate → Send

3. EXTRACT & SCORE
   Receive reply → Extract IOCs → Deduplicate → Enrich → Confidence Score → Decay

4. LEARN
   Conversation ends → Calculate reward → Update bandit

5. EXPORT
   IOCs aggregated → Format (STIX 2.1) → Publish
```

---

## Adaptive Scambaiting Component

### Contextual Bandit (Epsilon-Greedy)

```
┌─────────────────────────────────────────────────────────┐
│                  Persona Selection                       │
│                                                          │
│  Context (scam_type) ──┐                                │
│                        ▼                                 │
│              ┌─────────────────┐                        │
│              │  Bandit State   │                        │
│              │  (per scam_type)│                        │
│              └────────┬────────┘                        │
│                       │                                  │
│         ┌─────────────┼─────────────┐                   │
│         ▼             ▼             ▼                   │
│   ┌──────────┐  ┌──────────┐  ┌──────────┐            │
│   │ Persona 1│  │ Persona 2│  │ Persona N│            │
│   │  stats   │  │  stats   │  │  stats   │            │
│   └──────────┘  └──────────┘  └──────────┘            │
│         │             │             │                   │
│         └─────────────┼─────────────┘                   │
│                       ▼                                  │
│              ┌─────────────────┐                        │
│              │  Selection      │                        │
│              │  (ε-greedy:     │                        │
│              │  80/20 explore/ │                        │
│              │  exploit)       │                        │
│              └─────────────────┘                        │
│                       │                                  │
│                       ▼                                  │
│              Selected Persona                           │
└─────────────────────────────────────────────────────────┘
```

### Performance Tracking

| Metric | Storage | Update Trigger |
|--------|---------|----------------|
| Sessions count | Per persona × scam_type | Conversation start |
| Reward sum | Per persona × scam_type | Conversation end |
| Reward average | Computed | On update |

---

## Conversation Lifecycle Management

Each scam type has a dedicated lifecycle policy controlling timeouts, turn limits, and reopen behavior:

| Category | Scam Types | Timeout | Max Turns | Max Duration | Reopen |
|----------|------------|---------|-----------|--------------|--------|
| **Long engagement** | ROMANCE, INVESTMENT, ADVANCE_FEE_419 | 7-14 days | 40-50 | 30-60 days | Yes |
| **Medium engagement** | INVOICE_FRAUD, CEO_FRAUD | 3-5 days | 25-30 | 14-21 days | No |
| **Short engagement** | PHISHING, PHISH_CREDENTIALS, PHISH_MALWARE, TECH_SUPPORT | 1-2 days | 15-20 | 5-7 days | No |
| **Casual** | LOTTERY, JOB_OFFER, CHARITY | 3 days | 25 | 14 days | No |

**Closure criteria** (any triggers closure):
1. **Inactivity timeout**: No messages for N hours (per scam type)
2. **Max turns**: Conversation exchange limit reached
3. **Max duration**: Calendar time limit exceeded

**Reopen window**: Long-engagement scam types allow reopening within 48-72h if the scammer returns.

---

## Prompt Injection Detection

### Two-Layer Forensic Architecture

ScamBuster includes a forensic prompt injection detector that analyzes every inbound message. This is **research-oriented** -- it does not block ingestion or modify the reply pipeline.

```
Inbound message
       │
       ▼
┌──────────────────┐
│  Layer 1         │  < 1ms, zero cost
│  Pattern Matcher │  Known injection signatures (regex)
└────────┬─────────┘
         │
         ▼
┌──────────────────┐
│  Layer 2         │  LLM call (configurable model)
│  LLM-as-Judge   │  Semantic analysis, 6-technique taxonomy
└────────┬─────────┘
         │
         ▼
┌──────────────────┐
│  Analysis stored │  JSON column on message entity
│  (forensic)      │  risk_score, techniques, confidence
└──────────────────┘
```

**Technique taxonomy** (Layer 2):
1. Direct injection (explicit override instructions)
2. Indirect injection (hidden instructions in content)
3. Jailbreak (bypass safety constraints)
4. Prompt extraction (reveal system prompt)
5. Encoding tricks (base64, Unicode obfuscation)
6. Social engineering to break character (AI detection)

**Key research question**: Do real-world scammers attempt prompt injection against LLM-based honeypots?

---

## Infrastructure

### Deployment Model

ScamBuster is deployed as a **containerized application** with:

- **Isolated environments**: Separate production and pre-production
- **Automated CI/CD**: GitHub Actions (PHPStan, PHP-CS-Fixer, PHPUnit)
- **Secrets management**: HashiCorp Vault (credentials never in code)
- **Network isolation**: Docker Compose, defense-in-depth

> **Note**: Detailed infrastructure specifications available under NDA for pilot programs.

### SIEM Export

Pluggable SIEM connector (same port/adapter pattern as LLM providers):

| Adapter | Transport | Format | Use Case |
|---------|-----------|--------|----------|
| **NullSiemExporter** | None | — | Default (disabled, zero overhead) |
| **FileSiemExporter** | Local file | NDJSON | Testing, air-gapped deployments |
| **SyslogSiemExporter** | UDP/TCP | CEF/ECS | QRadar, ArcSight, Elastic |

Configuration: `SIEM_PROVIDER` env var. See [SIEM Integration Guide](15_siem_integration.md).

---

## Technology Choices

| Component | Technology | Rationale |
|-----------|------------|-----------|
| **Language** | PHP 8.3 | Strong typing, mature ecosystem, DDD support |
| **Framework** | Symfony 7.2 | Enterprise-grade, security features |
| **Database** | PostgreSQL 15 | JSON support, reliability, access control |
| **Cache & Locks** | Redis 7 | Rate limiting, distributed locks |
| **LLM** | OpenAI API (GPT-4o generation, GPT-4o-mini validation) | Quality for generation, cost-effective for validation |
| **Embeddings** | OpenAI text-embedding-3-small | Semantic similarity for campaign clustering ($0.02/1M tokens) |
| **Orchestration** | n8n | Visual debugging, 400+ integrations |
| **Secrets** | HashiCorp Vault | IMAP credentials, API keys |
| **CI/CD** | GitHub Actions | PHPStan, PHP-CS-Fixer, PHPUnit |

---

## Scalability Considerations

### Current State (March 2026)

| Metric | Value |
|--------|-------|
| Unique IOCs per conversation | Multiple unique IOCs per conversation (deduplicated) |
| IOC Precision | High precision on audited samples |
| System uptime | Continuous operation (0 incidents) |
| Scam types supported | 13 (with per-type lifecycle policies) |
| Frontend pages | 14 (Dashboard, Conversations, Detail, IOC Explorer, STIX Export, Personas, Campaigns, Campaign Detail, LLM Costs, Monitoring, Pipeline Monitor, Injection Monitor, Settings, Login) |
| Automated tests | Comprehensive test suite (unit, integration, E2E) |
| Code coverage | 81.75% (Codecov) |
| Infrastructure | Containerized, single host |

### Proven Quality

| Metric | Achieved |
|--------|----------|
| Unique IOCs/conversation | Multiple unique IOCs per conversation |
| Persona variance | 5.5x best vs worst |
| Cost per IOC | Low cost per IOC (with lightweight models) |
| Infrastructure | Same (sufficient headroom) |

### Future Scaling Options

- Horizontal scaling of backend (stateless)
- Read replicas for database
- Dedicated n8n workers for parallel processing

---

## Next Steps

- [Security & Ethics](04_security_guardrails.md): Safety controls and compliance
- [Evaluation](05_evaluation_methodology.md): How we measure success
- [Roadmap](06_roadmap.md): Development timeline

---

[← Back to Main](../README.md)

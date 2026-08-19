# High-Level Architecture

> **Note**: This document describes the conceptual architecture without implementation details. For implementation details, see the source code.

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
│  │     PostgreSQL      │  │       Redis         │                     │
│  │   (conversations,   │  │  (cache, sessions,  │                     │
│  │   messages, IOCs)   │  │   rate limiting)    │                     │
│  └─────────────────────┘  └─────────────────────┘                     │
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
- Risk scoring (Rspamd score extraction from headers)
- Automatic conversation reopening on new inbound message

### 2. Orchestration Layer

Workflow engine coordinates all processing steps:

- **Email intake**: Parse, score, classify, store
- **Response generation**: Select persona, generate, validate, send
- **IOC extraction**: Extract, deduplicate, enrich, store

**Technology**: n8n (self-hosted, 400+ integrations)

### 3. LLM Pipeline

**Eight agents** form the core pipeline (the Injection Detector is forensic — it observes prompt-injection attempts but never blocks; the Conversation Director steers the Generator each turn; the TTP Extractor is an inbound-only CTI tagger, feature-flagged and added after the white-paper window):

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
| **ScamClassifier** | Categorize inbound scam emails (13 scam types + an UNKNOWN fallback) | Ingestion |
| **IocExtractor** | Extract IOCs from messages (36 types, hybrid regex+LLM) | Ingestion |
| **Generator** | Generate contextual replies using identity-focused persona prompts (27 personas, auto language detection) | Reply |
| **Validator** | Two-layer validation: PolicyGuard (dynamic thresholds) + LLM multi-criteria scoring (naturalness, persona_fit, ti_value) | Reply |
| **Orchestrator** | Coordinate generation loop (3 attempts, multilingual fallback, cost tracking) | Reply |
| **ConversationDirector** | Reason over the whole thread each turn and steer the Generator (see below) | Reply |
| **TtpExtractor** | Tag scammer TTPs on inbound messages against a closed 27-entry taxonomy (inbound-only, feature-flagged, post-white-paper) | Ingestion |

| Forensic Module | Role | Phase |
|-----------------|------|-------|
| **InjectionDetector** | Non-blocking prompt injection analysis (pattern + LLM-as-judge) | Ingestion |

**Design principles**:
- Single responsibility per agent
- Stateless (context passed explicitly)
- Retry with feedback on failure
- Cost tracking per call

#### Conversation Director

Individual replies can be fluent yet fail to *conduct* a conversation: a persona
may re-ask for details already provided, keep engaging with no goal in mind, or
carry on after the correspondent has clearly disengaged. The Conversation
Director is an LLM reasoning step (a single judgement pass, reused across the
turn) that gives the Generator a strategic brief instead of relying on brittle
string matching:

- **Anti-repetition** — it lists, semantically and in any language, the
  information the correspondent has already revealed, so the persona never asks
  for the same thing twice (a common tell that a reply is automated).
- **Objective** — it infers the goal for the current turn from the scam's own
  mechanics (for example, steering a fake-services pitch toward a price, an
  invoice, and a payment method), rather than a fixed per-scam-type script.
- **Correspondent state & stop signal** — it reads whether the correspondent is
  cooperative, stalling, suspicious, or disengaged, and can signal that the
  exchange is no longer productive. When it does, the pipeline closes the
  conversation instead of sending another reply (see *Conversation Lifecycle
  Management*).

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
│  *** Minimal framework coupling (Doctrine annotations) ***  │
├─────────────────────────────────────────────────────────┤
│                 Infrastructure Layer                     │
│    (Repositories, External APIs, Persistence)           │
└─────────────────────────────────────────────────────────┘
```

**Key domains**:
- **Conversation**: Lifecycle management, per-scam-type policies, status, risk scoring
- **Message**: Threading, direction, deduplication
- **IOC**: Extraction, classification, enrichment
- **TTP**: Scammer-tactic tagging on inbound messages (Domain `Ttp` / `TtpObservation` entities + `TtpExtractionPolicy`; Application `TtpHandler`, `TtpExtractor`, `TtpQueryService`, `TtpObservationUpsertService`; `UI/Http/Ttp` read controllers + `UI/Console` backfill / audit-sample commands; hooked in-process at ingest via `IngestPostProcessor`; STIX attack-patterns + MISP tags in the export layer). Feature-flagged, evidence stays internal
- **Adaptive**: Epsilon-greedy bandit, persona performance tracking, convergence logging
- **Monitoring**: Lifecycle alerts, rate limit stats, LLM cost tracking, audit trail (33 event types)
- **Evaluation**: Automated benchmark suite (7 quality metrics, 3 CLI commands)
- **Tracing**: Per-reply pipeline trace (component timing, cost, approval status)

### 5. Data Layer

| Store | Purpose | Key Features |
|-------|---------|--------------|
| **PostgreSQL** | Primary data | Access control, application-level audit trail |
| **Redis** | Cache, sessions | Rate limiting, temporary state |

### 6. Export Layer

Standard formats for integration:

| Format | Details | Use Case |
|--------|---------|----------|
| **STIX 2.1** | Standard bundles (indicators, campaigns, threat-actors) | Threat intelligence platforms |
| **TAXII 2.1** | Server endpoint for STIX feed consumption | Automated TIP polling |
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
│              │  20/80 explore/ │                        │
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

### Reward Signal (Hybrid)

The value fed back to the bandit at conversation end is a **hybrid reward**. A
purely mechanical score (engagement duration, IOC counts, completion) is easy to
inflate — a long, repetitive exchange that yields nothing of value can still look
"successful". To avoid that, an LLM judges the *actual outcome* of the finished
conversation (did it surface high-value operational intelligence such as
payment/cash-out details, was engagement genuine, or was the persona exposed and
the correspondent lost?) and that judgement is blended with the mechanical score,
LLM-dominant.

The blend is deliberately fault-tolerant: if the outcome judgement is
unavailable for any reason, the mechanical score is used unchanged, so scoring
never blocks a conversation from closing.

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
4. **Strategic stop**: The Conversation Director judged the exchange no longer productive (correspondent disengaged or challenging the persona's authenticity with no path to further intelligence) — the conversation is closed instead of continuing

**Reopen window**: Long-engagement scam types allow reopening within 48-72h if the scammer returns.

**Reply cadence**: every reply passes through **human delay simulation** (configurable
cadence, randomized timing, time-of-day awareness) to mimic realistic response patterns.

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
- **Secrets management**: Environment variables / Docker secrets (credentials never in code)
- **Network isolation**: Docker Compose, defense-in-depth

> **Note**: See docker-compose.yml for full infrastructure configuration.

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
| **Framework** | Symfony 7.4 | Enterprise-grade, security features |
| **Database** | PostgreSQL 15 | JSON support, reliability, access control |
| **Cache & Locks** | Redis 7 | Rate limiting, distributed locks |
| **Frontend** | React 19, TypeScript, TailwindCSS, i18n (EN/FR) | Operations dashboard and analyst screens |
| **LLM** | Multi-provider — OpenAI, Anthropic, Ollama (full local), Mock (dev). OpenAI API by default (GPT-4o-mini generation, configurable; GPT-4o-mini validation) | Cost-effective default, upgradable to larger models for generation; provider switched with one env var |
| **Embeddings** | OpenAI text-embedding-3-small | Semantic similarity for campaign clustering ($0.02/1M tokens) |
| **Orchestration** | n8n (self-hosted) | Visual debugging, 400+ integrations |
| **Secrets** | Environment variables / Docker secrets | IMAP credentials, API keys |
| **Monitoring** | Prometheus metrics, LLM cost tracking, pipeline tracing | Operational visibility per reply and per call |
| **Infrastructure** | Docker Compose, GitHub Actions CI | Single-host deployment, automated checks |
| **SIEM** | CEF, ECS, JSON — pluggable connector | See [SIEM Export](#siem-export) above |
| **CI/CD** | GitHub Actions | PHPStan, PHP-CS-Fixer, PHPUnit |

> **Data sovereignty**: Deploy with `LLM_PROVIDER=ollama` for 100% on-premise processing.
> No data leaves your infrastructure.

---

## Repository Layout

```
scambuster/
  backend-symfony/         # PHP/Symfony backend (DDD)
    src/
      Domain/              # Entities, value objects, enums, events
      Application/         # Handlers, services, orchestrators
      Infrastructure/      # Doctrine repos, external APIs, listeners
      UI/Http/             # Controllers (single __invoke)
    tests/                 # PHPUnit (unit, integration, E2E)
  frontend-react/          # React 19 dashboard
  n8n/                     # Workflow definitions
  infra/                   # Docker configs
  docs/                    # Documentation guides
```

---

## Scalability Considerations

### System Composition

| Aspect | Value |
|--------|-------|
| Scam types supported | 13 (with per-type lifecycle policies) |
| IOC types extracted | 36 (hybrid regex + LLM) |
| Frontend pages | Dashboard, Conversations, Detail, IOC Explorer, STIX Export, Personas, LLM Costs, Monitoring, Pipeline Monitor, Injection Monitor, Settings, Login |
| Automated tests | Comprehensive test suite (unit, integration, E2E) |
| Code coverage | Tracked via Codecov |
| Infrastructure | Containerized, single host |

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

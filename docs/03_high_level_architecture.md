# High-Level Architecture

> **Note**: This document describes the conceptual architecture without implementation details. Operational specifics are available under NDA.

---

## System Overview

ScamBuster is designed as a **modular, event-driven system** with clear separation of concerns:

```
┌─────────────────────────────────────────────────────────────────────────┐
│                           INGESTION LAYER                                │
│  ┌───────────────┐  ┌───────────────┐  ┌───────────────┐                │
│  │  Email (IMAP) │  │   Scraping    │  │   Honeypots   │                │
│  └───────┬───────┘  └───────┬───────┘  └───────┬───────┘                │
│          └──────────────────┼──────────────────┘                        │
│                             ▼                                            │
├─────────────────────────────────────────────────────────────────────────┤
│                         ORCHESTRATION LAYER                              │
│                    ┌─────────────────────┐                               │
│                    │   Workflow Engine   │                               │
│                    │       (n8n)         │                               │
│                    └──────────┬──────────┘                               │
│                               ▼                                          │
├─────────────────────────────────────────────────────────────────────────┤
│                           LLM PIPELINE                                   │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  ┌─────────────┐ │
│  │ ScamClassifier│→│ IocExtractor │→│  Generator   │→│  Validator  │  │
│  └──────────────┘  └──────────────┘  └──────────────┘  └─────────────┘ │
│                               ▲                                          │
│                    ┌──────────┴──────────┐                               │
│                    │    Orchestrator     │                               │
│                    │  (cost & quality)   │                               │
│                    └─────────────────────┘                               │
├─────────────────────────────────────────────────────────────────────────┤
│                          BACKEND SERVICES                                │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  ┌─────────────────┐│
│  │Conversation │  │   Message   │  │     IOC     │  │ Adaptive Bandit ││
│  │  Manager    │  │   Handler   │  │  Extractor  │  │(ε-greedy/Thompson)│
│  └─────────────┘  └─────────────┘  └─────────────┘  └─────────────────┘│
├─────────────────────────────────────────────────────────────────────────┤
│                          DATA LAYER                                      │
│  ┌─────────────────────┐  ┌─────────────────────┐  ┌─────────────────┐ │
│  │     PostgreSQL      │  │       Redis         │  │   Vault (KV)    │ │
│  │   (conversations,   │  │  (cache, sessions,  │  │  (credentials,  │ │
│  │   messages, IOCs)   │  │   rate limiting)    │  │   API keys)     │ │
│  └─────────────────────┘  └─────────────────────┘  └─────────────────┘ │
├─────────────────────────────────────────────────────────────────────────┤
│                          EXPORT LAYER                                    │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  ┌─────────────────┐│
│  │  STIX 2.1   │  │    MISP     │  │  REST API   │  │   Dashboards    ││
│  │   Export    │  │    Feed     │  │  (JSON)     │  │   (Grafana)     ││
│  └─────────────┘  └─────────────┘  └─────────────┘  └─────────────────┘│
└─────────────────────────────────────────────────────────────────────────┘
```

---

## Layer Descriptions

### 1. Ingestion Layer

Multiple channels feed scam emails into the system:

| Source | Method | Volume |
|--------|--------|--------|
| **Email (IMAP)** | Real-time monitoring of honeypot accounts | Passive |
| **Scraping** | Public scam reporting sites | Active |
| **Honeypots** | Dedicated email accounts exposed on forums | Passive |

**Key features**:
- Deduplication (Message-ID based)
- Risk scoring (integration with Rspamd)
- Buffering for reliability

### 2. Orchestration Layer

Workflow engine coordinates all processing steps:

- **Email intake**: Parse, score, classify, store
- **Response generation**: Select persona, generate, validate, send
- **IOC extraction**: Extract, deduplicate, enrich, store
- **Campaign detection**: Cluster IOCs, identify patterns

**Technology**: n8n (self-hosted, 400+ integrations)

### 3. LLM Pipeline

Five specialized agents with distinct responsibilities:

```
                    ┌─────────────────┐
                    │   Orchestrator  │
                    │ (coordination)  │
                    └────────┬────────┘
                             │
         ┌───────────────────┼───────────────────┐
         ▼                   ▼                   ▼
┌─────────────────┐ ┌─────────────────┐ ┌─────────────────┐
│  ScamClassifier │ │  IocExtractor   │ │    Generator    │
│  (categorize)   │ │  (extract)      │ │  (respond)      │
└─────────────────┘ └─────────────────┘ └────────┬────────┘
                                                  │
                                                  ▼
                                        ┌─────────────────┐
                                        │    Validator    │
                                        │  (safety)       │
                                        └─────────────────┘
```

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
- **Conversation**: Lifecycle, status, risk scoring
- **Message**: Threading, direction, deduplication
- **IOC**: Extraction, classification, enrichment
- **Adaptive**: Bandit algorithm, performance tracking

### 5. Data Layer

| Store | Purpose | Key Features |
|-------|---------|--------------|
| **PostgreSQL** | Primary data | Access control, application-level audit trail |
| **Redis** | Cache, sessions | Rate limiting, temporary state |
| **Secrets Store** | Credentials | API keys, service accounts |

### 6. Export Layer

Standard formats for integration:

| Format | Use Case |
|--------|----------|
| **STIX 2.1** | Threat intelligence platforms |
| **MISP** | Information sharing communities |
| **REST API** | Custom integrations |
| **Grafana** | Operational dashboards |

---

## Data Flow: Email to Intelligence

```
1. INGEST
   Email arrives → Risk scoring → Classification → Store

2. ENGAGE
   Select persona (bandit) → Generate response → Validate → Send

3. EXTRACT
   Receive reply → Extract IOCs → Deduplicate → Enrich

4. LEARN
   Conversation ends → Calculate reward → Update bandit

5. EXPORT
   IOCs aggregated → Format (STIX/MISP) → Publish
```

---

## Adaptive Scambaiting Component

### Contextual Bandit Architecture

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
│              │  (ε-greedy or   │                        │
│              │  Thompson)      │                        │
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
| Alpha/Beta (Thompson) | Per persona × scam_type | Conversation end |

---

## Infrastructure

### Deployment Model

ScamBuster is deployed as a **containerized application** with:

- **Isolated environments**: Separate production and pre-production
- **Automated CI/CD**: GitLab CI with security scanning
- **Secrets management**: Dedicated secrets store (credentials never in code)
- **Network isolation**: Defense-in-depth with layered access controls

> **Note**: Detailed infrastructure specifications available under NDA for pilot programs.

---

## Technology Choices

| Component | Technology | Rationale |
|-----------|------------|-----------|
| **Language** | PHP 8.3 | Strong typing, mature ecosystem, DDD support |
| **Framework** | Symfony 7 | Enterprise-grade, security features |
| **Database** | PostgreSQL | JSON support, reliability, access control |
| **LLM** | OpenAI API | Cost-effective, consistent quality |
| **Orchestration** | n8n | Visual debugging, 400+ integrations |
| **CI/CD** | GitLab CI | Integrated, security scanning |

---

## Scalability Considerations

### Current State (February 2026)

| Metric | Value |
|--------|-------|
| Active conversations | +1K (60 days) |
| IOCs extracted | +20K total |
| System uptime | 60 days (0 incidents) |
| Infrastructure | Containerized, single host |

### Proven Capacity

| Metric | Achieved |
|--------|----------|
| Conversations (60 days) | +1K |
| IOCs (60 days) | +20K |
| Total cost | €5.2 |
| Infrastructure | Same (sufficient headroom) |

### Future Scaling Options

- Horizontal scaling of backend (stateless)
- Read replicas for database
- Dedicated n8n workers for parallel processing
- CDN for static assets (if web UI added)

---

## Next Steps

- [Security & Ethics](04_security_guardrails.md): Safety controls and compliance
- [Evaluation](05_evaluation_methodology.md): How we measure success
- [Roadmap](06_roadmap.md): Development timeline

---

[← Back to Main](../README.md)

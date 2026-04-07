<p align="center">
  <img src="frontend-react/public/scambuster_logo_horizontal.svg" alt="ScamBuster" width="500" />
</p>

<p align="center"><strong>Automated Scambaiting Honeypot & Threat Intelligence Platform</strong></p>

![Status](https://img.shields.io/badge/status-active-brightgreen)
![Stack](https://img.shields.io/badge/stack-PHP%208.3%20|%20Symfony%207.2%20|%20PostgreSQL%2015%20|%20LLM-green)
[![codecov](https://codecov.io/gh/laugiov/scambuster/graph/badge.svg?token=4TXL7E2L7W)](https://codecov.io/gh/laugiov/scambuster)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![CI](https://github.com/laugiov/scambuster/actions/workflows/ci.yml/badge.svg)](https://github.com/laugiov/scambuster/actions/workflows/ci.yml)
[![Docker](https://img.shields.io/badge/Docker-ready-blue.svg)](docker-compose.yml)
[![STIX](https://img.shields.io/badge/STIX-2.1-red.svg)](docs/16_taxii_server.md)
[![PRs Welcome](https://img.shields.io/badge/PRs-welcome-brightgreen.svg)](CONTRIBUTING.md)

> **Last updated**: 2026-04-07 | **Data period**: December 2025 -- ongoing

<p align="center">
  <img src="frontend-react/public/scambuster_screenshots.gif" alt="ScamBuster Operations Dashboard" width="100%" />
</p>

> **Try the demo** (no install): [Live Demo](https://frontend-production-b836.up.railway.app) -- login: `user@example.com` / `Un1que$trongPassword2024`
>
> **Install locally in 5 minutes**: [Quickstart Guide](docs/QUICKSTART.md) -- `cp .env.dist .env` -> edit 4 values -> `make quickstart` -> done.
>
> **Run demo locally** (no API key needed): [Demo Guide](docs/DEMO.md) -- `make demo-up` -> http://localhost:3002

ScamBuster turns inbound scam emails into **actionable threat intelligence** through **controlled, policy-driven engagement**. It extracts IOCs, profiles threat actors, measures engagement effectiveness, and exports intelligence in STIX 2.1 / MISP / TAXII formats -- all safety-gated, cost-aware, and fully auditable.

---

## The Problem

Email scams operate at massive scale. Most security programs **block and forget**: the message is removed, but the attacker infrastructure, financial rails, and campaign signals remain unobserved. There is little attribution, limited visibility into evolving TTPs, and no intelligence generated from real-world interaction with threat actors.

ScamBuster explores this gap by converting scam emails into measurable threat intelligence, safely and at scale. See [Problem Statement](docs/01_problem_statement.md).

---

## How It Works

### Multi-Agent LLM Pipeline

| Agent | Role |
|-------|------|
| **ScamClassifier** | Categorize incoming scams (13 types) + detect language |
| **IocExtractor** | Extract threat indicators (40+ IOC types) with contextual enrichment |
| **Generator** | Create persona-driven responses that maximize intelligence yield |
| **Validator** | Ensure safety & quality (PolicyGuard + LLM double validation) |
| **Orchestrator** | Coordinate pipeline, optimize costs, track per-reply traces |
| **InjectionDetector** | Two-layer prompt injection analysis (pattern + LLM-as-judge) |

Every reply passes through **human delay simulation** (configurable cadence, randomized timing, time-of-day awareness) to mimic realistic response patterns.

### Adaptive Strategy Selection

ScamBuster uses **epsilon-greedy with UCB1 exploration** to learn which persona maximizes intelligence yield per scam type. Strategy performance varies significantly across categories -- data-driven selection outperforms human intuition.

---

## What ScamBuster Produces

### IOC Extraction with Contextual Enrichment

Each conversation yields deduplicated IOCs (emails, domains, IPs, IBANs, crypto wallets, phones, Telegram usernames, file hashes...) enriched with **semantic context**: the role of each IOC in the scam narrative (payment destination, phishing lure, contact channel), stimulus type, urgency scoring, and a PII-free context excerpt.

### Threat Actor Profiling

Each conversation produces a **STIX 2.1 threat-actor** with behavioral profiling:

```json
{
  "type": "threat-actor",
  "name": "ScamBuster Actor - INVESTMENT #02114290",
  "sophistication": "minimal",
  "goals": ["financial-theft"],
  "primary_motivation": "personal-gain",
  "threat_actor_types": ["criminal"],
  "description": "Criminal actor operating investment scam."
}
```

Threat actors include MITRE ATT&CK technique mapping, `indicates` relationships to all IOCs, and a custom `x_scambuster_actor` extension with engagement metrics. Bundles are validated for import into **OpenCTI**.

### Automated Intelligence Feeds

- **STIX 2.1 export** per conversation (indicators + threat-actor + attack-pattern + relationships)
- **TAXII 2.1 server** with delta sync -- IOC indicators enriched with threat-actor attribution
- **MISP Event JSON** export
- Compatible with OpenCTI, MISP, TheHive, Splunk, QRadar, Elastic

See [TAXII Server Guide](docs/16_taxii_server.md) and [API Reference](docs/12_api_quick_reference.md).

---

## Pilot Results

### Controlled Live Deployment (60+ Days)

| Metric | Result |
|--------|--------|
| **IOC precision** | High precision on audited samples (vs 44% with regex-only baseline) |
| **Persona variance** | 5.5x between best and worst persona per scam type |
| **Scammer response rate** | 54% |
| **Max engagement** | 48.7 hours sustained interaction |
| **Cost** | Negligible operational expense with lightweight models |

Adaptive strategy selection validated on synthetic conversations with statistically significant results (p < 0.001, Cohen's d = 0.37). Full methodology in [Evaluation](docs/05_evaluation_methodology.md).

---

## Tech Stack

| Layer | Technology |
|-------|------------|
| **Backend** | PHP 8.3, Symfony 7.2, DDD architecture |
| **Database** | PostgreSQL 15, Redis 7 |
| **Frontend** | React 19, TypeScript, TailwindCSS, i18n (EN/FR) |
| **LLM** | Multi-provider: OpenAI, Anthropic, Ollama (full local), Mock (dev) |
| **Orchestration** | n8n (self-hosted) |
| **Secrets** | HashiCorp Vault |
| **Monitoring** | Prometheus metrics, LLM cost tracking, pipeline tracing |
| **Infrastructure** | Docker Compose, GitHub Actions CI |
| **SIEM** | CEF, ECS, JSON -- pluggable connector |

> **Data sovereignty**: Deploy with `LLM_PROVIDER=ollama` for 100% on-premise processing. No data leaves your infrastructure.

---

## Quick Start

```bash
git clone https://github.com/laugiov/scambuster.git
cd scambuster
cp .env.dist .env        # Configure (see below)
make build && make upd   # Build and start Docker stack
make composer-install    # Install PHP dependencies
make migration           # Create database schema
make fixtures-dev        # Seed reference data + default users
make test                # Run tests
```

**Minimum `.env` configuration**:

| Variable | What to do |
|----------|------------|
| `POSTGRES_PASSWORD` | Choose a password, update `DATABASE_URL` to match |
| `JWT_SECRET` | `openssl rand -base64 64` |
| `LLM_API_KEY` | Your OpenAI API key (or set `LLM_PROVIDER=mock` for demo) |

**LLM providers** (switch with one env var):

| Provider | `LLM_PROVIDER=` | Data Location | Best For |
|----------|-----------------|---------------|----------|
| **OpenAI** | `openai` | Cloud | Best quality (GPT-4o) |
| **Anthropic** | `anthropic` | Cloud | Alternative (Claude) |
| **Ollama** | `ollama` | **100% local** | Sovereign deployment |
| **Mock** | `mock` | Local | Demo (no API key, no cost) |

**Default credentials** (created by fixtures):

| Email | Password | Role |
|-------|----------|------|
| `user@example.com` | `Un1que$trongPassword2024` | `ROLE_USER` |
| `admin@example.com` | `Un1que$trongPassword2024` | `ROLE_ADMIN` |

> Full setup guide: [Getting Started](docs/08_getting_started.md)

---

## Project Structure

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
  docs/                    # Documentation (16 guides)
```

---

## Security & Ethics

ScamBuster is a **defensive research tool**, not an offensive weapon.

- **Inbound-only**: engages only after scammer initiates contact
- **No unauthorized access**: never accesses attacker systems
- **Content filtering**: PolicyGuard blocks threats, illegal content, real PII
- **Rate limiting**: hard limits on conversations, messages, and LLM calls
- **Kill switch**: immediate halt at workflow, API, database, or infrastructure level
- **GDPR**: data minimization, retention policies, encryption at rest

See [Security & Guardrails](docs/04_security_guardrails.md) and [SECURITY.md](SECURITY.md).

---

## Project Status

| Phase | Status |
|-------|--------|
| Multi-agent LLM architecture | Complete |
| Adaptive engagement (epsilon-greedy) | Complete |
| Scale, dashboards & observability | Complete |
| Quality assurance & validation | Complete |
| Rich contextual IOC enrichment | Complete |
| STIX threat-actor export & OpenCTI integration | Complete |
| Threat-actor clustering (Union-Find on financial IOCs) | Planned |
| Thompson Sampling (v2 bandit algorithm) | Planned |

See [Roadmap](docs/06_roadmap.md) and [Changelog](CHANGELOG.md).

---

## Documentation

| Document | Description |
|----------|-------------|
| [Problem Statement](docs/01_problem_statement.md) | The email scam problem |
| [Value Proposition](docs/02_value_proposition.md) | Technical differentiators |
| [Architecture](docs/03_high_level_architecture.md) | System design |
| [Security & Ethics](docs/04_security_guardrails.md) | Defensive principles, GDPR |
| [Evaluation](docs/05_evaluation_methodology.md) | Metrics and validation |
| [Roadmap](docs/06_roadmap.md) | Timeline and milestones |
| [FAQ](docs/07_faq.md) | Common questions |
| [Getting Started](docs/08_getting_started.md) | Full setup tutorial |
| [API Reference](docs/12_api_quick_reference.md) | All endpoints |
| [TAXII Server](docs/16_taxii_server.md) | Automated CTI feed guide |
| [SIEM Integration](docs/15_siem_integration.md) | Enterprise SIEM connector |

---

## License

- **Code**: [MIT License](LICENSE)
- **Documentation**: CC BY-NC-SA 4.0
- **Dataset** (when published): CC BY-NC-SA 4.0

---

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

---

## Contact

| | |
|---|---|
| **Maintainer** | Laurent Giovannoni |
| **LinkedIn** | [linkedin.com/in/giovannonilaurent](https://linkedin.com/in/giovannonilaurent) |
| **Context** | E-MSc Cybersecurity, Master's Thesis |
| **Issues** | [GitHub Issues](../../issues) |
| **Security** | See [SECURITY.md](SECURITY.md) |

---

<p align="center">
  <a href="docs/01_problem_statement.md">Learn More</a> &bull;
  <a href="docs/03_high_level_architecture.md">Architecture</a> &bull;
  <a href="docs/06_roadmap.md">Roadmap</a> &bull;
  <a href="docs/07_faq.md">FAQ</a>
</p>

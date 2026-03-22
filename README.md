# ScamBuster

**A Defensive Engagement & Threat Intelligence Research Laboratory (Email-first)**

![Status](https://img.shields.io/badge/status-active-brightgreen)
![Stack](https://img.shields.io/badge/stack-PHP%208.3%20|%20Symfony%207.2%20|%20PostgreSQL%2015%20|%20LLM-green)
![Tests](https://img.shields.io/badge/tests-1310%20passing-brightgreen)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![CI](https://github.com/laugiov/scambuster/actions/workflows/ci.yml/badge.svg)](https://github.com/laugiov/scambuster/actions/workflows/ci.yml)
[![Docker](https://img.shields.io/badge/Docker-ready-blue.svg)](docker-compose.yml)
[![STIX](https://img.shields.io/badge/STIX-2.1-red.svg)](docs/03_high_level_architecture.md)
[![PRs Welcome](https://img.shields.io/badge/PRs-welcome-brightgreen.svg)](CONTRIBUTING.md)

> **Last updated**: 2026-03-16 | **Data period**: December 2025 - February 2026

ScamBuster turns inbound scam emails into **actionable threat intelligence** through **controlled, policy-driven engagement**.

The project serves defensive security, fraud prevention, and applied research purposes (not offensive use). It extracts IOCs, maps campaigns, measures engagement effectiveness, and exports intelligence in STIX/MISP formats. All workflows are safety-gated, cost-aware, and fully auditable.

This is an academic research project (Master's thesis, E-MSc Cybersecurity) exploring a novel intersection of conversational AI, game theory, and cyber threat intelligence.

---

## The Problem: Email Scams Are High-Volume, and Mostly "Invisible" to Defenders

Email scams operate at massive scale. Most security programs are forced into a **block-and-forget** posture: the message is removed, but the attacker infrastructure, financial rails, and campaign signals remain largely unobserved. Industry estimates and sourced figures are documented in [Problem Statement](docs/01_problem_statement.md).

This creates a structural gap. There is little to no attribution across messages and campaigns, limited visibility into evolving TTPs and infrastructure reuse, and slow feedback loops on what actually works. Most organizations miss opportunities to generate intelligence from real-world interaction with threat actors.

ScamBuster explores this gap by converting scam emails into measurable threat intelligence, safely and at scale.

---

## ScamBuster: From Blocking to Understanding

ScamBuster is a **research laboratory** that transforms email scams into actionable intelligence through controlled AI engagement.

### The Vision: A Scam Observatory

Instead of discarding scam emails, ScamBuster creates an **observatory** that answers critical questions:

| Question | ScamBuster Insight |
|----------|-------------------|
| **What scam types are trending?** | Real-time classification across 12 categories |
| **Which personas maximize engagement?** | Adaptive learning identifies optimal strategies per scam type |
| **What IOCs do scammers reveal?** | Automatic extraction of 34 indicator types |
| **How do campaigns evolve?** | Clustering and attribution over time |
| **What works against different scammers?** | Data-driven optimization, not intuition |

### Three Research Dimensions

```
+---------------------------------------------------------------------+
|                    SCAMBUSTER RESEARCH LABORATORY                    |
+---------------------------------------------------------------------+
|                                                                     |
|  +------------------+  +------------------+  +------------------+   |
|  |  CONVERSATIONAL  |  |   INTELLIGENCE   |  |    ADAPTIVE      |   |
|  |    LABORATORY    |  |    EXTRACTION    |  |    LEARNING      |   |
|  +------------------+  +------------------+  +------------------+   |
|  |                  |  |                  |  |                  |   |
|  | Test which       |  | Analyze how &    |  | Automatically    |   |
|  | personas work    |  | when IOCs are    |  | optimize         |   |
|  | best for each    |  | revealed during  |  | strategies via   |   |
|  | scam type        |  | conversations    |  | reinforcement    |   |
|  |                  |  |                  |  | learning         |   |
|  +------------------+  +------------------+  +------------------+   |
|                                                                     |
+---------------------------------------------------------------------+
```

---

## Pilot Results (February 2026)

### Controlled Live Deployment (60 Days)

| Metric | Value | Notes |
|--------|-------|-------|
| **Unique IOCs per conversation** | 5.34 (deduplicated) | Emails, phones, IBANs, crypto wallets |
| **IOC Precision** | 100% on audited sample (N=107) | vs 44% with regex-only baseline |
| **Persona variance** | 5.5x between best/worst | Data-driven persona optimization |
| **Scammer response rate** | 54% | Indistinguishable from human operators |
| **Cost per IOC** | EUR 0.0002 | Negligible operational expense |
| **System Uptime** | 60 days | Zero incidents, fully automated |
| **Max engagement** | 48.7 hours | Longest sustained interaction |

> **Metrics scope & definitions**
>
> Figures come from a **controlled live deployment** (December 2025 - February 2026).
> Quality metrics are reproducible regardless of deployment scale.
>
> **IOC precision (100%)** = no false positives in audited sample (precision = TP / (TP + FP), N=107 messages).
> Sample-based validation details are documented in [Evaluation Methodology](docs/05_evaluation_methodology.md).

### Validation Summary

Adaptive strategy selection was validated on 2,221 synthetic conversations with statistically significant results (p < 0.001, Cohen's d = 0.37). Full methodology and statistical details are available in [Evaluation Methodology](docs/05_evaluation_methodology.md).

### Key Discoveries

**Strategy Performance Varies Significantly by Scam Type**

The adaptive system discovered that:
- Optimal strategy differs significantly across scam categories
- Human intuition about "best" approaches is often wrong
- Data-driven selection outperforms random assignment

**Campaign Attribution**

From the 60-day deployment, identified **coordinated operations**:
- Shared infrastructure (same IBANs across conversations)
- Common TTPs (message templates, escalation patterns)
- Geographic clustering (phone number prefixes)

---

## How It Works

### Multi-Agent LLM Architecture (5 Agents + 1 Forensic Module)

Five specialized AI agents form the core pipeline, supported by one forensic module:

| Agent | Role | Achievement |
|-------|------|-------------|
| **ScamClassifier** | Categorize incoming scams | 82% auto-classification, 12 types |
| **IocExtractor** | Extract threat indicators | 100% precision on audited sample, 34 IOC types |
| **Generator** | Create contextual responses | +35% IOCs post-IBAN detection |
| **Validator** | Ensure safety & quality | 95% approval rate (PolicyGuard + LLM) |
| **Orchestrator** | Coordinate & optimize costs | <EUR 0.0002/message |

| Forensic Module | Role | Notes |
|-----------------|------|-------|
| **InjectionDetector** | Prompt injection analysis | Two-layer detection (pattern + LLM-as-judge), non-blocking |

### Adaptive Strategy Selection

ScamBuster does not rely on a single fixed "best" conversational approach. Instead, it uses **adaptive strategy selection** to learn, per scam category, which persona maximizes **intelligence yield** under strict safety constraints.

- **Epsilon-greedy**: 80% exploitation / 20% exploration with UCB1 exploration bonus
- **Convergence detection**: 60% single-persona selection share triggers exploitation mode
- **Thompson Sampling** (planned v2): Bayesian, zero hyperparameters, automatic convergence
- Reward function: `0.40*duration + 0.25*iocs_total + 0.25*iocs_sensitive + 0.10*completion`
- 27 personas across 7 archetypes (seniors, business, tech, romance, banking, lottery, generic)

| Aspect | Summary |
|--------|---------|
| Approach | Contextual bandit / adaptive experimentation |
| Context | One policy per scam category (12 types, extensible) |
| Strategy space | 27 personas with tailored system prompts |
| Objectives | Intelligence yield, safety compliance, and cost efficiency |

---

## Value for Stakeholders

### For SOC/CERT Teams

| Capability | Benefit |
|------------|---------|
| **Automated IOC feeds** | STIX 2.1 / MISP-compatible exports |
| **Campaign attribution** | Link individual scams to organized operations |
| **Early warning** | Identify emerging threats before they scale |
| **Reduced analyst workload** | Automated extraction vs manual review |

### For MSSPs

| Capability | Benefit |
|------------|---------|
| **Differentiation** | Proactive TI service vs reactive blocking |
| **Scalability** | One deployment serves multiple clients |
| **ROI demonstration** | Quantifiable intelligence value |

### For Financial Institutions

| Capability | Benefit |
|------------|---------|
| **BEC detection** | Early identification of business email compromise |
| **Account protection** | Report fraudulent accounts to consortium |
| **Fraud prevention** | Intelligence on active money mule networks |

### For Research

| Capability | Benefit |
|------------|---------|
| **Reproducible methodology** | Published protocol for evaluation |
| **Dataset** | Anonymized corpus (planned 2026) |
| **Collaboration** | Open platform for strategy experimentation |

---

## Tech Stack

| Layer | Technology |
|-------|------------|
| **Backend** | PHP 8.3, Symfony 7.2, DDD architecture |
| **Database** | PostgreSQL 15, Redis 7 |
| **LLM** | OpenAI API (GPT-4o-mini, pinned version) |
| **Orchestration** | n8n (self-hosted workflow automation) |
| **Secrets** | HashiCorp Vault |
| **Infrastructure** | Docker, Docker Compose |

---

## Quick Start

```bash
git clone https://github.com/laugiov/scambuster.git
cd scambuster
cp .env.dist .env        # Configure your environment (see below)
make build               # Build Docker images
make upd                 # Start Docker stack (background)
make composer-install    # Install PHP dependencies
make migration           # Create database schema
make fixtures-dev        # Seed reference data + default users
make test                # Run 1077 unit + integration tests
```

**Minimum `.env` configuration** before starting:

| Variable | What to do |
|----------|------------|
| `POSTGRES_PASSWORD` | Choose a password, update `DATABASE_URL` to match |
| `JWT_SECRET` | `openssl rand -base64 64` |
| `LLM_API_KEY` | Your OpenAI API key (from [platform.openai.com](https://platform.openai.com)) |

All other `change-me` values have safe defaults for local development. See `.env.dist` for the full list.

**Default credentials** (created by fixtures):

| Email | Password | Role |
|-------|----------|------|
| `user@example.com` | `Un1que$trongPassword2024` | `ROLE_USER` |
| `admin@example.com` | `Un1que$trongPassword2024` | `ROLE_ADMIN` |

> **Full setup guide**: See [Getting Started](docs/08_getting_started.md) for detailed instructions, n8n workflow setup, Vault configuration, troubleshooting, and the complete Makefile reference.

---

## Project Structure

```
scambuster/
  backend-symfony/         # PHP/Symfony backend (DDD)
    src/
      Domain/              # Entities, value objects, enums, events
      Application/         # Handlers, services, orchestrators
      Infrastructure/      # Doctrine repos, external APIs, listeners
      UI/Http/             # Final controllers (single __invoke)
    tests/                 # PHPUnit (unit, integration, E2E)
    migrations/            # Doctrine migrations + reference data
  n8n/                     # Workflow definitions (JSON)
  infra/                   # Docker configs
  docs/                    # Detailed documentation (10 documents)
```

---

## API Endpoints

### Authentication
- `POST /api/v1/auth/login` -- Obtain JWT
- `POST /api/v1/auth/refresh` -- Refresh JWT
- `POST /api/v1/auth/logout` -- Invalidate refresh token
- `GET  /api/v1/auth/me` -- Current user info

### Conversations & Messages
- `POST/GET/PATCH/DELETE /api/v1/communication/conversation/{id}`
- `POST/GET/PATCH/DELETE /api/v1/communication/message/{id}`
- `GET /api/v1/communication/conversation/{id}/messages`
- `GET /api/v1/communication/conversation/{id}/iocs`

### Adaptive Scambaiting
- `POST /api/v1/scambaiting/select-persona` -- Select optimal persona
- `GET  /api/v1/scambaiting/stats` -- Aggregated performance stats
- `POST /api/v1/scambaiting/conversation/{id}/close` -- Close and update stats

### Attachments & IOCs
- `POST/GET/DELETE /api/v1/communication/attachment/{id}`
- `GET /api/v1/communication/message/{id}/iocs`

### Monitoring
- `GET /api/v1/monitoring/autonomy` -- System health, convergence, kill switch, activity

---

## Testing

1310 automated tests covering:
- **E2E**: Full API flow with real JWT, database, and fixtures
- **Integration**: Service/repository logic, business rules
- **Unit**: Domain logic, value objects, algorithms

```bash
make test              # Unit + integration tests
make endToEndTest      # E2E tests
make testOne q=MyTest  # Run a single test by filter
```

---

## Security & Ethics

ScamBuster is a **defensive research tool**, not an offensive weapon. See [SECURITY.md](SECURITY.md) for the security policy.

Key principles:
- **Inbound-only engagement**: System engages only after scammer initiates contact
- **No unauthorized access**: Never accesses attacker systems
- **Content filtering**: PolicyGuard blocks threats, illegal content, real PII
- **Rate limiting**: Hard limits on conversations, messages, and LLM calls
- **Kill switch**: Immediate halt at workflow, API, database, or infrastructure level
- **GDPR considerations**: Data minimization, 6-month content retention, encryption at rest

Full details in [Security & Guardrails](docs/04_security_guardrails.md).

---

## Project Status

| Phase | Status | Timeline |
|-------|--------|----------|
| **Phase 1**: Multi-agent LLM architecture | ✅ Complete | Oct-Nov 2025 |
| **Phase 2**: Adaptive engagement (epsilon-greedy) | ✅ Complete | Nov-Dec 2025 |
| **Phase 3**: Thompson Sampling | Planned (v2) | -- |
| **Phase 4**: Scale & Dashboards | ✅ Complete | Jan 2026 |
| **Phase 5**: A/B Testing & Validation | ✅ Complete | Jan-Feb 2026 |
| **Phase 6**: Publication & Dataset Release | 🔄 In Progress | Mar 2026 |

See [Roadmap](docs/06_roadmap.md) for detailed milestones.

---

## Documentation

| Document | Description |
|----------|-------------|
| [Problem Statement](docs/01_problem_statement.md) | The $12.5B scam problem in depth |
| [Value Proposition](docs/02_value_proposition.md) | Technical differentiators and ROI |
| [Architecture](docs/03_high_level_architecture.md) | High-level system design |
| [Security & Ethics](docs/04_security_guardrails.md) | Defensive principles, GDPR, safety |
| [Evaluation](docs/05_evaluation_methodology.md) | Metrics, validation, statistical methods |
| [Roadmap](docs/06_roadmap.md) | Timeline and milestones |
| [FAQ](docs/07_faq.md) | Common questions |
| [Getting Started](docs/08_getting_started.md) | Setup, run, test -- full tutorial |
| [DPIA Template](docs/09_dpia_template.md) | Data Protection Impact Assessment template |
| [Threat Model](docs/10_threat_model.md) | T1-T9 threat categories and mitigations |
| [Database Schema](docs/11_database_schema.md) | 21 tables, relationships, column reference |
| [API Quick Reference](docs/12_api_quick_reference.md) | 62 endpoints with curl examples |
| [MISP Integration](docs/13_misp_integration.md) | Connect to MISP, export IOCs, troubleshooting |

---

## Academic Context

### Research Contributions

1. **Methodological**: Reproducible protocol for adaptive honeypot evaluation
2. **Technical**: Multi-agent LLM with double validation pipeline (95% approval vs 60-70% baseline)
3. **Scientific**: Empirically validated adaptive engagement (p < 0.001, N=2,221, Cohen's d = 0.37)
4. **Practical**: Demonstrated efficiency at pilot scale (EUR 0.0002 per IOC, 100% extraction precision)

### Validated Hypotheses (Epsilon-Greedy with UCB1)

| ID | Hypothesis | Result |
|----|------------|--------|
| H1 | Adaptive selection improves engagement duration vs random | Validated (p < 0.001) |
| H2 | Adaptive selection increases IOCs/conversation vs random | Validated (+51.3% median) |
| H3 | Adaptive selection reduces early abandonment | Validated (48.6% -> 36.4%) |
| H4 | Per-scam-type policy converges in <100 sessions | Validated (9/12 types) |

### Planned (v2): Thompson Sampling

Thompson Sampling is planned as a v2 upgrade to the current epsilon-greedy algorithm. It is **not implemented** in the current codebase. Expected improvements: faster convergence, no hyperparameter tuning, automatic exploration-exploitation balance.

### Citation

```bibtex
@master{giovannoni2025scambuster,
  author = {Giovannoni, Laurent},
  title = {ScamBuster: Adaptive Controlled Engagement via Multi-Armed Bandits
           for Automated Threat Intelligence Extraction},
  school = {E-MSc Cybersecurity},
  year = {2025}
}
```

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
| **Issues & Features** | [GitHub Issues](../../issues) |
| **Security** | See [SECURITY.md](SECURITY.md) for responsible disclosure |

---

<p align="center">
  <a href="docs/01_problem_statement.md">Learn More</a> &bull;
  <a href="docs/03_high_level_architecture.md">Architecture</a> &bull;
  <a href="docs/06_roadmap.md">Roadmap</a> &bull;
  <a href="docs/07_faq.md">FAQ</a>
</p>

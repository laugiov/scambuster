<p align="center">
  <img src="frontend-react/public/scambuster_logo_horizontal.svg" alt="ScamBuster" width="500" />
</p>

<p align="center"><strong>Automated Scambaiting Honeypot & Threat Intelligence Platform</strong></p>

![Status](https://img.shields.io/badge/status-active-brightgreen)
![Stack](https://img.shields.io/badge/stack-PHP%208.3%20|%20Symfony%207.4%20|%20PostgreSQL%2015%20|%20LLM-green)
[![codecov](https://codecov.io/gh/laugiov/scambuster/graph/badge.svg?token=4TXL7E2L7W)](https://codecov.io/gh/laugiov/scambuster)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![CI](https://github.com/laugiov/scambuster/actions/workflows/ci.yml/badge.svg)](https://github.com/laugiov/scambuster/actions/workflows/ci.yml)
[![Docker](https://img.shields.io/badge/Docker-ready-blue.svg)](docker-compose.yml)
[![STIX](https://img.shields.io/badge/STIX-2.1-red.svg)](docs/16_taxii_server.md)
[![PRs Welcome](https://img.shields.io/badge/PRs-welcome-brightgreen.svg)](CONTRIBUTING.md)
[![Website](https://img.shields.io/badge/website-scambuster.ai-6f42c1.svg)](https://scambuster.ai)
[![Live Demo](https://img.shields.io/badge/live%20demo-demo.scambuster.ai-brightgreen.svg)](https://demo.scambuster.ai)

> **Website**: [scambuster.ai](https://scambuster.ai) · **Live demo**: [demo.scambuster.ai](https://demo.scambuster.ai)
>
> **Community**: [GitHub Discussions](https://github.com/laugiov/scambuster/discussions) | [Report security issues](SECURITY.md)
>
> **Community-driven**: evaluate it yourself -- run the demo, read the code, form your own view.

<p align="center">
  <img src="frontend-react/public/scambuster_screenshots.gif" alt="ScamBuster Operations Dashboard" width="100%" />
</p>

> **Try the demo** (no install): [demo.scambuster.ai](https://demo.scambuster.ai) -- login: `user@example.com` / `Un1que$trongPassword2024` (same dataset as the local `make demo-up`).
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
| **ScamClassifier** | Categorize incoming scams (13 scam types + an UNKNOWN fallback) + detect language |
| **IocExtractor** | Extract threat indicators (36 IOC types) with contextual enrichment |
| **Generator** | Create persona-driven responses that maximize intelligence yield |
| **Validator** | Ensure safety & quality (PolicyGuard + LLM double validation) |
| **ConversationDirector** | Reason over the whole thread each turn and steer the Generator (tracks intel already given, infers the objective, signals when to stop) |
| **Orchestrator** | Coordinate pipeline, optimize costs, track per-reply traces |
| **InjectionDetector** | Two-layer prompt injection analysis (pattern + LLM-as-judge) |
| **TtpExtractor** | Tag scammer tactics (TTPs) on inbound messages against a closed taxonomy (inbound-only, feature-flagged) |

Every reply passes through **human delay simulation** (configurable cadence, randomized timing, time-of-day awareness) to mimic realistic response patterns.

### Adaptive Strategy Selection

ScamBuster uses **epsilon-greedy with UCB1 exploration** to learn which persona maximizes intelligence yield per scam type. Persona effectiveness is learned per-category from live outcomes rather than fixed by hand.

### Multilingual by Design

Scammers operate in many languages, so ScamBuster does too. Detection rules
(threats, authority impersonation, urgency cues), persona system prompts, and
strategy guidance ship with non-English content on purpose — it is operational
data that lets the honeypot engage scammers in their own language. The codebase
itself (identifiers, comments, logs) is English; the non-English strings you see
in seed data and prompts are intentional, not untranslated code. Add or adapt
languages by editing the persona and detection seed data — no code change.

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

### Threat-Actor Intelligence

First-party intelligence *about the actor*, built from the honeypot's own interactions (no external enrichment) — see the [Threat-Actor Profiling guide](docs/21_threat_actor_profiling.md):

- **Psychological profiling** — a durable per-actor fingerprint: dominant + secondary **Cialdini influence levers** (Authority, Urgency, Scarcity, Secrecy, Reciprocity, Liking, SocialProof), a behavioural narrative, escalation pattern, and victim targeting. Generated offline, surfaced on the cluster detail page, the API, and a `x_scambuster_actor_psych` STIX extension.
- **Fuzzy actor clustering** — conversations link on *canonically equivalent* financial/contact IOCs (ETH wallet case, IBAN/card/phone separators), not just identical values — while genuinely different values (one digit apart) stay separate.
- **Analyst feedback loop** — analysts mark an IOC confirmed / false-positive (`POST /iocs/{id}/feedback`); the verdict overrides export confidence (confirmed → high, false-positive → near-zero).
- **Explicit STIX evidence** — each indicator carries a `sighting` SDO (count, first/last seen, where-sighted), and standard observables also emit `observed-data` + Cyber Observable Objects.

### Scammer TTP Intelligence

ScamBuster also tags the **scammer's tactics, techniques and procedures (TTPs)** on inbound
messages against a closed, 27-entry taxonomy spanning a six-phase scam kill chain (hook,
trust-building, payment-request, escalation, channel-switch, exit). A stimulus is something
**our persona** does; a TTP is something **the scammer** does — the two are kept strictly
separate, and the analytical value is the crossing **stimulus → TTP → IOC**.

- **Inbound-only LLM tagging** — our own replies are never analysed. Each observation carries
  a confidence, a `confirmed` / `review` status (below a configurable threshold, nothing is
  silently dropped), a verbatim evidence quote (**stored internally only**, never in any API
  response or export — consumers see character offsets), and model/prompt provenance.
- **Read APIs** — `GET /conversations/{id}/ttps`, `/clusters/{id}/ttps`, `/ttps`,
  `/ttps/cluster-matrix`, `/ttps/{code}/iocs`, `/iocs/{id}/ttps`; a manual
  `POST /communication/message/{msgId}/extract-ttps` remains as an ops/test surface.
- **Analyst UI** — a cluster TTP panel, a per-conversation stimulus → TTP → IOC elicitation
  timeline with neutral stimulus/causality chips, and a tabbed **TTP Explorer**
  (taxonomy with per-TTP detail pages, phase analytics with an 8-week trend, the
  cluster-overlap playbook matrix, and a read-only review queue with on-demand,
  masked-by-default evidence).
- **CTI export** — one stable STIX 2.1 attack-pattern per TTP (with `kill_chain_phases`),
  `threat-actor uses attack-pattern` relationships and sightings from cluster aggregates, and
  `scambuster:ttp` + MITRE ATT&CK galaxy MISP tags. Evidence text is never exported.
- **Operator tooling** — `scambuster:ttp:backfill` (historical extraction, preview by default,
  budget-capped, idempotent) and `scambuster:ttp:audit-sample` (random-sample CSV for a manual
  precision audit — the only path by which evidence text leaves the database).
- The whole module sits behind the **`TTP_EXTRACTION_ENABLED`** feature flag (default on) and
  fails safe: disabled or failing, it never affects ingestion, IOC extraction or replies.

> **Note on metrics.** The TTP module was added **after** the nine-month production window
> covered by the white paper, so **no published metric applies to it**. Its extraction
> precision is to be established by the manual audit (`scambuster:ttp:audit-sample`); no
> precision figure is claimed until that audit exists.

### Automated Intelligence Feeds

- **STIX 2.1 export** per conversation (indicators + threat-actor + sightings + observed-data + attack-pattern + relationships)
- **TAXII 2.1 server** with delta sync -- IOC indicators enriched with threat-actor attribution
- **MISP Event JSON** export
- Compatible with OpenCTI, MISP, TheHive, Splunk, QRadar, Elastic

See [TAXII Server Guide](docs/16_taxii_server.md) and [API Reference](docs/12_api_quick_reference.md).

---

## Tech Stack

| Layer | Technology |
|-------|------------|
| **Backend** | PHP 8.3, Symfony 7.4, DDD architecture |
| **Database** | PostgreSQL 15, Redis 7 |
| **Frontend** | React 19, TypeScript, TailwindCSS, i18n (EN/FR) |
| **LLM** | Multi-provider: OpenAI, Anthropic, Ollama (full local), Mock (dev) |
| **Orchestration** | n8n (self-hosted) |
| **Secrets** | Environment variables / Docker secrets |
| **Monitoring** | Prometheus metrics, LLM cost tracking, pipeline tracing |
| **Infrastructure** | Docker Compose, GitHub Actions CI |
| **SIEM** | CEF, ECS, JSON -- pluggable connector |

> **Data sovereignty**: Deploy with `LLM_PROVIDER=ollama` for 100% on-premise processing. No data leaves your infrastructure.

---

## Quick Start

```bash
git clone https://github.com/laugiov/scambuster.git
cd scambuster
cp .env.dist .env    # then EDIT it (see below) BEFORE the next step for real use
make quickstart      # one command: build, start, migrate, seed, JWT keys, n8n
make test            # optional: run the test suite
```

> **Configure `.env` before `make quickstart`.** The install registers your honeypot
> mailbox and creates the n8n IMAP credential *from `.env` during the install*. If you
> run `make quickstart` with the default placeholders it boots in **demo mode** —
> live email capture and replies won't work until you fill in `HONEYPOT_IMAP_*`,
> `MAILER_DSN` and `LLM_API_KEY` and re-run. (`make quickstart` warns you if it detects
> placeholders.)

`make quickstart` builds and starts the whole stack, creates and migrates the
database, seeds fixtures and demo data, generates the JWT keys, and configures
n8n — no manual steps. See [QUICKSTART.md](docs/QUICKSTART.md) for the full walkthrough.

**Two ways to deploy** — do it **by hand** with [QUICKSTART.md](docs/QUICKSTART.md),
or hand it to an **AI agent** (Claude Code, Cursor, Copilot, …) with
[AI_DEPLOYMENT.md](docs/AI_DEPLOYMENT.md), a tool-agnostic runbook with the
secret-handling and guardrails an agent needs.

> **Running in production?** `make quickstart` is the local/developer path (dev server,
> demo data). For a real deployment use the self-contained production image and compose
> file: **[Production deployment runbook](docs/runbooks/production-deployment.md)**
> (`docker compose -f docker-compose.prod.yml up -d --build` — nginx + php-fpm, no demo
> data, migrations auto-run). An agent can follow the Production section of
> [AI_DEPLOYMENT.md](docs/AI_DEPLOYMENT.md).

**Before real (non-demo) use, change these in `.env`**:

| Variable | What to do |
|----------|------------|
| `POSTGRES_PASSWORD` | Choose a password, update `DATABASE_URL` to match |
| `JWT_PASSPHRASE` | `openssl rand -hex 32` |
| `LLM_API_KEY` | Your OpenAI API key (or keep `LLM_PROVIDER=mock` for a no-key demo) |

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
  docs/                    # Documentation guides
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

> **Responsible use is the operator's responsibility.** Confirm your deployment is legal where you operate, keep it inbound-only, and never use it to initiate contact, target individuals, harass, or dox. Read the **[Disclaimer & Responsible Use](DISCLAIMER.md)** before deploying.

See [Security & Guardrails](docs/04_security_guardrails.md), [SECURITY.md](SECURITY.md), and [DISCLAIMER.md](DISCLAIMER.md).

---

## Project Status

| Phase | Status |
|-------|--------|
| Multi-agent LLM architecture | Complete |
| Adaptive engagement (epsilon-greedy) | Complete |
| Scale, dashboards & observability | Complete |
| Quality assurance & validation | Complete |
| Rich contextual IOC enrichment | Complete |
| STIX threat-actor export & OpenCTI integration (sightings + observed-data) | Complete |
| Threat-actor clustering (Union-Find on financial IOCs, fuzzy canonical match) | Complete |
| Threat-actor psychological profiling (Cialdini levers) | Complete |
| Analyst IOC feedback loop (confirmed / false-positive → confidence) | Complete |
| Scammer TTP extraction & intelligence (kill-chain taxonomy, STIX attack-patterns) | Complete (post-study — no published metric) |
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
| [Production Deployment](docs/runbooks/production-deployment.md) | Self-contained prod image + compose, tested end to end |
| [API Reference](docs/12_api_quick_reference.md) | All endpoints |
| [TAXII Server](docs/16_taxii_server.md) | Automated CTI feed guide |
| [SIEM Integration](docs/15_siem_integration.md) | Enterprise SIEM connector |
| [MISP Integration](docs/13_misp_integration.md) | MISP export mapping |
| [Enterprise SSO](docs/20_enterprise_sso.md) | OIDC single sign-on |
| [Metrics Catalog](docs/22_metrics_catalog.md) | Metric definitions and provenance |
| [Data Validation](docs/18_data_validation.md) | Audit commands for IOC, cluster, and classification quality |
| [Data Quality Audit](docs/19_data_quality_audit.md) | LLM quality auditor, manual deep audit, and remediation guide |
| [Threat-Actor Profiling](docs/21_threat_actor_profiling.md) | Per-actor psychological + behavioural fingerprint (Cialdini levers) |
| [Reading the Threat-Actor screen](docs/23_reading_the_threat_actor_screen.md) | Field guide to the Cluster Detail page — every indicator explained, with demo talking points |
| [Reading the TTP screens](docs/26_reading_the_ttp_screens.md) | Field guide to the TTP Explorer tabs, per-TTP detail pages, review queue and conversation causality chips |
| [Analyst Feedback](docs/24_analyst_feedback.md) | Human confirm / false-positive verdicts that become authoritative confidence across STIX, TAXII, MISP and CSV feeds |

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
| **Website** | [scambuster.ai](https://scambuster.ai) |
| **Live demo** | [demo.scambuster.ai](https://demo.scambuster.ai) |
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

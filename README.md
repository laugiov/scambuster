<p align="center">
  <img src="frontend-react/public/scambuster_logo_horizontal.svg" alt="ScamBuster" width="500" />
</p>

<p align="center"><strong>Automated Scambaiting Honeypot & Threat Intelligence Platform</strong></p>

[![CI](https://github.com/laugiov/scambuster/actions/workflows/ci.yml/badge.svg)](https://github.com/laugiov/scambuster/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/laugiov/scambuster/graph/badge.svg?token=4TXL7E2L7W)](https://codecov.io/gh/laugiov/scambuster)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Live Demo](https://img.shields.io/badge/live%20demo-demo.scambuster.ai-brightgreen.svg)](https://demo.scambuster.ai)

<p align="center">
  <img src="frontend-react/public/scambuster_screenshots.gif" alt="ScamBuster Operations Dashboard" width="100%" />
</p>

## Try it

**Hosted demo** — [demo.scambuster.ai](https://demo.scambuster.ai), login `user@example.com` / `Un1que$trongPassword2024`.

**Local demo** — `make demo-up`, then http://localhost:3002: no API key, no account, no mailbox ([guide](docs/DEMO.md)).

Most security programs block and forget: the mail goes, the attacker's infrastructure and money rails stay unobserved. ScamBuster engages instead, inbound-only and under policy, turning the exchange into IOCs, actor profiles and tagged tactics ([why](docs/01_problem_statement.md)).

ScamBuster was presented at Black Hat USA 2026 in the Human Factor track.

## How it works

| Agent | Role |
|-------|------|
| **ScamClassifier** | Categorizes the scam (13 types), detects language |
| **IocExtractor** | Extracts 36 IOC types with context |
| **Generator** | Writes the persona-driven reply |
| **Validator** | Safety and quality gate (PolicyGuard + LLM) |
| **ConversationDirector** | Reads the thread, steers each turn |
| **Orchestrator** | Runs the pipeline, tracks cost and traces |
| **InjectionDetector** | Two-layer prompt injection analysis |
| **TtpExtractor** | Tags scammer tactics, closed taxonomy |

Persona choice is adaptive: epsilon-greedy with UCB1 learns which persona yields most per scam type.

Multilingual by design: detection rules and persona prompts carry non-English content on purpose, so it answers scammers in their own language ([details](docs/25_prompt_customization.md)).

## What it produces

- **STIX 2.1 bundle** per conversation — indicators, threat-actor, sightings, observed-data, attack-patterns, relationships
- **TAXII 2.1 server** with delta sync
- **MISP Event JSON** export
- **SIEM export** in CEF, ECS or JSON, file or syslog

Standards, not per-vendor connectors. **Verified end to end against OpenCTI** ([what lands where](docs/11_opencti_integration.md)); the other three follow the same standards, untested live.

Each conversation produces a threat-actor:

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

Actors carry ATT&CK mapping, `indicates` relationships to every IOC, and a psychological profile ([profiling](docs/21_threat_actor_profiling.md), [TTPs](docs/27_ttp_intelligence.md), [API](docs/12_api_quick_reference.md)).

## Quick start

```bash
git clone https://github.com/laugiov/scambuster.git
cd scambuster
cp .env.dist .env    # edit it first, see below
make quickstart      # build, start, migrate, seed, JWT keys, n8n
```

Fill these 4 in `.env` before real use:

| Variable | What to do |
|----------|------------|
| `LLM_API_KEY` | OpenAI key (or `LLM_PROVIDER=mock`, no key) |
| `HONEYPOT_IMAP_USER` | Honeypot mailbox (IMAP, receives scams) |
| `HONEYPOT_IMAP_PASSWORD` | App password, not the account password |
| `MAILER_DSN` | SMTP for replies, `@` written as `%40` |

Left as placeholders, it boots in demo mode and says so.
[Quickstart](docs/QUICKSTART.md) · [demo](docs/DEMO.md) · [AI install](docs/AI_DEPLOYMENT.md) · [production](docs/runbooks/production-deployment.md) · [architecture](docs/03_high_level_architecture.md) · [roadmap](docs/06_roadmap.md) · [all docs](docs/README.md).

## Limitations

- **Email only, inbound only.** No SMS, chat or voice; it never writes first.
- **Not a control.** It blocks and filters nothing; it does not replace mail security.
- **One verified export path.** OpenCTI; TAXII, MISP and SIEM are untested live.
- **No published metric for the TTP module** — it postdates the evaluation window; precision awaits an operator-run audit.
- **Read-only review.** The TTP queue is triage only; campaign attribution stays manual.
- **Output depends on the model.** Mock replies are synthetic; personas and rules are seed data you tune; demo data is seeded, not live output.
- **One host, your risk.** Docker Compose on one machine, no HA; local legality is yours to establish.

## Security & ethics

ScamBuster is a defensive research tool, not an offensive weapon.

- **Inbound-only**: engages only after the scammer makes contact
- **No unauthorized access**: never accesses attacker systems
- **Content filtering**: PolicyGuard blocks threats, illegal content, real PII
- **Rate limiting**: hard limits on conversations, messages and LLM calls
- **Kill switch**: halt at workflow, API, database or infrastructure level
- **GDPR**: data minimization, retention policies, encryption at rest

> **Responsible use is the operator's responsibility.** Confirm your deployment is legal where you operate, keep it inbound-only, and never use it to initiate contact, target individuals, harass, or dox. Read the **[Disclaimer & Responsible Use](DISCLAIMER.md)** before deploying.

More: [Security & Guardrails](docs/04_security_guardrails.md), [SECURITY.md](SECURITY.md).

## License

Code [MIT](LICENSE); docs and dataset CC BY-NC-SA 4.0.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md), [Discussions](https://github.com/laugiov/scambuster/discussions) and [Issues](../../issues).

## Contact

Laurent Giovannoni — [scambuster.ai](https://scambuster.ai) · [LinkedIn](https://linkedin.com/in/giovannonilaurent) · [SECURITY.md](SECURITY.md).

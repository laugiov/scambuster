# ScamBuster Documentation

The documentation entry point. Start with the [project README](../README.md) for what
ScamBuster is and how to see it running; everything below is the detail.

Community-driven: evaluate it yourself -- run the demo, read the code, form your own view.

## Start here

| Document | Description |
|----------|-------------|
| [Quickstart](QUICKSTART.md) | Install in under 5 minutes, with troubleshooting |
| [Demo Mode](DEMO.md) | Keyless local demo (`make demo-up`) and what it does not do |
| [AI Deployment](AI_DEPLOYMENT.md) | Hand the install to an AI agent (Claude Code, Cursor, Copilot, …) |
| [Getting Started](08_getting_started.md) | Full setup tutorial |
| [Production Deployment](runbooks/production-deployment.md) | Self-contained prod image + compose, tested end to end |

## Concepts

| Document | Description |
|----------|-------------|
| [Problem Statement](01_problem_statement.md) | The email scam problem |
| [Value Proposition](02_value_proposition.md) | Technical differentiators |
| [Architecture](03_high_level_architecture.md) | System design, tech stack, repository layout |
| [Security & Ethics](04_security_guardrails.md) | Defensive principles, GDPR |
| [Evaluation](05_evaluation_methodology.md) | Metrics and validation |
| [Roadmap](06_roadmap.md) | Timeline and milestones |
| [FAQ](07_faq.md) | Common questions |
| [Changelog](../CHANGELOG.md) | Release history |

## Intelligence

| Document | Description |
|----------|-------------|
| [Threat-Actor Profiling](21_threat_actor_profiling.md) | Per-actor psychological + behavioural fingerprint (Cialdini levers) |
| [TTP Intelligence](27_ttp_intelligence.md) | Scammer tactics: taxonomy, extraction, APIs, export, feature flag |
| [Analyst Feedback](24_analyst_feedback.md) | Human confirm / false-positive verdicts that become authoritative confidence across STIX, TAXII, MISP and CSV feeds |
| [Reading the Threat-Actor screen](23_reading_the_threat_actor_screen.md) | Field guide to the Cluster Detail page -- every indicator explained, with demo talking points |
| [Reading the TTP screens](26_reading_the_ttp_screens.md) | Field guide to the TTP Explorer tabs, per-TTP detail pages, review queue and conversation causality chips |
| [Metrics Catalog](22_metrics_catalog.md) | Metric definitions and provenance |
| [Data Validation](18_data_validation.md) | Audit commands for IOC, cluster, and classification quality |
| [Data Quality Audit](19_data_quality_audit.md) | LLM quality auditor, manual deep audit, and remediation guide |
| [Standards track](standards-track.md) | Status of the TTP taxonomy as a published standard |

## Integration

| Document | Description |
|----------|-------------|
| [API Reference](12_api_quick_reference.md) | All endpoints |
| [TAXII Server](16_taxii_server.md) | Automated CTI feed guide |
| [OpenCTI Integration](11_opencti_integration.md) | Wire OpenCTI to the feed: what lands where, and the traps |
| [SIEM Integration](15_siem_integration.md) | Enterprise SIEM connector |
| [MISP Integration](13_misp_integration.md) | MISP export mapping |
| [Enterprise SSO](20_enterprise_sso.md) | OIDC single sign-on |
| [Email Provider Setup](17_email_provider_setup.md) | IMAP/SMTP configuration per provider |

## Operations, compliance and contributing

| Document | Description |
|----------|-------------|
| [Prompt Customization](25_prompt_customization.md) | Adapt prompts, personas and languages without editing code |
| [Key Management](14_key_management.md) | JWT and audit key handling |
| [Threat Model](10_threat_model.md) | What we defend against, and what we do not |
| [DPIA Template](09_dpia_template.md) | Data protection impact assessment starting point |
| [Compliance pack](compliance/README.md) | GDPR record of processing, risk register, policies |
| [Runbooks](runbooks/) | Production deployment, incident response, key rotation, RACI |
| [Factory pipelines](factory/README.md) | How changes are specced, gated and shipped |
| [Contributing](../CONTRIBUTING.md) | How to contribute |
| [Disclaimer & Responsible Use](../DISCLAIMER.md) | Read before deploying |
| [Security policy](../SECURITY.md) | Reporting a vulnerability |

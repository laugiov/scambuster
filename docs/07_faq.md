# Frequently Asked Questions

## General Questions

### What is ScamBuster?

ScamBuster is an **adaptive conversational honeypot** that engages email scammers using AI-generated personas to extract threat intelligence (IOCs). Unlike passive honeypots, ScamBuster actively engages scammers and learns which strategies work best for each type of scam.

### Is this an open-source project?

**Yes.** This repository contains the complete ScamBuster source code, including backend, frontend, workflows, and tests. It is released under the MIT license.

### Can I get access to the code?

The complete source code is available on GitHub under MIT License.

### What's the current project status?

- **Foundation + Adaptive V1**: ✅ Complete
- **Scale + Optimization**: ✅ Complete
- **A/B Validation**: ✅ Complete
- **Publication & Open Source**: ✅ Complete
- **Thompson Sampling**: Planned (v2 roadmap)

---

## Technical Questions

### What technology stack does ScamBuster use?

| Layer | Technology |
|-------|------------|
| **Backend** | PHP 8.3, Symfony 7.4 |
| **Architecture** | Domain-Driven Design (DDD) |
| **Database** | PostgreSQL 15, Redis 7 |
| **LLM** | Multi-provider: OpenAI, Anthropic, Ollama (local), Mock. Switch via `LLM_PROVIDER` env var |
| **Orchestration** | n8n (workflow automation) |
| **Infrastructure** | Docker, Docker Compose |
| **CI/CD** | GitHub Actions |
| **Secrets** | Dedicated secrets management |

### Why PHP / Symfony?

Symfony 7.4 is the most mature PHP framework for Domain-Driven Design and hexagonal architecture. The same stack powers MISP -- the world's most deployed threat intelligence sharing platform (15,000+ instances). PHP 8.3 with strict types, enums, readonly properties, and PHPStan level 8 bleeding edge provides type safety comparable to statically-typed languages. The codebase has an extensive automated test suite and zero PHPStan errors.

### Can I run ScamBuster without sending data to OpenAI?

**Yes.** Set `LLM_PROVIDER=ollama` in your `.env` file and run a local model (Mistral, Llama 3, Phi). All LLM processing stays on your infrastructure. No data is sent to any external API. This is the recommended configuration for regulated environments (NIS2, DORA, government).

### How does the LLM architecture work?

ScamBuster uses **eight specialized agents**:

1. **ScamClassifier**: Categorizes incoming scams (13 scam types + an UNKNOWN fallback)
2. **IocExtractor**: Extracts indicators (36 IOC types, hybrid regex + LLM)
3. **Generator**: Creates contextual responses
4. **Validator**: Ensures safety and quality (PolicyGuard + LLM scoring)
5. **ConversationDirector**: Steers the Generator each turn -- tracks what the correspondent has already revealed (so the persona never re-asks), infers the extraction objective from the scam's mechanics, and signals when an exchange is no longer productive so it can be closed
6. **InjectionDetector**: Analyzes inbound messages for prompt-injection attempts (forensic, non-blocking -- it observes and stores, it never blocks)
7. **Orchestrator**: Coordinates agents and optimizes costs
8. **TtpExtractor**: Tags scammer tactics (TTPs) on inbound messages against a closed 27-entry ATT&CK-aligned taxonomy (inbound-only, feature-flagged)

Each agent has a single responsibility and can be optimized independently.

### What's the difference between the current algorithm and Thompson Sampling?

| Aspect | ε-Greedy + UCB1 (current) | Thompson Sampling (planned v2) |
|--------|---------------------------|-------------------------------|
| **Exploration** | UCB1 bonus + 20% random | Probability-weighted by uncertainty |
| **Parameters** | ε=0.20, C=0.5, convergence=60% | None (auto-adaptive) |
| **Bad performers** | UCB1 reduces over time | Naturally eliminated |
| **Convergence** | Detection built-in (60% dominance threshold) | Expected: faster |
| **Status** | Implemented (production) | Planned (roadmap v2) |

### How does IOC extraction work?

ScamBuster uses a **hybrid extraction approach**:

- **Hybrid approach**: Regex patterns + LLM understanding
- **36 IOC types**: emails, phones, IBANs, crypto wallets, URLs, etc.
- **Contextual extraction**: the LLM understands when text is an IOC vs normal content
- **Deterministic definition**: precision = TP / (TP + FP), evaluated on audited samples via the reproducible benchmark suite (see [Evaluation Methodology](05_evaluation_methodology.md))

### What scam types are supported?

Currently 13 scam types (plus an UNKNOWN fallback):

| Type | Description |
|------|-------------|
| UNKNOWN | Unclassified |
| PHISHING | Generic phishing |
| PHISH_CREDENTIALS | Password/login theft |
| PHISH_MALWARE | Malware delivery via phishing |
| INVOICE_FRAUD | Fake invoices / BEC |
| ROMANCE | Dating/emotional manipulation |
| TECH_SUPPORT | Microsoft/Apple impersonation |
| CEO_FRAUD | Executive impersonation |
| INVESTMENT | Crypto/stock pump-and-dump |
| LOTTERY | Fake winnings |
| JOB_OFFER | Employment scams |
| CHARITY | Disaster relief fraud |
| ADVANCE_FEE_419 | Advance-fee / 419 scams |
| COLD_SERVICE_SPAM | Unsolicited cold service outreach (SEO, web/app dev, marketing) / fake-vendor advance-fee-for-services |

---

## Business Questions

### Who is the target user?

| User Type | Use Case |
|-----------|----------|
| **SOC/CERT Teams** | Automated threat intelligence feeds |
| **MSSPs** | Value-added security services |
| **Financial Institutions** | BEC and fraud early warning |
| **Telecoms** | Scam phone number identification |
| **Law Enforcement** | IOC intelligence sharing |
| **Researchers** | Reproducible evaluation methodology |

### What's the ROI?

ScamBuster runs on lightweight LLM models with a configurable monthly budget cap (default $50/month). For external context, the Ponemon Institute estimates $137 per phishing incident handled manually. A live deployment can measure its own cost-per-IOC and engagement metrics using the built-in LLM Cost Monitor, Impact dashboard, and [metrics catalog](22_metrics_catalog.md).

### How much does it cost to operate?

| Component | Cost |
|-----------|------|
| **Infrastructure** | Existing Docker host |
| **Budget cap** | $50/month (configurable hard limit, enforced at the orchestrator) |

Actual LLM spend depends on inbound volume and the selected model. It is tracked live in the LLM Cost Monitor rather than asserted as a fixed figure here.

### Is there a commercial offering?

Currently exploring:

- **Pilot programs**: Time-boxed evaluation for enterprises
- **IOC feeds**: Subscription or per-IOC pricing
- **Consulting**: Implementation and integration support

Contact us to discuss your needs.

---

## Security & Ethics Questions

### Is this legal?

ScamBuster is designed as a **defensive research and fraud-prevention system**, but this documentation does **not** constitute legal advice.

In typical EU contexts, a deployment may rely on (among other options):

- **GDPR Article 6(1)(f)** (legitimate interest) for security research and fraud prevention, subject to a documented balancing test
- A strictly **defensive posture** (no intrusion, no exploitation, no "hack-back")
- A controlled scope (honeypot-controlled mailboxes, governance, logging, retention)

A real-world deployment should include a **scope-specific DPIA** and legal review, because obligations depend on jurisdiction, data flows, and operational context.

> **Important**: Legal review is recommended before any deployment. Compliance requirements vary by jurisdiction and use case.

### Do you interact with real scammers?

Yes, in a **controlled, sandboxed environment** with strict safeguards:

- Rate limiting (max 50 new conversations/day)
- Content filtering (PolicyGuard + LLM Validator)
- Kill switch (immediate halt capability)
- Full audit trail (all actions logged)

### What data do you collect?

| Data Type | Collected | Purpose |
|-----------|-----------|---------|
| Scammer emails | Yes | Engagement |
| Scammer-provided IOCs | Yes | Intelligence |
| Conversation transcripts | Yes | Analysis |
| Victim data | **No** (redacted if detected) | Privacy |
| System credentials | **No** | Security |

### How is data protected?

| Control | Implementation |
|---------|----------------|
| **Encryption at rest** | Infrastructure-layer encryption (volume/disk); optional field-level for sensitive values |
| **Encryption in transit** | TLS 1.2+; TLS 1.3 where supported |
| **Access control** | Token-based auth + RBAC + network restrictions |
| **Secrets management** | Dedicated secrets store |
| **Data retention** | Content: 6 months max; audit metadata: 12 months (see guardrails) |

### Could scammers use this system?

The full source code is open-source (MIT License), but the system includes multiple layers of safeguards that prevent misuse:

- **PolicyGuard**: Content filtering rejects harmful or off-topic outputs
- **Double validation**: LLM Validator ensures safety before any reply is sent
- **Rate limiting**: Caps on conversations and messages per day
- **Kill switch**: Immediate halt capability for operators
- **Audit trail**: Every action is logged for accountability

---

## Research Questions

### What's the academic contribution?

1. **Methodological**: Reproducible protocol for adaptive honeypot evaluation
2. **Technical**: Multi-agent LLM architecture with a two-layer validation pipeline
3. **Scientific**: Adaptive scambaiting via contextual multi-armed bandits
4. **Practical**: Open-source implementation with an automated evaluation harness

### Where will this be published?

Publication in peer-reviewed venues is planned. Details will be announced after acceptance.

### Will there be a public dataset?

Yes, an anonymized dataset is planned. It is expected to include:

- Anonymized conversation transcripts and messages
- Extracted IOCs (sensitive values hashed)
- Metadata (scam types, personas, rewards)
- License: CC BY-NC-SA 4.0

### How can I cite this work?

```bibtex
@mastersthesis{giovannoni2025scambuster,
  author = {Giovannoni, Laurent},
  title = {ScamBuster: Adaptive Scambaiting via Multi-Armed Bandits
           for Automated Threat Intelligence Extraction},
  school = {E-MSc Cybersecurity},
  year = {2025},
  note = {E-MSc Cybersecurity thesis}
}
```

---

## Collaboration Questions

### How can I get involved?

| Interest | Path |
|----------|------|
| **Research collaboration** | Contact for dataset sharing, methodology validation |
| **Technical contribution** | Open-source components (MIT License, this repository) |
| **Pilot program** | Enterprise evaluation (time-boxed) |
| **Hiring** | Looking for security researchers, ML engineers |
| **Advisory** | Strategic guidance welcome |

### What kind of partnerships are you seeking?

| Partner Type | Collaboration |
|--------------|---------------|
| **SOC/MSSP** | Pilot integration, feedback |
| **Financial institutions** | BEC intelligence sharing |
| **CERTs/ISACs** | IOC sharing frameworks |
| **Universities** | Joint research, student projects |
| **Security vendors** | SIEM/SOAR integration |

### How do I request a demo?

Contact via:

- Laurent Giovannoni via github message
- **LinkedIn**: [linkedin.com/in/giovannonilaurent](https://linkedin.com/in/giovannonilaurent)

Include:
- Your context (role, organization)
- What you're looking for (demo, pilot, partnership)
- Any constraints (industry, compliance, timeline)

---

## Still Have Questions?

If your question isn't answered here, please [contact us](../README.md#contact). We're happy to discuss:

- Technical architecture details
- Research methodology
- Partnership opportunities
- Custom evaluation criteria

---

[← Back to Main](../README.md)

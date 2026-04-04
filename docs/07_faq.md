# Frequently Asked Questions

## General Questions

### What is ScamBuster?

ScamBuster is an **adaptive conversational honeypot** that engages email scammers using AI-generated personas to extract threat intelligence (IOCs). Unlike passive honeypots, ScamBuster actively engages scammers and learns which strategies work best for each type of scam.

### Is this an open-source project?

**Yes.** This repository contains the complete ScamBuster source code, including backend, frontend, workflows, and tests. It is released under the MIT license.

### Can I get access to the code?

The complete source code is available on GitHub under MIT License.

### What's the current project status?

- **Phase 1-2 (Foundation + Adaptive V1)**: ✅ Complete
- **Phase 3 (Thompson Sampling)**: Planned (v2 roadmap)
- **Phase 4 (Scale & Dashboards)**: ✅ Complete
- **Phase 5 (A/B Validation)**: ✅ Complete
- **Phase 6 (Publication & Dataset Release)**: In Progress

---

## Technical Questions

### What technology stack does ScamBuster use?

| Layer | Technology |
|-------|------------|
| **Backend** | PHP 8.3, Symfony 7.2 |
| **Architecture** | Domain-Driven Design (DDD) |
| **Database** | PostgreSQL 15, Redis 7 |
| **LLM** | Multi-provider: OpenAI, Anthropic, Ollama (local), Mock. Switch via `LLM_PROVIDER` env var |
| **Orchestration** | n8n (workflow automation) |
| **Infrastructure** | Docker, Docker Compose |
| **CI/CD** | GitHub Actions |
| **Secrets** | Dedicated secrets management |

### Why PHP / Symfony?

Symfony 7.2 is the most mature PHP framework for Domain-Driven Design and hexagonal architecture. The same stack powers MISP -- the world's most deployed threat intelligence sharing platform (15,000+ instances). PHP 8.3 with strict types, enums, readonly properties, and PHPStan level 6 bleeding edge provides type safety comparable to statically-typed languages. The codebase has 2140 tests (1.16:1 test/code LOC ratio) and zero PHPStan errors.

### Can I run ScamBuster without sending data to OpenAI?

**Yes.** Set `LLM_PROVIDER=ollama` in your `.env` file and run a local model (Mistral, Llama 3, Phi). All LLM processing stays on your infrastructure. No data is sent to any external API. This is the recommended configuration for regulated environments (NIS2, DORA, government).

### How does the LLM architecture work?

ScamBuster uses **five specialized agents** supported by one forensic module:

1. **ScamClassifier**: Categorizes incoming scams (13 types)
2. **IocExtractor**: Extracts indicators with 100% precision on audited sample
3. **Generator**: Creates contextual responses
4. **Validator**: Ensures safety and quality (100% first-attempt approval)
5. **Orchestrator**: Coordinates agents and optimizes costs

Additionally, the **InjectionDetector** forensic module analyzes inbound messages for prompt injection attempts (non-blocking, results stored for research).

Each agent has a single responsibility and can be optimized independently.

### What's the difference between the current algorithm and Thompson Sampling?

| Aspect | ε-Greedy + UCB1 (current) | Thompson Sampling (planned v2) |
|--------|---------------------------|-------------------------------|
| **Exploration** | UCB1 bonus + 20% random | Probability-weighted by uncertainty |
| **Parameters** | ε=0.20, C=0.5, convergence=60% | None (auto-adaptive) |
| **Bad performers** | UCB1 reduces over time | Naturally eliminated |
| **Convergence** | 9/13 types in <100 sessions | Expected: faster |
| **Status** | Validated (production) | Planned (roadmap v2) |

### How accurate is IOC extraction?

**100% precision on audited sample** (no false positives observed; N=107 messages, precision = TP / (TP + FP)). This is achieved through:

- **Hybrid approach**: Regex patterns + LLM understanding
- **34 IOC types**: emails, phones, IBANs, crypto wallets, URLs, etc.
- **Contextual extraction**: LLM understands when text is an IOC vs normal content
- **Pilot results**: Multiple unique IOCs per conversation (deduplicated), high precision on audited samples

Compared to regex-only approaches (44% precision), this is a 2.3× improvement.

### What scam types are supported?

Currently 13 scam types:

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

---

## Business Questions

### Who is the target user?

| User Type | Use Case |
|-----------|----------|
| **SOC/CERT Teams** | Automated threat intelligence feeds |
| **MSSPs** | Value-added security services |
| **Financial Institutions** | BEC and fraud early warning |
| **Telecoms** | Scam phone number identification |
| **Law Enforcement** | Campaign attribution |
| **Researchers** | Reproducible evaluation methodology |

### What's the ROI?

Based on February 2026 pilot data:

| Metric | Value |
|--------|-------|
| **Unique IOCs per conversation** | Multiple unique IOCs per conversation (deduplicated) |
| **IOC Precision** | High precision on audited samples |
| **Cost per IOC** | Low cost per IOC (with lightweight models) |
| **Persona variance** | 5.5x best vs worst |

With lightweight models, the cost per IOC is orders of magnitude cheaper than the $137 per phishing incident (Ponemon). The cost efficiency is substantial.

### How much does it cost to operate?

| Component | Cost |
|-----------|------|
| **LLM API** | ~€0.0002/message |
| **Infrastructure** | Existing Docker host |
| **Total actual** | Low cost per IOC (with lightweight models) |
| **Hard limit** | €50/month configured |

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

The operational code is **private** specifically to prevent misuse. This public repository contains no:

- Prompts or persona libraries
- Automation workflows
- Operational playbooks
- Infrastructure details

Access requires NDA and responsible-use agreement.

---

## Research Questions

### What's the academic contribution?

1. **Methodological**: Reproducible protocol for adaptive honeypot evaluation
2. **Technical**: Multi-agent LLM with double validation (100% first-attempt approval vs 60-70% baseline)
3. **Scientific**: Empirically validated adaptive scambaiting (p < 0.001, N=2,221)
4. **Practical**: Demonstrated efficiency at pilot scale

### Where will this be published?

Target venues for submission (peer review pending, acceptance not guaranteed):

- **ACSAC 2026** (Annual Computer Security Applications Conference)
- **NDSS 2027** (Network and Distributed System Security Symposium)

### Will there be a public dataset?

Yes. In progress (Q1 2026):

- 600+ anonymized conversations
- 5,000+ messages
- 2,000+ IOCs (sensitive values hashed)
- Metadata (scam types, personas, rewards)
- License: CC BY-NC-SA 4.0

### How can I cite this work?

```bibtex
@master{giovannoni2025scambuster,
  author = {Giovannoni, Laurent},
  title = {ScamBuster: Adaptive Scambaiting via Multi-Armed Bandits
           for Automated Threat Intelligence Extraction},
  school = {E-MSc Cybersecurity},
  year = {2025},
  note = {Target venues: ACSAC 2026, NDSS 2027}
}
```

---

## Collaboration Questions

### How can I get involved?

| Interest | Path |
|----------|------|
| **Research collaboration** | Contact for dataset sharing, methodology validation |
| **Technical contribution** | Open-source components coming Q1 2026 |
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

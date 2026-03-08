# Frequently Asked Questions

## General Questions

### What is ScamBuster?

ScamBuster is an **adaptive conversational honeypot** that engages email scammers using AI-generated personas to extract threat intelligence (IOCs). Unlike passive honeypots, ScamBuster actively engages scammers and learns which strategies work best for each type of scam.

### Is this an open-source project?

**Not in its current form.** This repository is a public preview containing documentation only. The operational code is private for security reasons. Some components (IOC normalization utilities, STIX mapping helpers) may be open-sourced in February 2026.

### Can I get access to the code?

Yes—for **serious interest** (demo, pilot, hiring, partnership). See the [Contact section](../README.md#contact) to request access. Private access may require an NDA and/or responsible-use agreement.

### What's the current project status?

- **Phase 1-2 (Foundation + Adaptive V1)**: ✅ Complete
- **Phase 3 (Thompson Sampling)**: ✅ Feature-complete (rollout in progress, December 2025)
- **Phase 4 (Scale & Dashboards)**: 🔄 In Progress (December 2025)
- **Phase 5 (A/B Validation)**: 📅 Planned (January 2026)
- **Phase 6 (Publication)**: 📅 Planned (February 2026)

---

## Technical Questions

### What technology stack does ScamBuster use?

| Layer | Technology |
|-------|------------|
| **Backend** | PHP 8.3, Symfony 7 |
| **Architecture** | Domain-Driven Design (DDD) |
| **Database** | PostgreSQL 15, Redis |
| **LLM** | GPT-4o-mini (OpenAI API) |
| **Orchestration** | n8n (workflow automation) |
| **Infrastructure** | Docker |
| **CI/CD** | GitLab CI |
| **Secrets** | Dedicated secrets management |

### How does the LLM architecture work?

ScamBuster uses **five specialized agents**:

1. **ScamClassifier**: Categorizes incoming scams (13 types)
2. **IocExtractor**: Extracts indicators with 100% precision on audited sample
3. **Generator**: Creates contextual responses
4. **Validator**: Ensures safety and quality (95% approval rate)
5. **Orchestrator**: Coordinates agents and optimizes costs

Each agent has a single responsibility and can be optimized independently.

### What's the difference between ε-greedy and Thompson Sampling?

| Aspect | ε-Greedy (V1) | Thompson Sampling (V2) |
|--------|---------------|------------------------|
| **Exploration** | Random 20% of time | Probability-weighted by uncertainty |
| **Parameters** | ε = 0.20 (fixed) | None (auto-adaptive) |
| **Bad performers** | Keep testing | Naturally eliminated |
| **Convergence** | ~200 sessions | ~100 sessions expected |
| **Status** | ✅ Validated | 🔄 In development |

### How accurate is IOC extraction?

**100% precision on audited sample** (no false positives observed; N=107 messages, precision = TP / (TP + FP)). This is achieved through:

- **Hybrid approach**: Regex patterns + LLM understanding
- **34 IOC types**: emails, phones, IBANs, crypto wallets, URLs, etc.
- **Contextual extraction**: LLM understands when text is an IOC vs normal content
- **Pilot scale**: +20K IOCs extracted from +1K conversations

Compared to regex-only approaches (44% precision), this is a 2.3× improvement.

### What scam types are supported?

Currently 13 scam types:

| Type | Description |
|------|-------------|
| ADVANCE_FEE_419 | Nigerian prince / inheritance |
| PHISH_CREDENTIALS | Password/login theft |
| FAKE_INVOICE | Business email compromise |
| TECH_SUPPORT | Microsoft/Apple impersonation |
| ROMANCE | Dating/emotional manipulation |
| INVESTMENT | Crypto/stock pump-and-dump |
| LOTTERY | Fake winnings |
| AUTHORITY_IMPERSONATION | Government/police/tax authority |
| RENTAL | Fake property listings |
| JOB_OFFER | Employment scams |
| CHARITY | Disaster relief fraud |
| RESHIPPING | Package forwarding |
| OTHER | Unclassified |

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

Based on February 2026 pilot data (60 days):

| Metric | Value |
|--------|-------|
| **Operational cost** | €5.2 |
| **Conversations** | +1K |
| **IOCs captured** | +20K |
| **Cost per IOC** | €0.0002 |
| **ROI** | ~7,700× |

Even at conservative estimates (€2/IOC), ROI exceeds 7,700×.

> **Note**: Value estimates reflect **internal defensive operational value** (SOC workflow efficiency, faster blocking/reporting, campaign correlation). These are not claims about commercial pricing or resale markets.

### How much does it cost to operate?

| Component | Cost |
|-----------|------|
| **LLM API** | ~€0.0002/message |
| **Infrastructure** | Existing Docker host |
| **Total actual** | €5.2 (+1K conversations) |
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

Yes—in a **controlled, sandboxed environment** with strict safeguards:

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
2. **Technical**: Multi-agent LLM with double validation (95% vs 60-70% baseline)
3. **Scientific**: Empirically validated adaptive scambaiting (p < 0.001, N=2,221)
4. **Practical**: Demonstrated efficiency at pilot scale

### Where will this be published?

Target venues for submission (peer review pending, acceptance not guaranteed):

- **ACSAC 2026** (Annual Computer Security Applications Conference)
- **NDSS 2027** (Network and Distributed System Security Symposium)

### Will there be a public dataset?

Yes. Planned for **February 2026**:

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

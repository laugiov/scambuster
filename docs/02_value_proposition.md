# Value Proposition: What Makes ScamBuster Different

## Executive Summary

ScamBuster is a **novel adaptive conversational honeypot** that combines:

1. **Research laboratory approach**: Not just blocking scams, but understanding them
2. **Multi-agent LLM architecture** for realistic, scalable engagement
3. **Hybrid IOC extraction** combining regex patterns with LLM understanding (36 IOC types)
4. **Reinforcement learning** to automatically optimize strategies per scam type
5. **Standards-based export**: STIX 2.1, TAXII 2.1, and MISP for SOC/CERT integration

---

## The Laboratory Vision

### From Reactive to Proactive Intelligence

Traditional security treats scam emails as disposable threats:

```
Scam Email → Block → Forget → No intelligence gained
```

ScamBuster transforms them into research opportunities:

```
Scam Email → Engage → Extract → Analyze → Learn → Share
```

### Observatory Capabilities

| Capability | What It Reveals |
|------------|-----------------|
| **Scam Type Tracking** | Which fraud types are trending (13 categories) |
| **Persona Effectiveness** | Which strategies work best per scam type |
| **IOC Patterns** | When and how scammers reveal indicators |
| **Campaign Attribution (planned)** | Linking individual scams to coordinated operations |
| **Evolution Monitoring** | How attacker TTPs change over time |

### Research Questions ScamBuster Answers

1. **What makes scammers stay engaged?** → Duration analysis per persona × scam type
2. **When do IOCs get revealed?** → Message-level extraction timing
3. **Which personas perform best?** → Reward comparison across personas per scam type
4. **How are campaigns organized?** → IOC clustering may reveal shared infrastructure (planned)

---

## Core Innovations

### 1. Multi-Agent LLM Architecture

Unlike single-prompt systems, ScamBuster uses **eight specialized agents**:

| Agent | Responsibility | Details |
|-------|---------------|---------|
| **ScamClassifier** | Categorize incoming scams | 13 scam types + an UNKNOWN fallback |
| **IocExtractor** | Extract indicators from messages | 36 IOC types (hybrid regex + LLM) |
| **Generator** | Create contextual responses | Persona-based, context-aware replies |
| **Validator** | Ensure response safety/quality | Two-layer PolicyGuard + LLM scoring |
| **ConversationDirector** | Steer the Generator each turn | Tracks intel already given, infers the objective, signals when to stop |
| **InjectionDetector** | Detect prompt injection attempts | Forensic, non-blocking; 6-technique taxonomy |
| **Orchestrator** | Coordinate agents, optimize costs | 3-attempt loop with per-call cost tracking |
| **TtpExtractor** | Tag scammer TTPs on inbound messages | Closed 27-entry ATT&CK-aligned taxonomy (inbound-only, feature-flagged) |

**Why it matters**: Specialized agents outperform monolithic approaches. Each can be optimized independently, and failures are isolated.

### 2. Hybrid IOC Extraction

ScamBuster combines regex patterns with LLM understanding:

| Approach | Description | IOC Types |
|----------|-------------|-----------|
| Regex only | Deterministic pattern matching | 7 basic types |
| LLM only | Contextual understanding, no fixed patterns | Open-ended |
| **ScamBuster Hybrid** | Regex patterns + LLM understanding | **36 types** |

**Supported IOC types include**:
- Email addresses, phone numbers (international formats)
- Bank accounts (IBAN, SWIFT/BIC, account numbers)
- Cryptocurrency wallets (BTC, ETH, USDT, etc.)
- URLs, domains, IP addresses
- Telegram handles, WhatsApp numbers
- Payment services (Western Union, MoneyGram MTCNs)
- Identity documents, company names

#### Contextual enrichment

Each conversation yields **deduplicated** IOCs (emails, domains, IPs, IBANs, crypto
wallets, phones, Telegram usernames, file hashes…) enriched with **semantic context**:

| Enrichment | What it records |
|------------|-----------------|
| **Role in the scam narrative** | Payment destination, phishing lure, contact channel |
| **Stimulus type** | What our persona did in the reply that preceded the reveal |
| **Urgency scoring** | How much pressure the surrounding message carries |
| **Context excerpt** | A short, **PII-free** quote showing how the IOC was used |

The enrichment prompt itself is operator-configurable (`contextual_enrichment`) -- see
[Prompt Customization](25_prompt_customization.md). The stimulus attribution is what the
stimulus → TTP → IOC crossing is built from ([TTP Intelligence](27_ttp_intelligence.md)),
and an analyst verdict can override the confidence attached to any of these IOCs
([Analyst Feedback](24_analyst_feedback.md)).

### 3. Adaptive Learning (Research Innovation)

**Novel application of contextual multi-armed bandits to scambaiting.** To our knowledge, this is among the earliest documented implementations combining RL-based persona selection with automated honeypot engagement in a production-oriented, measurable setup.

#### The Problem with Static Strategies

Traditional honeypots use fixed personas and responses:

- Different scam types respond to different personas
- What works for "Nigerian Prince" scams fails for tech support scams
- Human intuition about optimal strategies is often wrong
- The optimal strategy changes as scammers adapt

#### The ScamBuster Solution

**Contextual bandit** that learns automatically:

```
Context (scam type) → Select persona → Observe reward → Update model
```

| Algorithm | Version | Status | Key Properties |
|-----------|---------|--------|----------------|
| **ε-greedy + UCB1** | V1 | ✅ Production | 80/20 exploit/explore, UCB1 bonus, 60% convergence detection |
| **Thompson Sampling** | V2 | Planned | Bayesian, zero hyperparameters |

**Reward function**:

```
reward = 0.40 × duration_score
       + 0.25 × total_iocs_score
       + 0.25 × high_value_iocs_score
       + 0.10 × completion_score
```

The bandit tracks a per-scam-type reward average for each persona and shifts selection toward the personas that accumulate higher reward for that context.

### 4. Double Validation Pipeline

Every generated response passes through two validation layers:

```
Generator → PolicyGuard (hard rules) → LLM Validator (quality) → Send
```

| Layer | Type | Purpose |
|-------|------|---------|
| **PolicyGuard** | Rule-based | Block forbidden content |
| **LLM Validator** | AI-based | Ensure coherence and strategic alignment |

**Result**: Every reply is checked by deterministic rules and an LLM quality judge before it is sent.

---

## Campaign Attribution

> **Note**: These patterns are identified through manual observation. An automated attribution pipeline is planned for a future release.

Patterns that can suggest coordinated operations:

| Pattern | Indicator |
|---------|-----------|
| **Shared IBANs** | Same accounts across multiple conversations |
| **Common templates** | Identical message structures |
| **Geographic clustering** | Phone prefixes revealing origin |
| **Infrastructure reuse** | Domains, email patterns |

---

## Cost Model

| Aspect | Value |
|--------|-------|
| **LLM provider** | GPT-4o (generation) + GPT-4o-mini (validation) |
| **Infrastructure** | Docker (existing) |
| **Human intervention** | Zero |
| **Budget cap** | Configurable monthly hard limit (default $50/month) |

For external context on manual handling costs, the Ponemon Institute estimates $137 per phishing incident handled manually. A live deployment can measure its own cost-per-IOC via the built-in LLM Cost Monitor and the [metrics catalog](22_metrics_catalog.md).

---

## Competitive Advantages

| Capability | Traditional Honeypot | Manual Scambaiting | ScamBuster |
|------------|---------------------|-------------------|------------|
| **Scale** | Limited | Very limited | **Fully automated** |
| **Learning** | None | Slow (experience) | **Automatic** |
| **24/7 operation** | Yes | No | **Yes** |
| **Observatory capabilities** | None | Limited | **Full** |
| **Reproducibility** | Low | None | **High** |

---

## Value by Stakeholder

### For SOC/CERT Teams

| Need | ScamBuster Solution |
|------|---------------------|
| Threat intelligence feeds | Automated STIX 2.1 / MISP export |
| Campaign attribution | IOC clustering and analysis (planned) |
| Early warning | Trend detection across scam types |
| Analyst workload | Fully automated extraction |

### For MSSPs

| Need | ScamBuster Solution |
|------|---------------------|
| Service differentiation | Proactive TI vs reactive blocking |
| Scalability | One deployment, multiple clients |
| ROI demonstration | Quantifiable metrics and value |

### For Financial Institutions

| Need | ScamBuster Solution |
|------|---------------------|
| BEC detection | Early identification of business email compromise |
| Fraud prevention | Intelligence on active scam campaigns |
| Account protection | Reportable IBANs and accounts |

### For Researchers

| Need | ScamBuster Solution |
|------|---------------------|
| Methodology | Reproducible evaluation protocol |
| Data | Anonymized dataset (planned release) |
| Experimentation | Platform for strategy testing |

---

## Next Steps

- [Architecture](03_high_level_architecture.md): How the system is designed
- [Security & Ethics](04_security_guardrails.md): Safety controls and compliance
- [Evaluation](05_evaluation_methodology.md): How we measure results
- [Roadmap](06_roadmap.md): What's coming next

---

[← Back to Main](../README.md)

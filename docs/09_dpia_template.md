# Data Protection Impact Assessment (DPIA) Template

## Document Information

| Field | Value |
|-------|-------|
| System | ScamBuster -- Automated Scambaiting Honeypot |
| Version | 1.0 |
| Classification | Internal |
| Last Updated | 2026-03-11 |

---

## 1. Description of Processing

### 1.1 Nature of Processing

ScamBuster is an automated honeypot platform that engages with inbound scam emails to waste scammers' time and extract threat indicators (IOCs). The system:

- Receives unsolicited scam emails sent to monitored mailboxes
- Classifies the scam type using an LLM-based classifier
- Generates contextually appropriate replies using an LLM with persona-based engagement strategies
- Validates all outgoing replies through a two-layer safety filter (PolicyGuard + LLM Validator)
- Extracts, normalizes, and deduplicates IOCs from scammer messages
- Exports IOCs in STIX 2.1 and MISP-compatible formats for threat intelligence sharing
- Analyzes inbound messages for prompt injection attempts using two-layer detection (pattern matching + LLM-as-judge forensic analysis)

### 1.2 Scope of Processing

| Data Category | Examples | Source | Retention |
|---------------|----------|--------|-----------|
| Scam email content | Subject, body text, headers | Inbound emails from scammers | 6 months max, then anonymization |
| Threat indicators (IOCs) | IBANs, crypto wallets, phone numbers, email addresses, URLs, IPs | Extracted from scam emails | Indefinite (intelligence value) |
| Email metadata | From/To/CC, Message-ID, timestamps, DKIM/SPF results | Email headers | Aligned with email content retention |
| LLM interaction metadata | Model used, token count, cost estimate, approval status | System-generated | Aligned with email content retention |
| Persona assignment | Persona code, scam type, reward score | System-generated | Indefinite (aggregated statistics) |

### 1.3 Purpose of Processing

1. **Cybersecurity research**: Study scam patterns, tactics, and infrastructure
2. **Threat intelligence production**: Generate actionable IOCs for SOC/CERT teams
3. **Scammer time-wasting**: Reduce scammer capacity by engaging them in non-productive conversations
4. **Academic research**: Support master's thesis on automated scambaiting (E-MSc Cybersecurity)

### 1.4 Data Controller

The system operator (deploying organization) acts as data controller. The software is provided under MIT license; deployment responsibility lies with the operator.

---

## 2. Legal Basis

### 2.1 Primary Legal Basis

**Legitimate interest** (GDPR Article 6(1)(f)):
- The legitimate interest is cybersecurity defense and threat intelligence production
- Scammers initiate contact; the system only responds to unsolicited messages
- No processing of data from innocent third parties (inbound-only architecture)

### 2.2 Balancing Test

| Factor | Assessment |
|--------|------------|
| Nature of interest | Cybersecurity defense -- strong public interest |
| Impact on data subjects | Scammers' operational data (IBANs, wallets) used for threat intel |
| Reasonable expectation | Scammers have no reasonable expectation of privacy when sending fraudulent emails |
| Safeguards | Two-layer validation, kill switch, inbound-only enforcement, PII filtering |

### 2.3 Special Category Data

No special category data (Article 9) is intentionally processed. If scam emails incidentally contain health, religious, or other sensitive data, it is not extracted or classified as such.

---

## 3. Necessity and Proportionality

### 3.1 Necessity

- **Automation is necessary** because manual scambaiting does not scale (one operator can handle ~5 conversations; the system handles 1,000+)
- **LLM generation is necessary** to produce realistic, varied responses that maintain scammer engagement
- **IOC extraction is necessary** to produce the threat intelligence that justifies the processing
- **Persona optimization is necessary** to maximize engagement duration and IOC yield per conversation

### 3.2 Proportionality

- Only data from scam emails is processed (no scraping, no proactive collection)
- The system never initiates contact (inbound-only, enforced at architecture level)
- Generated replies are validated against safety policies before sending
- Data minimization: only IOC-relevant fields are extracted and persisted
- Retention periods are defined and enforced via automated purge service

### 3.3 Data Minimization Measures

- PolicyGuard blocks replies containing real PII (IBANs, addresses)
- Phone numbers used in replies are fake (provided for reciprocity)
- No real personal data of the system operator is disclosed
- 16 automation-revealing keyword patterns are blocked
- Threat and authority impersonation patterns are blocked

---

## 4. Risk Assessment

### 4.1 Identified Risks

| # | Risk | Likelihood | Severity | Mitigation |
|---|------|-----------|----------|------------|
| R1 | Harmful content in generated replies | Low | High | PolicyGuard (deterministic) + LLM Validator (semantic) |
| R2 | PII leak in generated replies | Low | High | Regex-based PII detection (IBAN, addresses) |
| R3 | System reveals it is automated | Medium | Medium | 16 forbidden keyword patterns + LLM Validator |
| R4 | Impersonation of authorities | Low | High | Authority impersonation patterns in PolicyGuard |
| R5 | Threats or intimidation | Low | High | Threat patterns in PolicyGuard |
| R6 | Cost explosion (LLM API) | Medium | Low | Rate limiting at 3 levels (per-conversation, global LLM calls, active conversations) |
| R7 | IOC poisoning (false indicators) | Low | Medium | IocValidator + confidence scoring + manual audit |
| R8 | Scammer detects automation | Medium | Low | Persona variability, tone adaptation, repetition avoidance |
| R9 | Unintended outbound contact | Very Low | High | Inbound-only architecture (data model FK, handler exceptions, no standalone outbound endpoint) |
| R10 | Scammer attempts prompt injection to extract system prompt or manipulate LLM behavior | Low | High | Two-layer injection detection (pattern + LLM-as-judge), system prompt hardening, output validation via PolicyGuard + LLM Validator |

### 4.2 Residual Risk

After all mitigations, the residual risk is **LOW**. The primary remaining risk is R8 (scammer detection), which has low severity (the worst outcome is the scammer stops responding).

---

## 5. Security Measures

### 5.1 Technical Measures

| Measure | Implementation |
|---------|---------------|
| Secrets management | HashiCorp Vault (API keys, IMAP credentials) |
| Two-layer content validation | PolicyGuard (deterministic) + LLM Validator (semantic, gpt-4o-mini, temp=0.1) |
| Kill switch | Environment variable-based, checked before every generation and send |
| Rate limiting | Redis-backed Symfony rate-limiter at 3 levels |
| Inbound-only enforcement | Data model FK constraints + handler exceptions + no outbound endpoint |
| Data retention | PurgeService: anonymization at 6 months, hard delete at 12 months |
| IOC validation | IocNormalizer + IocValidator (regex per type, checksum, format) |
| Network isolation | Docker Compose, n8n self-hosted, no public-facing LLM endpoints |
| STIX 2.1 export | Standardized threat intelligence format with PII filtering |
| Prompt injection detection | Two-layer analysis (deterministic pattern matching + LLM-as-judge) on all inbound messages, results stored per message |

### 5.2 Organizational Measures

| Measure | Description |
|---------|-------------|
| Access control | JWT-based authentication, role-based access |
| Audit trail | LLM metadata (model, cost, approval) stored per message |
| Automated testing | 1,039 automated tests (unit, integration, E2E) |
| Code review | DDD architecture with strict layer separation |
| Monitoring | SQL views for precision drift detection (7-day sliding window) |

---

## 6. Data Subject Rights

### 6.1 Applicability

Scammers sending fraudulent emails may exercise data subject rights under GDPR. However:

- **Right to erasure**: Can be fulfilled via the purge mechanism. However, IOCs derived from criminal activity may be retained under the crime prevention exemption (Article 17(3)(d))
- **Right of access**: The system stores email content and extracted IOCs. Access requests can be fulfilled via the API
- **Right to object**: Processing can be stopped for a specific sender by adding them to the safelist or closing the conversation

### 6.2 Contact

Data subject requests should be directed to the system operator's designated contact point (DPO or equivalent).

---

## 7. Consultation

### 7.1 Internal Stakeholders

| Stakeholder | Consulted | Outcome |
|-------------|-----------|---------|
| System developer | Yes | Architecture designed with privacy-by-design principles |
| Academic supervisor | Yes | Research ethics approval obtained |
| Legal advisor | Recommended | To validate legitimate interest assessment |

### 7.2 Supervisory Authority

Prior consultation with the supervisory authority (CNIL or equivalent) is recommended if the operator processes data at scale or in a high-risk context.

---

## 8. Review Schedule

This DPIA should be reviewed:
- Annually
- When processing activities change significantly
- When new data types are collected
- When new LLM models are deployed
- After any security incident

---

## Appendix: DPIA Checklist

- [ ] Processing description is complete and accurate
- [ ] Legal basis is identified and documented
- [ ] Necessity and proportionality are justified
- [ ] All risks are identified with mitigations
- [ ] Technical and organizational measures are in place
- [ ] Data subject rights procedures are defined
- [ ] Review schedule is established
- [ ] Relevant stakeholders have been consulted

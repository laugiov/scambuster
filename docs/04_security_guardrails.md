# Security, Ethics & Guardrails

## Foundational Principle

> **Defensive engagement is only acceptable when it is controlled, proportionate, and legally framed.**

ScamBuster is designed as a **defensive research tool**, not an offensive weapon. Every design decision prioritizes safety, legality, and ethical operation.

---

## Defensive Principles

### What ScamBuster Does

| Action | Purpose | Control |
|--------|---------|---------|
| **Engage scammers** | Extract threat intelligence | Controlled personas, rate-limited |
| **Collect IOCs** | Enable blocking & attribution | Automatic, structured extraction |
| **Share intelligence** | Help defend others | Standard formats, authorized channels |
| **Learn strategies** | Improve effectiveness | Automated, no human targeting |

### What ScamBuster Does NOT Do

| Prohibited Action | Reason |
|-------------------|--------|
| ❌ Access attacker systems | Illegal (unauthorized access) |
| ❌ Deploy malware or exploits | Offensive, disproportionate |
| ❌ Harass or threaten | Unethical, counterproductive |
| ❌ Share victim data | Privacy violation |
| ❌ Target individuals | Research focuses on infrastructure |
| ❌ Escalate conflicts | Defensive posture only |

---

## Legal Framework

### GDPR Compliance Approach (EU)

ScamBuster is designed with **Article 6.1.f** (legitimate interest) as the assessed lawful basis:

| Requirement | Implementation |
|-------------|----------------|
| **Lawful basis** | Legitimate interest assessed: academic research + fraud prevention |
| **Purpose limitation** | Data used only for threat intelligence research |
| **Data minimization** | Only scammer-provided data collected; victim PII redacted when detected (incident procedure documented) |
| **Storage limitation** | 6 months max, then anonymization |
| **Security** | Encryption at rest, TLS in transit |
| **Privacy considerations** | Processing limited to fraud-related communications |

> **Note**: Legal review recommended before deployment in specific jurisdictions.

### Retention Model (Content vs. Metadata)

To reconcile **storage limitation** with **auditability**, ScamBuster separates retention into two layers:

| Layer | Retention | Content |
|-------|-----------|---------|
| **Content** | 6 months max | Raw messages, transcripts, prompts/responses |
| **Audit metadata** | 12 months | Timestamps, event types, hashes, decision outcomes, cost metrics |

- **Content layer**: Anonymized or deleted after 6 months per DPIA scope
- **Metadata layer**: Retained for security traceability without storing full message content

This model preserves auditability while minimizing personal data exposure.

### Data Protection Impact Assessment (DPIA)

DPIA documentation covers:

- Data flows and processing activities
- Risk assessment and mitigation measures
- Proportionality of processing

> **Status**: Internal documentation available upon request under NDA.

### Jurisdictional Considerations

| Jurisdiction | Status |
|--------------|--------|
| **France** | Primary operation; CNIL guidelines considered |
| **EU** | Designed for GDPR compliance |
| **International** | Geo-restrictions configurable |

> **Recommendation**: Consult local counsel before deployment outside France/EU.

---

## Safety Controls

### 1. Kill Switch

**Immediate system halt** available at multiple levels:

| Level | Trigger | Effect |
|-------|---------|--------|
| **Workflow** | Manual button in n8n | Stop all active engagements |
| **API** | Admin endpoint | Disable response generation |
| **Database** | Flag conversations | Prevent further messages |
| **Infrastructure** | Container stop | Full system halt |

### 2. Rate Limiting

| Control | Limit | Purpose |
|---------|-------|---------|
| **New conversations/day** | 50 max | Prevent runaway engagement |
| **Messages/conversation** | 20 max | Limit exposure |
| **LLM calls/hour** | 200 max | Cost control |
| **API requests/minute** | 100 max | DDoS protection |

### 3. Content Filters

**PolicyGuard** (rule-based) blocks:

| Category | Examples |
|----------|----------|
| **Threats** | Violence, harm, blackmail |
| **Illegal offers** | Drugs, weapons, CSAM |
| **Real PII** | Actual victim data, credentials |
| **Financial fraud** | Real account numbers, transfers |
| **Impersonation** | Law enforcement, government |

**LLM Validator** (AI-based) ensures:

| Quality | Check |
|---------|-------|
| **Coherence** | Response makes sense in context |
| **Tone** | Matches persona profile |
| **Safety** | No harmful content slipped through |
| **Strategy** | Aligns with engagement goals |

### 4. Sandboxing

| Layer | Isolation |
|-------|-----------|
| **Network** | Dedicated network segmentation |
| **Environments** | Separate preprod environment |
| **Database** | Access control policies |
| **Secrets** | Dedicated store with strict access |

### 5. Audit Trail

**Application-level audit trail** of all operations:

| Event | Data Captured |
|-------|---------------|
| **Conversation start** | Timestamp, source, scam type |
| **Message sent** | Content reference (ID/hash), persona used |
| **Message received** | Content reference (ID/hash), IOCs extracted |
| **LLM calls** | Call metadata, cost (prompts stored separately in Content layer) |
| **Validation** | Pass/fail, reasons |
| **Admin actions** | User, action, timestamp |

Logs are:
- **Metadata layer**: Retained for 12 months (no raw content)
- **Content layer**: Retained for 6 months max, then anonymized/deleted (see Retention Model above)
- Designed for append-only operation
- Available for security review

---

## Ethical Safeguards

### Source Ethics

| Principle | Implementation |
|-----------|----------------|
| **Controlled sources** | Combination of public scam reporting sites and controlled honeypot mailboxes |
| **Inbound-only engagement** | System engages **only after inbound contact**; no proactive outreach |
| **No entrapment posture** | No coercion, no escalation, no inducement; defensive engagement only |

### Engagement Ethics

| Principle | Implementation |
|-----------|----------------|
| **No harassment** | Maximum 1 initiation message |
| **No escalation** | Defensive responses only |
| **No doxxing** | IOCs shared only via professional channels |
| **Proportionate** | Engagement matches scam severity |

### Output Ethics

| Principle | Implementation |
|-----------|----------------|
| **Authorized recipients only** | CERTs, banks, law enforcement (referrals/reporting, not evidence-grade artifacts) |
| **Standard formats** | STIX 2.1, MISP (interoperable) |
| **No public shaming** | Research, not vigilantism |
| **Anonymization** | Conversation transcripts stripped of identifiers |

---

## Operational Security

### Infrastructure Security

| Control | Implementation |
|---------|----------------|
| **Encryption at rest** | Infrastructure-layer encryption (volume/disk); optional field-level encryption for high-sensitivity values |
| **Encryption in transit** | TLS 1.2+; TLS 1.3 where supported |
| **Access control** | Token-based auth + RBAC + network restrictions |
| **Secrets management** | Dedicated secrets store |
| **Vulnerability scanning** | Automated CI/CD security checks |

### LLM Security

| Risk | Mitigation |
|------|------------|
| **Prompt injection** | Input sanitization, output validation |
| **Model abuse** | Rate limiting, cost caps |
| **Data leakage** | No training on conversation data |
| **Version drift** | Pinned model version |

### Personnel Security

| Control | Implementation |
|---------|----------------|
| **Access limitation** | Need-to-know basis |
| **Audit logging** | All admin actions tracked |
| **Training** | Ethics and security awareness |
| **Incident response** | Documented procedures |

---

## Legal & Compliance Positioning

> **Important**: This documentation does not constitute legal advice.

ScamBuster is a **defensive research and fraud-prevention system**. Key compliance considerations:

| Aspect | Approach |
|--------|----------|
| **Lawful basis** | Designed to operate under legitimate interest (GDPR Art. 6(1)(f)) for fraud prevention and security research |
| **Jurisdiction** | Actual legality depends on jurisdiction, deployment model, and data flows |
| **DPIA** | Scope-specific DPIA recommended for any enterprise deployment |
| **Governance** | Written governance required (who can access what, and why) |
| **Legal review** | Formal legal review recommended before deployment |

> **Note**: Any enterprise deployment should include formal legal review, documented DPIA (scope-specific), and written governance policies.

---

## Risk Assessment

### Identified Risks & Mitigations

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| **Bot detection** | Medium | Medium | Human-like delays, micro-errors |
| **Scammer retaliation** | Low | Low | No real identities exposed |
| **Data breach** | Low | High | Encryption, access control, monitoring |
| **Legal challenge** | Low | Medium | GDPR compliance, legal review |
| **Misuse of code** | Medium | High | Private repository, NDA for access |
| **LLM cost explosion** | Low | Medium | Hard limits, monitoring |

### Residual Risks

| Risk | Acceptance Rationale |
|------|---------------------|
| **Some scammers may detect automation** | Expected; doesn't compromise safety |
| **Limited to email fraud** | Scope limitation, not a flaw |
| **Dependent on LLM provider** | Acceptable for research phase |

---

## Compliance Checklist

### Before Deployment

- [ ] Legal review of engagement model
- [ ] DPIA completed and documented
- [ ] Security audit of infrastructure
- [ ] Kill switch tested
- [ ] Rate limits configured
- [ ] Logging verified
- [ ] Access controls in place
- [ ] Incident response plan documented

### Ongoing Operations

- [ ] Weekly log review
- [ ] Monthly security scans
- [ ] Quarterly access review
- [ ] Annual legal/compliance review

---

## Incident Response

### Classification

| Severity | Definition | Response Time |
|----------|------------|---------------|
| **Critical** | Data breach, system compromise | Immediate |
| **High** | Safety control failure | <4 hours |
| **Medium** | Unexpected behavior | <24 hours |
| **Low** | Minor issue | <1 week |

### Response Steps

1. **Detect**: Automated monitoring + manual review
2. **Contain**: Kill switch if needed
3. **Investigate**: Log analysis, root cause
4. **Remediate**: Fix and test
5. **Report**: Document and notify (if required)
6. **Improve**: Update controls

---

## Contact for Security Issues

For security concerns or responsible disclosure:
- send me github message 
- **PGP Key**: Available on request
- **Response**: Within 48 hours

---

## Next Steps

- [Evaluation Methodology](05_evaluation_methodology.md): How we measure success
- [Roadmap](06_roadmap.md): Development timeline
- [FAQ](07_faq.md): Common questions

---

[← Back to Main](../README.md)

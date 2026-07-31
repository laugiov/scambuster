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

> **Status**: Full implementation details in the source code.

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
| **Messages/conversation** | 20 per 24h sliding window | Limit exposure |
| **LLM calls/hour** | 200 max | Cost control |
| **API requests/minute** | 100 max | DDoS protection |
| **Emails/sender/day** | 10 max | Per-sender abuse prevention |
| **Sender flood (burst)** | 5 in 5min | Quarantine sender for 1 hour |

**Sender-level rate limiting**: When a sender exceeds 10 emails/24h, messages are still ingested (for analysis) but no reply is generated. Burst flood detection quarantines senders who send 5+ emails within 5 minutes. All rate limit hits generate `RATE_LIMIT_EXCEEDED` audit events.

### 3. Content Filters

**PolicyGuard** (rule-based) blocks:

| Category | Examples |
|----------|----------|
| **Threats** | Violence, harm, blackmail |
| **Illegal offers** | Drugs, weapons, CSAM |
| **Real PII** | Actual victim data, credentials |
| **Financial fraud** | Real account numbers, transfers |
| **Impersonation** | Law enforcement, government |

**LLM Validator** (AI-based, multi-criteria scoring):

| Dimension | Scale | Rejection threshold |
|-----------|-------|---------------------|
| **Naturalness** | 1-5 | Reject if < 2 |
| **Persona fit** | 1-5 | Used in average |
| **TI value** | 1-5 | Used in average |
| **Security gate** | pass/fail | Reject if fail |

Average quality score (naturalness + persona_fit + ti_value) / 3 must be >= 2.5. Chain-of-thought reasoning per dimension.

**Prompt Injection Detector** (forensic analysis):

| Layer | Description |
|-------|-------------|
| **Layer 1 (Pattern Matcher)** | Deterministic pre-filter for known injection techniques (<1ms, zero cost) |
| **Layer 2 (LLM-as-Judge)** | Semantic analysis for novel attack patterns (LLM call, configurable model) |

> **Note**: This detection is forensic -- it does not block message ingestion or modify the reply pipeline. Results are stored per message for offline research analysis.

### 4. Sandboxing

| Layer | Isolation |
|-------|-----------|
| **Network** | Dedicated network segmentation |
| **Environments** | Separate preprod environment |
| **Database** | Access control policies |
| **Secrets** | Dedicated store with strict access |

### 5. Audit Trail

**Structured audit trail** with 33 event types, SIEM-forwarded:

| Event Type | Trigger | Data Captured |
|------------|---------|---------------|
| **AUTH_SUCCESS/FAILURE** | Login attempt | User, IP, outcome |
| **AUTH_TOKEN_EXPIRED** | JWT expiry | User, token age |
| **MESSAGE_INGESTED** | Email ingestion | Message ID, conversation ID, channel |
| **REPLY_GENERATED** | Reply creation | Persona, model, cost, attempts, language |
| **REPLY_SENT** | Reply sent via email | Message ID, provider |
| **IOC_EXTRACTED** | IOC extraction | IOC type, value, indicator ID |
| **TTP_EXTRACTED** | Scammer TTP tagged on an inbound message | TTP code, confidence, status |
| **CONVERSATION_CLOSED** | Conversation end | Reward value, closure reason, duration |
| **PERSONA_SELECTED** | Bandit selection | Persona, scam type, strategy (exploit/explore) |
| **INJECTION_DETECTED** | High-risk injection | Risk score, patterns, conversation ID |
| **RATE_LIMIT_EXCEEDED** | Rate limit hit | Limit type, sender |
| **KILL_SWITCH_TOGGLED** | Emergency halt | Actor, new state |
| **EXPORT_STIX/MISP** | Data export | Campaign/conversation ID, item count |
| **CONFIG_CHANGED** | Configuration update | Setting, old/new values |

All events include: `trace_id`, `actor_id`, `ip_address`, `timestamp`. Forwarded to SIEM (CEF/ECS/JSON).

Additional security features:
- **IOC Confidence Scoring**: Multi-observation boost formula (1-(1-base)^n) with temporal decay per IOC type
- **Prompt Injection Detection**: Scheduled forensic analysis (cron every 6h) with dedicated monitoring page
- **Pipeline Tracing**: Per-reply component trace (timing, cost, approval) stored on message headers
- **Production LLM Logs**: Dedicated Monolog handler bypassing fingers_crossed for always-on visibility

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
| **Prompt injection** | Two-layer detection (pattern matching + LLM-as-judge forensic analysis), input sanitization, output validation. Inbound messages are analyzed for injection attempts and results stored for research |
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
| **Misuse of code** | Medium | High | Open source (MIT License), responsible use agreement recommended |
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
- Submit a report via [GitHub Security Advisories](../../security/advisories/new)
- **PGP Key**: Available on request
- **Response**: Within 48 hours

---

## Next Steps

- [Evaluation Methodology](05_evaluation_methodology.md): How we measure success
- [Roadmap](06_roadmap.md): Development timeline
- [FAQ](07_faq.md): Common questions

---

## Security by Design Implementation

The following controls were implemented based on the [security-by-design](https://github.com/laugiov/security-by-design) reference framework:

### OWASP Security Headers

All API responses include: `Content-Security-Policy: default-src 'none'`, `Strict-Transport-Security: max-age=31536000; includeSubDomains`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`, `Permissions-Policy`, `Cross-Origin-Opener-Policy: same-origin`, `X-Permitted-Cross-Domain-Policies: none`. Implemented via `SecurityHeadersListener` on `kernel.response`.

### Structured Audit Trail

`audit_log` table with 33 event types (AUTH_SUCCESS, AUTH_FAILURE, MESSAGE_INGESTED, REPLY_GENERATED, IOC_EXTRACTED, TTP_EXTRACTED, INJECTION_DETECTED, etc.). Queryable via `GET /api/v1/monitoring/audit`. Each entry includes event_type, actor, resource, action, outcome, details JSON, IP address, and trace_id.

### PII Minimization in Logs

Zero `error_log()` calls in production code. LLM prompt content and generated text are never logged (only lengths and metadata). Monolog JSON formatter in prod with structured fields.

### Request Correlation (Trace ID)

Every request gets a unique `X-Trace-Id` header (UUID). Propagated to all log entries via Monolog processor and to audit events. Supports incoming trace_id for cross-service correlation.

### JWT RS256 (Asymmetric)

JWT signing migrated from HS256 (shared secret) to RS256 (private/public key pair). Token TTL reduced from 1 hour to 15 minutes. Key rotation scripts with zero-downtime procedure. See [Key Management](14_key_management.md).

### RBAC Permissions

13 fine-grained permissions (conversation:read/write/close, ioc:read/export/feedback, reply:generate, campaign:read/hunt/promote, monitoring:read, audit:read, config:write). PermissionVoter grants all permissions to ROLE_ADMIN implicitly. Regular users need explicit permissions in their user profile.

### Payload Size Limit

Requests exceeding 1 MB are rejected with `413 Payload Too Large` via `PayloadSizeLimitListener`.

### CI Security Scanning

GitHub Actions CI includes `composer audit` (PHP dependency vulnerabilities) and Gitleaks (secret detection).

### SIEM Event Export

All 33 audit event types are exportable to enterprise SIEM platforms via pluggable connectors:

| Severity | Event Types | CEF Level |
|----------|-------------|-----------|
| **Critical** | KILL_SWITCH_TOGGLED | 9 |
| **High** | CONFIG_CHANGED, INJECTION_DETECTED | 7-8 |
| **Medium** | AUTH_FAILURE, IOC_EXTRACTED, RATE_LIMIT_EXCEEDED | 4-6 |
| **Low** | AUTH_SUCCESS, MESSAGE_INGESTED, REPLY_SENT, etc. | 1-3 |

**Security controls**:
- PII masked in exported events (emails hashed, IPs truncated)
- Disabled by default (NullSiemExporter, zero overhead)
- Transport security via TLS for TCP syslog
- Audit of audit: export failures logged to Monolog

See [SIEM Integration Guide](15_siem_integration.md) for configuration and testing.

---

[← Back to Main](../README.md)

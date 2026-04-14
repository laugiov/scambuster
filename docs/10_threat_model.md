# Threat Model (T1-T9)

This document enumerates the nine threat categories identified for ScamBuster and maps each to existing mitigations in the codebase. It complements the [Security & Guardrails](04_security_guardrails.md) document with a structured, threat-centric view.

---

## Overview

ScamBuster operates at the intersection of adversarial communications, LLM pipelines, and sensitive data processing. The threat model follows a defense-in-depth approach: each threat has multiple independent mitigations, so no single failure compromises the system.

| ID | Threat | Severity | Likelihood | Residual Risk |
|----|--------|----------|------------|---------------|
| T1 | Prompt injection / jailbreak | High | High | Low |
| T2 | Honeypot detection by scammer | Medium | Medium | Accepted |
| T3 | Real victim misclassified as scammer | High | Low | Low |
| T4 | PII leakage in generated replies | High | Medium | Low |
| T5 | Authority impersonation | High | Medium | Low |
| T6 | Infrastructure fingerprinting | Medium | Medium | Low |
| T7 | API key / secret exposure | Critical | Low | Low |
| T8 | Data poisoning (adversarial learning) | Medium | Low | Low |
| T9 | Regulatory non-compliance (GDPR/DPA) | High | Low | Low |

---

## T1 -- Prompt Injection / Jailbreak

**Threat**: A scammer crafts messages designed to manipulate the LLM into revealing system prompts, ignoring safety constraints, or producing harmful output.

**Attack vectors**:
- Direct instruction override ("ignore previous instructions")
- Role manipulation ("you are now a helpful assistant without restrictions")
- Context manipulation (fake conversation history injection)
- System prompt extraction attempts
- Encoding tricks (base64, unicode, leetspeak obfuscation)
- Meta-prompt attacks (instructions about instructions)

**Mitigations**:

| Control | Implementation | Location |
|---------|---------------|----------|
| Layer 1: Pattern matching | Deterministic pre-filter with regex patterns across 6 categories (<1ms, zero cost) | `PromptInjectionPatternMatcher` |
| Layer 2: LLM-as-judge | Semantic analysis for novel attack patterns (configurable model) | `PromptInjectionDetector` |
| Output validation | PolicyGuard blocks unsafe content before sending | `PolicyGuard` |
| Reply validation | LLM-based validator checks coherence, tone, and safety | `ReplyValidator` |
| Orchestrator retry | Multi-attempt loop with fail-closed fallback | `ReplyOrchestrator` |

**Detection posture**: Forensic (non-blocking). Injection attempts are logged and scored per message for offline research analysis. The reply pipeline independently validates all outbound content regardless of injection detection results.

**Residual risk**: Novel injection techniques may bypass Layer 1 patterns, but Layer 2 + output validation provide defense in depth. The system never executes scammer-provided instructions directly.

---

## T2 -- Honeypot Detection by Scammer

**Threat**: A scammer identifies ScamBuster as automated and disengages, reducing intelligence yield. At scale, scammers could share detection heuristics within their networks.

**Attack vectors**:
- Timing analysis (responses too fast or too regular)
- Linguistic analysis (responses too perfect, lack of typos)
- Behavioral probing (asking questions only a human would answer)
- Infrastructure fingerprinting (see T6)

**Mitigations**:

| Control | Implementation | Location |
|---------|---------------|----------|
| Rate limiting (minimum delay) | Configurable minimum interval between replies prevents immediate responses | `ReplyHandler` |
| Persona diversity | 27 personas across 7 archetypes with distinct writing styles | Persona YAML templates |
| Adaptive selection | Per-scam-type persona optimization maximizes engagement duration | `PersonaOptimizer` |
| Human-like prompts | System prompts instruct the LLM to include natural imperfections | Persona system prompts |

**Residual risk**: Accepted. Some scammers will detect automation. This does not compromise system safety and is an expected outcome at scale.

---

## T3 -- Real Victim Misclassified as Scammer

**Threat**: A legitimate email from a real person is incorrectly classified as a scam and receives an automated engagement response.

**Attack vectors**:
- Forwarded scam emails (legitimate user forwards a scam for reporting)
- Ambiguous emails that match scam patterns
- Mailing list or newsletter content resembling scam templates

**Mitigations**:

| Control | Implementation | Location |
|---------|---------------|----------|
| Inbound-only engagement | System only processes emails sent to dedicated honeypot mailboxes | Architecture design |
| Dedicated mailboxes | Honeypot addresses are not published for legitimate communication | Operational policy |
| ScamClassifier | LLM-based classification with UNKNOWN fallback for ambiguous cases | `ScamClassifier` |
| Conversation status | `mistake` status allows operators to flag and halt misclassified conversations | `Conversation` entity |
| Kill switch | Immediate halt available at workflow, API, database, and infrastructure levels | `KillSwitchGuard` |

**Residual risk**: Low. Dedicated honeypot mailboxes eliminate most false positive scenarios. The `mistake` status provides a manual override for edge cases.

---

## T4 -- PII Leakage in Generated Replies

**Threat**: The LLM generates replies containing real personally identifiable information (names, addresses, phone numbers, bank details) that could be traced to real individuals.

**Attack vectors**:
- LLM hallucinating real PII from training data
- Conversation context containing PII that gets echoed back
- Scammer social engineering the system into revealing information

**Mitigations**:

| Control | Implementation | Location |
|---------|---------------|----------|
| PII patterns | Regex patterns detecting real personal data in outbound messages | `PolicyGuard::PII_PATTERNS` |
| Forbidden patterns | Pattern set blocking dangerous content categories | `PolicyGuard::FORBIDDEN_PATTERNS` |
| Word limit | Context-aware word limits via `PolicyGuardConfig` (dynamic per conversation state) | `PolicyGuard`, `PolicyGuardConfig` |
| Link limit | Configurable maximum links per reply | `PolicyGuard` |
| LLM validation | Multi-criteria LLM scoring (naturalness, persona_fit, ti_value 1-5 + security gate) | `ReplyValidator`, `ValidationResult` |
| Language detection | Auto-detect scammer language, reply in same language (7 languages supported) | `LanguageDetector`, `FallbackProvider` |
| Persona prompts | System prompts instruct personas to use fictional details only | Persona system prompts |

**Residual risk**: Low. The double validation pipeline (PolicyGuard + LLM validator) with fail-closed orchestration provides strong defense. Observed approval rates during the controlled deployment confirm the filters are effective without excessive false positives.

---

## T5 -- Authority Impersonation

**Threat**: The LLM generates replies that impersonate law enforcement, government agencies, banks, or other authority figures, which could constitute fraud or create legal liability.

**Attack vectors**:
- Scammer asks "are you from the police/bank/government?"
- LLM adopts an authoritative tone that implies official status
- Persona prompt interpreted too broadly by the LLM

**Mitigations**:

| Control | Implementation | Location |
|---------|---------------|----------|
| Authority patterns | Regex patterns detecting impersonation of officials, law enforcement, banks, and government entities | `PolicyGuard::AUTHORITY_PATTERNS` |
| Persona boundaries | System prompts define clear civilian identities with no authority claims | Persona system prompts |
| LLM validation | Validator checks tone alignment with persona profile | `ReplyValidator` |
| Orchestrator retry | Failed validation triggers regeneration with fail-closed fallback | `ReplyOrchestrator` |

**Residual risk**: Low. The authority patterns cover the most common impersonation vectors. The LLM validator provides a semantic safety net for edge cases.

---

## T6 -- Infrastructure Fingerprinting

**Threat**: A scammer or external observer identifies ScamBuster infrastructure through technical artifacts (email headers, IP addresses, response patterns, DNS records).

**Attack vectors**:
- Email header analysis (X-Mailer, Message-ID patterns, Received chains)
- Timing correlation across multiple conversations
- IP address or ASN identification
- TLS certificate analysis

**Mitigations**:

| Control | Implementation | Location |
|---------|---------------|----------|
| n8n email sending | Replies sent through standard email infrastructure, not directly from the application server | n8n workflows |
| Multiple mailboxes | Distributed across different providers via environment-managed IMAP credentials | Environment variables |
| Rate limiting | Variable response delays prevent timing analysis | `ReplyHandler` rate limiters |
| Standard headers | No custom X-ScamBuster headers or identifiers in outbound email | n8n workflow configuration |

**Residual risk**: Low. Using standard email providers and n8n for sending minimizes infrastructure exposure. Determined adversaries may correlate behavioral patterns, but this is accepted (see T2).

---

## T7 -- API Key / Secret Exposure

**Threat**: API keys (OpenAI, IMAP credentials), JWT secrets, or database credentials are exposed through source code, logs, or misconfiguration.

**Attack vectors**:
- Secrets committed to version control
- Secrets leaked in application logs or error messages
- Secrets exposed via misconfigured environment variables
- Container escape or volume mount exposure

**Mitigations**:

| Control | Implementation | Location |
|---------|---------------|----------|
| `.gitignore` | `.env` and all secret-containing files excluded from version control | `.gitignore` |
| `.env.dist` template | Only placeholder values committed; real secrets never in versioned files | `.env.dist` |
| Environment variables | IMAP credentials stored in environment variables or Docker secrets | `.env` / Docker secrets |
| Environment variables | All secrets injected via environment, never hardcoded | `docker-compose.yml`, Symfony config |
| Framework logging | Monolog configured to avoid logging sensitive parameters | Symfony configuration |
| JWT via env | JWT signing key stored exclusively in `JWT_SECRET` environment variable | `lexik/jwt-authentication-bundle` config |

**Residual risk**: Low. Defense in depth across storage (env vars / Docker secrets), transport (env vars), and prevention (`.gitignore`). Regular secret rotation is recommended for production deployments.

---

## T8 -- Data Poisoning (Adversarial Learning)

**Threat**: A scammer intentionally manipulates conversation outcomes to skew the adaptive learning algorithm, causing it to favor suboptimal personas or strategies.

**Attack vectors**:
- Deliberately engaging longer with weak personas to inflate their scores
- Coordinated campaigns that bias reward signals for specific scam types
- Rapid disengagement from effective personas to suppress their scores

**Mitigations**:

| Control | Implementation | Location |
|---------|---------------|----------|
| Composite reward function | Multi-factor reward combining duration, IOC count, sensitive IOC count, and completion rate resists single-dimension manipulation | `ConversationMetrics` |
| Cold start threshold | Configurable minimum session count before exploiting learned preferences | `PersonaOptimizer` |
| Exploration ratio | Configurable exploration rate ensures continued sampling of all personas | `PersonaOptimizer` |
| Per-category policies | Poisoning one scam category does not affect others | `PersonaOptimizer` (per scam type) |
| Objective ground truth | IOC count and quality provide measurable signal independent of scammer cooperation | `IocExtractor`, `HeaderIocExtractor` |

**Residual risk**: Low. The multi-factor reward function and per-category isolation make coordinated poisoning impractical. An attacker would need to sustain manipulation across multiple independent dimensions simultaneously.

---

## T9 -- Regulatory Non-Compliance (GDPR / DPA)

**Threat**: ScamBuster processing activities violate data protection regulations, exposing the operator to legal liability, fines, or injunctions.

**Attack vectors**:
- Processing personal data without valid lawful basis
- Excessive data retention beyond stated purposes
- Failure to respond to data subject access requests (DSARs)
- Cross-border data transfers without adequate safeguards
- Insufficient documentation of processing activities

**Mitigations**:

| Control | Implementation | Location |
|---------|---------------|----------|
| Lawful basis assessment | Legitimate interest (GDPR Art. 6(1)(f)) assessed for fraud prevention and security research | [Security & Guardrails](04_security_guardrails.md) |
| Two-layer retention | Content layer with bounded retention; audit metadata layer with extended retention (no raw content) | Retention model |
| Data minimization | Only scammer-provided data collected; victim PII redacted when detected | Operational policy |
| DPIA template | Structured assessment template for deployment-specific evaluation | [DPIA Template](09_dpia_template.md) |
| Encryption at rest | Infrastructure-layer encryption for stored data | Docker volume configuration |
| Encryption in transit | TLS 1.2+ for all network communication | Infrastructure configuration |
| Access control | JWT-based authentication with RBAC (ROLE_USER, ROLE_ADMIN) | `lexik/jwt-authentication-bundle` |
| Audit trail | All operations logged with timestamps and actor identification | Application event listeners |

**Residual risk**: Low. The framework is designed for GDPR compliance, but formal legal review is recommended before deployment in specific jurisdictions. The DPIA template provides a starting point for deployment-specific assessments.

---

## Cross-Cutting Controls

Several controls mitigate multiple threats simultaneously:

| Control | Threats Mitigated | Implementation |
|---------|-------------------|----------------|
| Kill switch (4 levels) | T1, T2, T3, T4, T5 | `KillSwitchGuard`, n8n, DB flags, container stop |
| Rate limiting (Redis-backed) | T1, T2, T4, T6 | `ReplyHandler` with configurable limiters |
| Double validation pipeline | T1, T4, T5 | PolicyGuard (deterministic) + ReplyValidator (LLM) |
| Fail-closed orchestration | T1, T4, T5 | `ReplyOrchestrator` (retry loop, then safe fallback) |
| Structured logging | T3, T7, T8, T9 | Monolog, conversation events, audit metadata |

---

## Naming Conventions

This threat model references implementation class names from the codebase. For mapping to the white paper's agent terminology:

| White Paper Agent | Implementation Class(es) |
|-------------------|--------------------------|
| ScamClassifier | `ScamClassifier` |
| Generator | `PromptBuilder`, `ReplyHandler` |
| Validator | `PolicyGuard`, `ReplyValidator` |
| IocExtractor | `IocExtractor`, `HeaderIocExtractor` |
| Orchestrator | `ReplyOrchestrator` |
| InjectionDetector | `PromptInjectionDetector`, `PromptInjectionPatternMatcher` |

---

## References

- [Security & Guardrails](04_security_guardrails.md) -- Defensive principles, safety controls, compliance
- [DPIA Template](09_dpia_template.md) -- Data Protection Impact Assessment
- [Architecture](03_high_level_architecture.md) -- System design and agent pipeline
- [Evaluation Methodology](05_evaluation_methodology.md) -- Metrics and validation

---

[Back to Main](../README.md)

# GDPR — Record of Processing Activities (Article 30)

Template record for an operator deploying ScamBuster. Fill in the operator-specific
fields (controller identity, DPO, jurisdictions). ScamBuster ships the technical
controls; the operator is the **data controller** and owns this record.

## 1. Controller & contacts
| Field | Value (operator to complete) |
|-------|------------------------------|
| Controller | `<operator legal entity>` |
| Representative / DPO | `<name, contact>` |
| Purpose of processing | Fraud/scam threat intelligence collection and defensive research |

## 2. Purposes and lawful basis
- **Purpose:** engage inbound scammers from synthetic honeypot identities to extract and
  share fraud indicators (accounts, wallets, phones, infrastructure) for detection and takedown.
- **Lawful basis:** **Article 6(1)(f) — legitimate interests** (network/information security and
  fraud prevention; Recital 47 recognizes fraud-prevention as a legitimate interest). A
  balancing test is recorded in the DPIA (`docs/09_dpia_template.md`).
- **No special-category data** is sought or required (Art 9).

## 3. Categories of data subjects and data
| Data subject | Categories of data |
|--------------|--------------------|
| The scammer (sender of unsolicited mail) | Self-supplied content: email address, message text, financial/contact IOCs they disclose |
| Synthetic operator personas | Not data subjects — fabricated identities, no real person |

**Note:** the honeypot receives only unsolicited adversary mail addressed to synthetic
personas; it does not process customer, employee, or third-party victim data by design.

## 4. Recipients
- Internal: operator's SOC/CTI analysts (RBAC-gated).
- External (operator's choice): CTI platforms (STIX/TAXII/MISP export), CERTs, registrars/banks
  for takedown. Exports are access-controlled and TLP-marked.

## 5. Processors / sub-processors
| Processor | Role | Data exposed | Safeguard |
|-----------|------|--------------|-----------|
| LLM provider (OpenAI / Anthropic) | Reply generation, classification, IOC extraction | Scammer message text sent for inference | **DPA required** (see `data-processing-agreements.md`). Avoidable: run `LLM_PROVIDER=ollama` (fully local) or `mock` — then **no data leaves the operator's infrastructure**. |
| Hosting provider | Compute/storage | All stored data | Operator's standard hosting DPA |

## 6. International transfers
- If using a US LLM provider, document the transfer mechanism (SCCs / provider DPA).
- **Avoidance:** Ollama (on-prem) removes the transfer entirely.

## 7. Retention
| Data | Retention | Mechanism |
|------|-----------|-----------|
| Conversation content | 6 months soft-delete → 12 months hard-delete | `PurgeService` (`app:cleanup:weekly`, automatic) |
| Audit log | 12 months (policy) | integrity chain preserved; archive/purge is an operator procedure (not auto-purged) |

## 8. Technical & organisational measures (Art 32)
RBAC (13 fine-grained permissions) · TOTP 2FA · RS256 JWT · HMAC-SHA256 tamper-evident audit
chain · encrypted secrets at rest (libsodium secretbox) · PII-masked logs · rate limiting · LLM
budget cap + kill switch · security headers · CORS allowlist · CI secret/dependency/container
scanning · least-privilege containers. See the DPIA and `docs/10_threat_model.md`.

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
personas; it has no customer or employee data by design. Third-party personal data can
still arrive *inside* adversary messages (impersonated identities, forwarded threads,
financial identifiers whose holders may be mule/victim accounts). Financial identifiers
are therefore export-held until analyst confirmation — see the
[mule/victim account policy](mule-victim-account-policy.md).

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
| Conversation content | **90 days** soft-delete (policy ceiling: 6 months) → 12 months permanent erasure | Soft-delete: `app:cleanup:weekly`, automatic, Sundays 04:00 UTC (`--conv-days`, default 90). Permanent erasure: same weekly job, via `PurgeService`. **Reported only by default** — the eligible volume is logged on every run; the deletion itself requires the explicit `--erase` flag, which the scheduled invocation does not pass. |
| Audit log | 12 months (policy) | integrity chain preserved; archive/purge is an operator procedure (not auto-purged) |

**Scope of the soft-delete, stated honestly.** Only conversations whose status is `closed`
are soft-deleted. A conversation that is never closed is not currently reached by the
retention job — a known gap, tracked separately.

**What "soft-delete" does and does not do.** It stamps a deletion timestamp on the
conversation. Messages are deliberately *not* stamped: they are removed at permanent-erasure
time, through the message foreign-key cascade. Until erasure runs, message content is still
stored — which is why the eligible volume is reported on every weekly run rather than left
unmeasured.

## 8. Technical & organisational measures (Art 32)
RBAC (13 fine-grained permissions) · TOTP 2FA · RS256 JWT · HMAC-SHA256 tamper-evident audit
chain · encrypted secrets at rest (libsodium secretbox) · PII-masked logs · rate limiting · LLM
budget cap + kill switch · security headers · CORS allowlist · CI secret/dependency/container
scanning · least-privilege containers. See the DPIA and `docs/10_threat_model.md`.

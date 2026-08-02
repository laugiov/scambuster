# Data Classification

ScamBuster is a scambaiting honeypot: it engages scammers from **synthetic operator
identities** and stores what those scammers send. This shapes its data classification —
most stored content is *attacker-supplied*, not the personal data of protected individuals.

## Classification levels

| Level | Definition | Handling |
|-------|-----------|----------|
| **SECRET** | Credentials & keys | Encrypted at rest, never logged, never exported |
| **INTERNAL** | Operational intelligence | RBAC-gated, access-controlled endpoints |
| **ATTACKER-SUPPLIED** | Scam content the adversary sent | Retained for intel; not subject-PII of a protected person |
| **PUBLIC** | Non-sensitive | — |

## Data inventory

| Data | Class | Where | Control |
|------|-------|-------|---------|
| Scammer email bodies / subjects / headers | ATTACKER-SUPPLIED | `message.body_text`, `body_html`, `headers` | RBAC (`conversation:read`); PII-masked in logs. **Plaintext at rest by design** — see rationale. |
| Extracted IOCs (wallets, IBAN, phones, URLs…) | INTERNAL | `observed_ioc`, `indicator` | RBAC (`ioc:read`), TLP marking |
| Synthetic operator persona content | ATTACKER-SUPPLIED context | personas, outbound replies | Synthetic identities — not real-person PII |
| Honeypot mailbox addresses | INTERNAL | `mail_account.email_address` | Never committed to git; OPSEC pre-commit gate |
| Per-account SMTP DSN | **SECRET** | `mail_account.smtp_dsn_encrypted` | **Encrypted at rest** (libsodium secretbox) |
| TOTP secrets | **SECRET** | `EncryptedStringType` | **Encrypted at rest** (libsodium secretbox) |
| Operator user credentials | **SECRET** | `app_user.password` | bcrypt/argon2 (`auto`) |
| Audit log | INTERNAL | `audit_log` | HMAC-SHA256 tamper-evident chain, RBAC (`audit:read`) |
| JWT signing keys / env secrets | **SECRET** | `config/jwt/*.pem`, `.env` | Gitignored, env-sourced, never logged |

## Why message bodies are stored plaintext (rationale)

The content in `message.body_text` is **written by the scammer**, to a **synthetic honeypot
persona**, on a mailbox that receives **only** unsolicited scam mail. It is not the personal
data of a customer, employee, or protected data subject; it is adversary-supplied evidence.
Consequently:

- Field-level encryption of bodies is **not** applied — it would break the Campaign-Radar
  content search (`WHERE body_text ILIKE`, the pg_trgm index) and the IOC-context tooling
  for no meaningful subject-privacy gain, since there is no protected subject.
- Confidentiality is enforced instead by **access control** (RBAC + admin-gated endpoints),
  **PII-masking in logs**, and **honeypot-address OPSEC** (names never leave the operator's
  environment).
- Genuinely sensitive fields (SMTP DSNs, TOTP secrets, user passwords, keys) **are** encrypted
  or hashed at rest.

If an operator ever ingests non-synthetic, real-subject mail, they should re-classify those
mailboxes and apply field-level encryption (a separate control) before doing so.

## Retention (see the DPIA + GDPR record)
- Conversation content: **soft-deleted at 6 months**, hard-deleted at 12 months (`PurgeService`, automatic).
- Audit log: **12-month retention is policy** — the integrity chain is preserved and archive/purge
  is an operator procedure; there is no automatic audit-log purge command.

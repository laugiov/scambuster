# Personal-Data Breach Notification Procedure (GDPR Article 33/34)

Complements the Incident Response Plan (`docs/runbooks/incident-response-plan.md`). This
procedure covers the **regulatory** obligations when a security incident involves personal data.

## 1. Scope trigger
A breach is a security incident leading to accidental/unlawful destruction, loss, alteration,
unauthorised disclosure of, or access to personal data. For ScamBuster the realistic breach
surface is: exposure of stored scammer content, honeypot identities, operator user accounts,
or the audit log.

## 2. Clock
- **Art 33 (to supervisory authority):** notify within **72 hours** of becoming aware, unless
  the breach is unlikely to result in a risk to individuals' rights and freedoms.
- **Art 34 (to data subjects):** notify **without undue delay** if the breach is likely to
  result in a **high** risk to them. Note: the primary data subject here is the *scammer* — a
  high-risk-to-a-victim scenario is unlikely by design, which usually lowers the Art 34 duty
  (document the assessment).

## 3. Steps
1. **Detect & record** the breach with a timestamp (the tamper-evident audit log helps
   establish scope and integrity — run `app:audit:verify-chain`).
2. **Contain** per the IRP (rotate keys, kill switch, revoke tokens/accounts).
3. **Assess** the data categories, volume, and risk to individuals (use the risk register).
4. **Decide** on notification (Art 33 to the authority; Art 34 to subjects if high-risk).
5. **Notify** with: nature of breach, categories/approx. numbers affected, likely consequences,
   measures taken. Use the operator's supervisory-authority channel.
6. **Post-mortem** (`docs/runbooks/post-mortem-template.md`) and update the risk register.

## 4. Evidence
- The append-only, HMAC-chained `audit_log` is the primary forensic record; verify its
  integrity before relying on it and preserve a copy.
- Key-rotation runbooks: `docs/runbooks/audit-hmac-key-rotation.md`, `totp-key-rotation.md`,
  `n8n-credentials.md`.

## 5. Register
Record every breach (even non-notifiable ones) in an internal breach register with the
assessment rationale — Art 33(5) requires documenting all breaches.

# Incident Response Plan (IRP)

Structured around the NIST SP 800-61 lifecycle. Scoped to a self-hosted ScamBuster
deployment. Pair with the breach-notification procedure (`docs/compliance/breach-notification-procedure.md`)
when personal data is involved, and the post-mortem template for lessons learned.

## Roles
| Role | Responsibility |
|------|----------------|
| Incident Lead | Declares the incident, owns the timeline and decisions |
| Operator/Engineer | Executes containment (kill switch, rotation, revocation) |
| Comms/Legal | Regulatory notification (Art 33/34), external comms |
See `RACI.md` for the responsibility matrix.

## 1. Preparation (before)
- Controls in place: RBAC, TOTP 2FA, HMAC audit chain, rate limits, kill switch, LLM budget
  cap, encrypted secrets, CI scanning. Runbooks for key rotation exist.
- Keep `.env` secrets in a manager; know how to reach the supervisory authority.
- Verify backups + the audit chain periodically (`app:audit:verify-chain`).

## 2. Detection & Analysis
- **Signals:** audit events (`AUTH_BRUTE_FORCE_DETECTED`, `RATE_LIMIT_EXCEEDED`,
  `INJECTION_DETECTED`, `KILL_SWITCH_TOGGLED`, budget threshold), abnormal LLM cost,
  security-dashboard alerts, container-scan findings.
- **Triage:** confirm scope via the audit log; run `app:audit:verify-chain` to prove the log
  itself wasn't tampered with; classify severity (SEV1 credential/data exposure → SEV3 nuisance).

## 3. Containment, Eradication, Recovery
| Action | How |
|--------|-----|
| Stop all outbound replies | **Kill switch** (`SCAMBUSTER_KILL_SWITCH` / admin toggle) -- audited |
| Cap/stop LLM spend | Budget cap `enforce` mode |
| Revoke a compromised session | Invalidate the user's refresh tokens; disable the account |
| Rotate a leaked key | Runbooks: `audit-hmac-key-rotation.md`, `totp-key-rotation.md`, JWT keys, `N8N_ENCRYPTION_KEY`, `APP_SECRET` |
| Contain a compromised mailbox | `app:mail-account:disable`; rotate the IMAP/SMTP app password |
| Eradicate | Patch the vector, redeploy from a clean image (Trivy-scanned), invalidate caches |
| Recover | Re-enable services, re-verify audit chain, monitor for recurrence |

## 4. Post-Incident
- Complete the **post-mortem** (`post-mortem-template.md`) within 5 business days.
- Update the **risk register** (`docs/compliance/risk-register.md`).
- If personal data was involved, ensure the Art 33/34 timeline was met.

---

## Tabletop exercise (rehearse quarterly)

> **Scenario -- Leaked LLM API key + cost spike.** Monitoring shows LLM spend at 300% of the
> daily budget; the `budget threshold` audit event fired overnight; a git push last week
> accidentally included a real `.env`.
>
> Walk the team through: (1) detect -- confirm via audit + cost dashboard; (2) contain -- kill
> switch + budget `enforce` + rotate the LLM key + rotate any other secret in the leaked
> `.env` (APP_SECRET, JWT passphrase, N8N_ENCRYPTION_KEY, AUDIT_HMAC_KEY, DB password);
> (3) eradicate -- purge the key from git history if it was committed, force-push, verify with
> gitleaks; (4) recover -- new keys deployed, audit chain re-verified; (5) assess -- was personal
> data exposed? (likely no -- scammer content only) → document the Art 33 no-notification
> decision; (6) post-mortem -- why did the pre-commit gitleaks hook not catch it?
>
> **Success criteria:** containment within 30 min, all secrets from the leaked file rotated,
> a written decision on notifiability, and one preventive action logged.

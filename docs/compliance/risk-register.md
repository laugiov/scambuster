# Risk Register

Living register of known risks and their treatment. Seeded from an internal security audit
and the threat model (`docs/10_threat_model.md`). Review quarterly and after every incident.

Likelihood/Impact: L/M/H. Status: OPEN / MITIGATED / ACCEPTED / CLOSED.

| ID | Risk | L | I | Treatment | Status |
|----|------|---|---|-----------|--------|
| R1 | Demo admin credentials committed + loaded in the public demo | M | M | Randomize the demo admin password at container start; ensure prod admin differs | OPEN |
| R2 | Refresh token stored plaintext; no reuse-detection cascade; refresh not audited | L | M | Store SHA-256, revoke token family on reuse, audit refresh | OPEN |
| R3 | `/api/metrics` + `/api/health` publicly reachable (info leak) | M | L | Require ROLE_ADMIN; keep `/healthz` public | **CLOSED** |
| R4 | Audit HMAC chain fails open silently without key | L | M | Fail-closed in prod, WARNING in dev | **CLOSED** |
| R5 | CORS wildcard on the authenticated API | M | M | Env-driven origin allowlist (`origin_regex`) | **CLOSED** |
| R6 | SSRF via attacker-supplied IOC URLs | M | H | Not in the PHP backend (constant provider URLs). Enrichment lives in n8n → audit n8n workflows for link-local/RFC1918 blocking | OPEN (external) |
| R7 | LLM prompt injection steering a persona reply | M | M | Layered guards: PromptInjectionDetector, PolicyGuard, PaymentInstigationGuard, ReplyValidator (detect + log) | MITIGATED |
| R8 | Scammer content sent to a cloud LLM (transfer/DPA) | M | M | DPA required; or run Ollama/mock (no data leaves infra) | ACCEPTED / avoidable |
| R9 | Message bodies plaintext at rest | L | L | Access control + PII-masked logs; content is attacker-supplied, not victim PII (see data-classification) | ACCEPTED |
| R10 | No SSO/OIDC (enterprise IAM) | M | M | Add opt-in generic OIDC authenticator (password login stays default) | IN PROGRESS |
| R11 | Single points of failure (1× pg/redis/backend); no circuit breaker | L | M | Kill switch + budget cap for graceful degradation; HA is a roadmap item | ACCEPTED |
| R12 | HMAC/CSRF timing-unsafe compares; Content-Length DoS bypass; dual mail parsers | L | L | Hardening backlog | OPEN (minor) |
| R13 | Honeypot identities leaking to a public artifact | L | H | Pre-commit OPSEC gate + automated pre-publish check; names never in git/DB seeds | MITIGATED |

## Treatment ownership
See `docs/runbooks/RACI.md`. Each OPEN item has an owner and a target review date at the
next quarterly review.

# RACI — Security & Operations Responsibilities

R = Responsible (does the work) · A = Accountable (final owner, one per row) ·
C = Consulted · I = Informed.

For a small deployment one person may hold several roles; the point is that every activity
has a single **A**. Roles: **Lead** (project owner / BDFL, see GOVERNANCE.md), **Ops**
(operator/engineer running the instance), **Sec** (security reviewer), **Legal** (DPO / legal).

| Activity | Lead | Ops | Sec | Legal |
|----------|:----:|:---:|:---:|:-----:|
| Threat model & risk register upkeep | A | C | R | I |
| Security patch / dependency updates (Dependabot) | A | R | C | — |
| Secret & key rotation (runbooks) | I | R/A | C | — |
| Access control / RBAC grants | A | R | C | — |
| Incident declaration & command | C | R | C | I |
| Incident containment (kill switch, revoke, rotate) | I | R/A | C | I |
| Breach notification decision (Art 33/34) | C | I | C | R/A |
| Audit-log integrity verification | I | R/A | C | I |
| GDPR record / DPA upkeep | I | C | C | R/A |
| Data-retention enforcement (`app:cleanup:weekly`) | I | R/A | — | C |
| Post-mortem authoring | A | R | C | I |
| Release approval / merge to main | R/A | C | C | — |

Adjust to your team; keep exactly one **A** per row.

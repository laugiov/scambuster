# Factory setup — state

The setup runs in phases, each restartable from a fresh session. **This file is
the memory**: chat history is not. Update it at the end of every phase.

Branch: `claude/scambuster-factory-setup-5eind5`. Nothing is pushed during setup;
no PR is opened.

## Phases

| Phase | What it delivers | Status |
|---|---|---|
| 0 | Discovery: `factory/DISCOVERY.md`, `factory/found-issues.md` | done |
| 1 | Spec Kit v0.16.4 installed and committed, constitution written, `factory/speckit-inventory.md` | done |
| 2 | `docs/factory/pipelines.md`, `.claude/commands/factory-{feature,bug,security}.md`, `factory/security-findings.md` | done |
| 3 | `factory/gates.yaml`, `.claude/agents/*`, objection format, iteration rules, `docs/factory/templates/gate-report.md`, `scripts/factory/adversarial-review.sh` | not started |
| 4 | `.github/workflows/factory-gates.yml`, security-scan tool decision, PR template fields | not started |
| 5 | `factory/benchmark/`, `docs/factory/README.md` | not started |

## Forward references — documents that point at files not yet created

Phase 2 documents already reference Phase 3 and Phase 4 artifacts. A pipeline run
started before those phases land will hit a missing file. This is the prompt's
sequencing, not an oversight, but it is real:

| Referenced by | Missing file | Lands in |
|---|---|---|
| all three commands, `pipelines.md` | `factory/gates.yaml` | Phase 3 |
| `/factory-feature`, `/factory-security` | `docs/factory/templates/gate-report.md` | Phase 3 |
| `/factory-feature` | `.claude/agents/{security,architecture,qa}-reviewer` | Phase 3 |
| `/factory-feature`, `pipelines.md` | traceability job in `.github/workflows/factory-gates.yml` | Phase 4 |
| all three commands | the `Pipeline:` line in the PR template | Phase 4 |

## Decisions taken (do not relitigate without saying so)

| # | Decision | Where it came from |
|---|---|---|
| 1 | `.claude/`, `.specify/`, `specs/` are versioned; only credential-bearing and per-developer files stay ignored | maintainer, phase 1 |
| 2 | Definition of done uses **PHPStan level 8 over `backend-symfony/src`** — what CI actually runs | maintainer, phase 1 |
| 3 | Constitution says password hashing **must resolve to** Argon2id; `algorithm: auto` stays as-is | maintainer, phase 1 |
| 4 | Factory scope is **backend + frontend**; `n8n/`, `infra/`, `scripts/` are out of pipeline scope but bound by the security rules | maintainer, phase 1 |
| 5 | Spec Kit pinned to `v0.16.4`; upgrading is a deliberate, separate change | phase 1 |

## Still open — blocking Phase 3

Three sensitive-path questions from `factory/DISCOVERY.md` §5:

1. Does `frontend-react/src/api/client.ts` (holds the JWT in memory, drives the
   401-refresh loop) count as a sensitive path?
2. Does `n8n/` count, given it orchestrates ingestion and sending?
3. Session handling: proposal is to cover it through `config/packages/security.yaml`
   plus `src/UI/Http/Auth/**` rather than as a separate "session" entry, since the
   API firewalls are stateless JWT.

Also open, from `DISCOVERY.md` §6 and not yet needed: whether `composer.json`'s
`>=8.2` floor should be raised to match the 8.3 runtime (an application change,
out of scope for this setup).

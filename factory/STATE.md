# Factory setup — state

The setup runs in phases, each restartable from a fresh session. **This file is
the memory**: chat history is not. Update it at the end of every phase.

Branch: `claude/scambuster-factory-setup-5eind5`.

**Current state**: the five setup phases ran on this branch with nothing pushed
and no PR opened, as the setup required. That phase is over. The maintainer then
pushed the branch and opened **PR #61** (`Pipeline: chore`), and work has
continued on the branch since: the `chore` pipeline type itself was added *after*
the PR was open, because PR #61 could not truthfully declare any of the three
original types.

So a session reading this file should expect an open PR, a branch that is pushed,
and commits made after the PR was created. Push freely; do not merge — the
factory never merges, and that rule outlives the setup.

## Phases

| Phase | What it delivers | Status |
|---|---|---|
| 0 | Discovery: `factory/DISCOVERY.md`, `factory/found-issues.md` | done |
| 1 | Spec Kit v0.16.4 installed and committed, constitution written, `factory/speckit-inventory.md` | done |
| 2 | `docs/factory/pipelines.md`, `.claude/commands/factory-{feature,bug,security}.md`, `factory/security-findings.md` | done |
| 3 | `factory/gates.yaml`, `.claude/agents/*`, objection format, iteration rules, `docs/factory/templates/gate-report.md`, `scripts/factory/adversarial-review.sh` | done |
| 4 | `.github/workflows/factory-gates.yml`, `.semgrep/`, patch-coverage + traceability scripts, PR template fields | done |
| 5 | `factory/benchmark/`, `docs/factory/README.md` | done |

## Forward references — documents that point at files not yet created

None left. Phase 4 created `.github/workflows/factory-gates.yml` and the
`Pipeline:` line in the PR template, which were the last two.

## Decisions taken (do not relitigate without saying so)

| # | Decision | Where it came from |
|---|---|---|
| 1 | `.claude/`, `.specify/`, `specs/` are versioned; only credential-bearing and per-developer files stay ignored | maintainer, phase 1 |
| 2 | Definition of done uses **PHPStan level 8 over `backend-symfony/src`** — what CI actually runs | maintainer, phase 1 |
| 3 | Constitution says password hashing **must resolve to** Argon2id; `algorithm: auto` stays as-is | maintainer, phase 1 |
| 4 | Factory scope is **backend + frontend**; `n8n/`, `infra/`, `scripts/` are out of pipeline scope but bound by the security rules | maintainer, phase 1 |
| 5 | Spec Kit pinned to `v0.16.4`; upgrading is a deliberate, separate change | phase 1 |
| 6 | Frontend auth files are **sensitive**: `api/client.ts`, `store/authStore.ts`, `components/layout/AuthGuard.tsx`, `pages/Login.tsx` | delegated to Claude, phase 2 review |
| 7 | `n8n/workflows/**` and `n8n/n8n-init.sh` are **sensitive** — out of pipeline scope, but a change there is an escalation trigger | delegated to Claude, phase 2 review |
| 8 | **No separate "session" path.** Covered by `security.yaml`, `framework.yaml` and `src/UI/Http/Auth/**`, since the API firewalls are stateless JWT | delegated to Claude, phase 2 review |
| 9 | Security scan tool is **Semgrep** (pinned 1.173.0, CI-only, no composer dependency), with repo-specific rules in `.semgrep/constitution.yml` that encode the constitution | maintainer, phase 4 |
| 10 | `factory-gates.yml` does **not** duplicate tests/PHPStan/coverage; it waits for `ci.yml`'s jobs on the same SHA instead | maintainer, phase 4 |
| 11 | Coverage gate is **patch coverage**, computed inside `ci.yml`'s existing test job; Codecov keeps the project-level base comparison | maintainer, phase 4 |
| 12 | A fourth pipeline type, **`chore`**, for process/docs/CI changes that touch no application code. Added after PR #61 — the factory's own setup PR could not declare any of the three existing types truthfully | maintainer, PR #61 review |

Reasoning for 6–8 is in `factory/DISCOVERY.md` §5 under "Resolved". The
sensitive-path list is now closed and becomes `escalation_triggers.sensitive_paths`
in `factory/gates.yaml` in Phase 3.

## Setup complete

All five phases are done. The factory has never been exercised on a real change:
every pipeline, gate and reviewer profile is untested against actual work, and no
benchmark has been run — that is deliberate, since the benchmark must be run by
the maintainer with defects they seeded themselves.

Nothing in this setup ran the repository's own gates either: this environment has
no Docker daemon, so `make test`, `make stan` and the style check could not
execute here. Semgrep is the one gate that was actually run, and its rules are
verified against the real codebase (27 findings across 11 sites).

## Still open — not blocking

- Whether `composer.json`'s `>=8.2` floor should be raised to match the 8.3
  runtime (`DISCOVERY.md` §6.1). An application change, out of scope for this
  setup.
- **Two thresholds are uncalibrated and will need a first pass of real PRs**:
  the patch-coverage minimum (80%, in `ci.yml`) and whether Semgrep's registry
  rulesets (`p/php`, `p/security-audit`) can be made blocking. Neither could be
  set from a checkout alone — the registry rulesets have never been run against
  this codebase, so their finding count is unknown. The registry step is
  `continue-on-error: true` until that triage happens.
- The constitution's layering rules are enforced against the **diff**, not the
  whole tree: 11 pre-existing violations are recorded in `factory/found-issues.md`
  (issues 5, 6, 8). Fixing them is separate work, each through its own pipeline.

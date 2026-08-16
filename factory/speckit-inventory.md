# Spec Kit inventory (installed state)

Authoritative list of what the Spec Kit init actually installed. Every factory
document must use **these** names — not names from memory or from the upstream
README, which have changed across versions.

## Installation

| Item | Value |
|---|---|
| Source | `git+https://github.com/github/spec-kit.git` |
| Pinned tag | `v0.16.4` (annotated tag `c3708c9` → commit `d1f50fc`) |
| Installed version | `specify-cli 0.16.4` |
| Install command | `uv tool install specify-cli --from git+https://github.com/github/spec-kit.git@v0.16.4` |
| Init command | `specify init --here --integration claude --script sh` |
| Script flavour | `sh` (bash scripts under `.specify/scripts/bash/`) |
| Files added | 30, all under `.claude/` and `.specify/`. No existing file modified. |

Reproducing this: run the init in an empty directory first and diff against the
repo before running it in place. That is how the "no existing file touched" claim
above was verified.

## Installed skills — use these names verbatim

They are Claude Code **skills** (`.claude/skills/<name>/SKILL.md`), invoked as
slash commands.

| Skill | Role in the factory |
|---|---|
| `/speckit-constitution` | Create/update `.specify/memory/constitution.md`. |
| `/speckit-specify` | Write the feature spec. Creates `specs/<branch>/spec.md`. |
| `/speckit-clarify` | Up to 5 targeted questions to remove ambiguity. Run **before** `/speckit-plan`. |
| `/speckit-plan` | Implementation plan and design artifacts. |
| `/speckit-tasks` | Dependency-ordered `tasks.md`. |
| `/speckit-analyze` | Cross-artifact consistency report (spec ↔ plan ↔ tasks). Run after `/speckit-tasks`, before `/speckit-implement`. |
| `/speckit-checklist` | Quality checklist for requirement completeness and clarity. Run after `/speckit-plan`. |
| `/speckit-implement` | Execute `tasks.md`. |
| `/speckit-converge` | Assess codebase against spec/plan/tasks, append remaining work as tasks. |
| `/speckit-taskstoissues` | Convert tasks into GitHub issues. **Not used by the factory** — the factory ends in a PR, not in issue creation. |

## Layout that the factory depends on

```
.claude/skills/speckit-*/SKILL.md    the ten skills above
.specify/memory/constitution.md      the project constitution (authoritative)
.specify/templates/                  spec / plan / tasks / checklist templates
.specify/scripts/bash/               create-new-feature.sh, setup-plan.sh, setup-tasks.sh, …
.specify/workflows/speckit/workflow.yml
specs/<branch-name>/spec.md          per-feature specs, created by create-new-feature.sh
```

`.specify/feature.json` is per-checkout state (which feature is current) and is
gitignored by `.specify/.gitignore`. `SPECIFY_FEATURE_DIRECTORY` overrides it.

## Versioning note

`.specify/init-options.json` and `.specify/integration.json` both record
`0.16.4`. Upgrading Spec Kit is a deliberate change: re-run the pinned install
with the new tag, re-run the init in a scratch directory, diff, and update this
file plus every factory document that names a skill.

# Gate report — `<G1 | G2 | SEC-G1 | SEC-G2>`

<!--
One report per gate. The maintainer reads gate reports and deltas — not full
artifacts — except at G1 (they read the spec) and G2 (they read the PR).

So: this document must be readable on its own. "See the spec" is not a summary.
Keep it under a page. Delete the guidance comments; keep the headings.

Store as: specs/<branch>/gate-reports/<gate-id>.md
For the bug pipeline, which has no specs/ directory: factory/gate-reports/<branch>-<gate-id>.md
-->

| | |
|---|---|
| **Pipeline** | `feature` \| `bug` \| `security` |
| **Gate** | G1 / G2 / SEC-G1 / SEC-G2 |
| **Branch** | |
| **Spec** | `specs/<branch>/spec.md`, or "none — the failing test is the spec" |
| **Date** | |
| **Decision requested** | One sentence: what exactly you are asking the maintainer to approve. |

## What changed since the last gate

<!--
For the first gate of a run, this is what the run produced so far. For a later
gate, this is the delta only — the maintainer already read the earlier report,
and repeating it hides the new part.
-->

-

## Objections raised

<!--
Standard format, one per line:
  BLOCKING|ADVISORY ; requirement ID or failing-test path ; short description

BLOCKING requires a requirement ID that exists in the spec, or a failing
executable test. Everything else is ADVISORY and cannot block the stage.

Include objections that were raised and then withdrawn — a reviewer changing
their mind is information, and deleting the line hides it.
-->

```
```

## How each was resolved

| Objection (field 2) | Raised by | Resolution | Evidence |
|---|---|---|---|
| | | fixed / withdrawn / downgraded to advisory / escalated | commit, test, file:line |

<!--
"Downgraded to advisory" needs a reason: the cited ID was not in the spec, or no
failing test backed it. State which.
-->

## Advisory notes carried forward

<!--
Advisories are never deleted. Each one either becomes a follow-up entry
(factory/found-issues.md, or factory/security-findings.md with a SEC-### id) or
is explicitly accepted here. "Accepted" is a decision and needs a name against it.
-->

-

## Escalation triggers

| Trigger | Fired | Detail |
|---|---|---|
| sensitive_paths | yes / no | which paths |
| migration_present | yes / no | which migration |
| public_api_changed | yes / no | which route or DTO |
| new_dependency | yes / no | which package, and why it is needed |
| large_diff | yes / no | changed lines vs the 400 limit |
| adversarial_not_converged | yes / no | link the disagreement summary |

<!--
Every row gets an answer. "None fired" must be a statement someone made after
checking, not an empty table.
-->

## Gate criteria

<!-- The auto_pass_criteria for this transition in factory/gates.yaml. -->

| Criterion | Result | How verified |
|---|---|---|
| `make test` | pass / fail / **not run** | paste the summary line |
| `make stan` | pass / fail / **not run** | |
| style (`--dry-run`) | pass / fail / **not run** | |
| frontend gates | pass / fail / n/a | only if `frontend-react/` was touched |
| coverage vs base | | |
| patch coverage | | % of changed lines covered |
| mutation score (Infection) | | MSI on changed files — reported, not blocking |
| TDD order | pass / fail / n/a | feature pipeline only; list any `TDD-exempt` claims |
| documentation impact | | docs updated, or the `Docs-impact: none` reason |
| `/speckit-analyze` | | |
| tasks cite requirement IDs | | |

<!--
"Not run" is an allowed and sometimes necessary answer — for example in an
environment with no Docker daemon, where none of these can execute. It is never
acceptable to write "pass" for something that was not run.
-->

## Disagreement summary

<!-- Only when the adversarial loop did not converge after 2 iterations. Delete otherwise. -->

**Unresolved objection**: `<the line in standard format>`

- **Reviewer's claim**, and its evidence:
- **Author's counter-claim**, and its evidence:
- **What would settle it**: the test that would need to exist, or the decision only the maintainer can make.

## Maintainer decision

<!-- Left blank by the factory. Filled in by the maintainer. -->

- [ ] Approved
- [ ] Approved with the advisories above accepted
- [ ] Changes requested

**Notes**:

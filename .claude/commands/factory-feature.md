---
description: Run the feature pipeline — spec, clarify, plan, G1, tasks, analyze, implement, G2
argument-hint: [what the feature should do]
---

# Feature pipeline

Feature to build: **$ARGUMENTS**

You are running the ScamBuster feature pipeline. Read
`docs/factory/pipelines.md` and `.specify/memory/constitution.md` before you
start; they govern this run and this file only sequences them.

## Non-negotiable

- **You never merge and never push to `main`.** This pipeline ends at an open PR.
- **You stop at G1 and G2** and wait for the maintainer. Do not continue past a
  human gate on your own judgement, however obvious the answer seems.
- **You do not fix things you notice in passing.** Log them in
  `factory/found-issues.md` and carry on.
- The escalation triggers in `factory/gates.yaml` apply throughout. When one
  fires, say so out loud and hand the decision to the maintainer.

## Sequence

**1. Specify** — run `/speckit-specify` with the feature description above.
Requirements get `FR-###` IDs, success criteria get `SC-###`. Keep implementation
out of the spec: it states what must be true, not how.

**2. Clarify** — run `/speckit-clarify`. Every `[NEEDS CLARIFICATION]` marker must
be resolved with the maintainer. Do not answer them yourself by picking the most
likely option; that is the exact ambiguity the step exists to remove.

**3. Plan** — run `/speckit-plan`. The plan must state explicitly:
- which of the five layers are touched (`Domain`, `Application`, `Infrastructure`,
  `UI`, `Security`), and every dependency direction it introduces;
- every port interface added or changed;
- every database migration required;
- whether any sensitive path from `factory/gates.yaml` is touched;
- the projected diff size. **Above 400 changed lines, the plan says how the work
  splits into stacked PRs** — one reviewable increment each, each green alone.

**4. ▣ G1 — HUMAN GATE. STOP HERE.**
Produce a gate report from `docs/factory/templates/gate-report.md`. Have
`security-reviewer` review the spec and `architecture-reviewer` review the plan;
both emit objections in the standard format. Resolve BLOCKING objections before
presenting. Then print the spec path, the plan path, the report, and a one-line
statement of what you are asking the maintainer to approve. **Then stop and say
nothing further until they answer.**

**5. Tasks** — run `/speckit-tasks`. Then rewrite each task line so it cites the
requirement IDs it covers:

```
- [ ] T012 [P] [US1] (FR-003, FR-004) Add PersonaSelector port in src/Application/…
```

The Spec Kit template does not add these; the factory requires them and CI fails
the PR without them. A task covering no requirement is either scope nobody asked
for or a missing requirement — resolve which, do not leave it uncited.

**6. Analyze** — run `/speckit-analyze`. Resolve every inconsistency it reports
before implementing. Have `qa-reviewer` review the task list for test coverage of
each requirement.

**7. Implement** — run `/speckit-implement`, one task at a time:
- one commit per task, message citing the requirement IDs;
- tests ship in the same commit as the behaviour they cover;
- after each task, the suite stays green — you do not accumulate a red suite and
  fix it at the end;
- if a task turns out to need a decision the spec does not settle, stop and ask.
  Do not widen the spec by implementing your best guess.

**8. ▣ G2 — HUMAN GATE. STOP HERE.**
Open the PR (never merge it) with:
- the `Pipeline: feature` line and the spec ID in the body, per the PR template;
- both gate reports linked;
- a traceability table: every `FR-###` → the tasks and commits that cover it;
- every escalation trigger that fired.

Then tell the maintainer what to review and remind them to run `make test`
locally — CI does not run the `functional` suite. **Stop.**

## Exit criteria

`make test` green, `make stan` clean at level 8 over `src`, `make cs-fixer` with
no diff, frontend gates green if `frontend-react/` was touched, coverage not below
base, and every `FR-###` traceable to a task and a commit.

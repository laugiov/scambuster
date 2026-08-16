---
description: Run the bug pipeline — failing reproduction test committed first, then the fix
argument-hint: [what is broken, and how to trigger it]
---

# Bug pipeline

Bug report: **$ARGUMENTS**

You are running the ScamBuster bug pipeline. Read `docs/factory/pipelines.md` and
`.specify/memory/constitution.md` first.

**There is no spec document in this pipeline. The failing test is the spec.**

## Non-negotiable

- **The reproduction test is written and committed before any production change.**
  Not after. Not "alongside". A test written after the fix proves only that the
  code does what it now does.
- **You must see the test fail.** Run it, capture the failure output, and paste it
  into the PR. A reproduction you never watched go red is a guess.
- **You never merge and never push to `main`.**
- **You do not refactor.** Log anything else you notice in
  `factory/found-issues.md`.

## Sequence

**1. Reproduce.** Understand the bug well enough to trigger it deterministically.
Choose the cheapest layer that still exercises the real defect: a unit test if the
bug is in domain logic, an integration test if it needs the database, a functional
test if it needs the HTTP boundary. Do not reach for a functional test out of
convenience — CI does not run that suite.

**2. Commit the failing test alone.** Its message states the observed wrong
behaviour and the expected behaviour. Nothing else in the commit.

```
test(<context>): reproduce <the wrong behaviour>

Observed: …
Expected: …
Fails at <file:line> with <the actual failure>.
```

**3. Fix.** The smallest change that turns the test green. If the fix looks like
it needs a design decision — which of two behaviours is correct, a new
abstraction, a schema change — **stop**: this is a feature, not a bug. Say so and
restart under `/factory-feature`.

**4. Verify.** All of these, actually run:
- the reproduction test passes;
- `make test` is green (unit + integration + functional);
- `make stan` is clean at level 8 over `src`;
- `make cs-fixer` produces no diff;
- if `frontend-react/` was touched: typecheck, lint, test, build.

Report what you ran and what it printed. "Should pass" is not a result.

**5. Check escalation.** The triggers in `factory/gates.yaml` apply to bug fixes
exactly as to features. Say explicitly which fired and which did not:
- sensitive path touched;
- migration present;
- public API changed;
- new dependency;
- diff above 400 changed lines;
- adversarial loop did not converge.

If any fired, the maintainer reviews before the PR is considered ready, even
though this pipeline has no G1.

**6. Open the PR.** Never merge it. Body carries `Pipeline: bug`, the failing
output from step 1, and the two commits in order — red, then green — so a
reviewer can see the test came first.

## Exit criteria

The reproduction test passes, the full suite is green, static analysis is clean,
and the PR shows the red-then-green commit pair.

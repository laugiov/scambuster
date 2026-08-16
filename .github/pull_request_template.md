<!--
The three lines below are read by .github/workflows/factory-gates.yml. Keep them
at the top, one per line, exactly in this shape.

  Pipeline:  feature | bug | security   (required — selects which gates apply)
  Spec:      path to spec.md            (required for feature; omit otherwise)
  Gates:     links to the gate reports  (required when a gate report exists)

For a change made outside the factory, use `Pipeline: bug` for a fix and
`Pipeline: feature` for anything specified. There is no "none": every PR into
main goes through a pipeline.
-->

Pipeline:
Spec:
Gates:

## Summary

<!-- What does this PR do? Link the issue if applicable. -->

Closes #

## Changes

-
-
-

## Type of Change

- [ ] Bug fix (non-breaking change that fixes an issue)
- [ ] New feature (non-breaking change that adds functionality)
- [ ] Refactoring (no functional change)
- [ ] Documentation update
- [ ] Infrastructure / CI change

## Checklist

- [ ] `make test` passes (unit + integration + functional — CI does not run functional)
- [ ] `make stan` passes (PHPStan level 8 over `src`)
- [ ] `make cs-fixer` applied and left the worktree unchanged
- [ ] New code has tests
- [ ] Documentation updated if needed
- [ ] No secrets or credentials in the code
- [ ] Follows DDD architecture (controllers delegate to handlers)

### Factory gates

- [ ] Every commit and task cites a requirement id (feature pipeline)
- [ ] Gate reports linked in the `Gates:` line above
- [ ] Escalation triggers listed in the gate report, including the ones that did not fire
- [ ] For a bug or security PR: the reproduction or exploit test is committed **before** the fix, and its failure output is in this description

## Screenshots (if applicable)

<!-- For frontend changes, include before/after screenshots -->

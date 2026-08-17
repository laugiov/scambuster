<!--
The four lines below are read by .github/workflows/factory-gates.yml. Keep them
at the top, one per line, exactly in this shape.

  Pipeline:       feature | bug | security | chore  (required — selects the gates)
  Spec:           path to spec.md                   (required for feature only)
  Gates:          links to the gate reports         (when a gate report exists)
  Deploy-impact:  what a deployer needs to know     (required for a chore PR that
                  touches infra/docker/**, docker-compose*.yml or .env.dist)

Choosing:
  feature   new or changed behaviour, specified first
  bug       behaviour is wrong; a failing reproduction test is committed first
  security  a vulnerability, or auth/crypto/outbound-content changes
  chore     process, docs or CI only — changes NO application code

`chore` skips the traceability gate, so it is checked: a chore PR that touches
backend-symfony/{src,tests,migrations} or frontend-react/src is rejected. There
is no "none" — every PR into main declares one of the four.

`Deploy-impact:` is the softer second tier. Container images, compose files and
the environment template decide what runs in production and on the public demo,
and a chore PR is allowed to change them — but not silently, because "chore"
promises no behaviour change. Say what a deployer would want to have been told;
"none — <reason>" is a fine answer, "none" on its own is not.
-->

Pipeline:
Spec:
Gates:
Deploy-impact:

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

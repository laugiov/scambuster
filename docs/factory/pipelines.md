# The three pipelines

Every change to ScamBuster goes through one of three pipelines: **feature**,
**bug**, or **security**. Pick by the nature of the change, not by its size.

| Pipeline | Command | Use when |
|---|---|---|
| Feature | `/factory-feature` | New or changed behaviour that needs to be specified before it is built. |
| Bug | `/factory-bug` | Existing behaviour is wrong and the correct behaviour is not in dispute. |
| Security | `/factory-security` | A vulnerability, or a change to authentication, crypto, or what the system sends to third parties. |

If a bug fix turns out to need a design decision, stop and restart it as a
feature. If a feature turns out to be a vulnerability, stop and restart it as
security. Switching pipeline mid-flight is normal; carrying on in the wrong one
is not.

## Rules common to all pipelines

1. **Every pipeline ends in a pull request with its gate reports attached. The
   factory never merges — the maintainer merges.** No pipeline has a step that
   pushes to `main`, closes an issue, or merges anything.
2. **The escalation triggers in `factory/gates.yaml` apply to every pipeline,
   including bug fixes.** A one-line fix that touches a sensitive path or adds a
   migration is escalated exactly like a feature.
3. **Small diffs are the rule.** A change projected above **400 changed lines**
   is split into stacked PRs, each independently reviewable and each green on its
   own. Splitting is a decision made at plan time, not a rescue after the fact.
4. **The constitution governs.** `.specify/memory/constitution.md` is
   authoritative. Where a pipeline step and the constitution disagree, the
   constitution wins and the pipeline is the thing that gets fixed.
5. **Human gates are never auto-passed.** A gate marked blocking in
   `factory/gates.yaml` stops the pipeline until the maintainer answers. In the
   security pipeline this holds regardless of any configuration.
6. **Definition of done is the constitution's, not the pipeline's.** A pipeline
   step that reports success without having actually run `make test`, `make stan`
   and the style check has reported nothing. Careful with the last one:
   **`make cs-fixer` rewrites files** — it has no `--dry-run`. The check is that
   it leaves the worktree unchanged; CI runs `--dry-run --diff` instead.
7. **Nothing outside the change's scope gets touched.** A defect noticed in
   passing goes to `factory/found-issues.md`; it does not get fixed in the same
   PR.

## Identifier conventions

Taken from the installed Spec Kit templates — use these, they are what the tools
generate.

| Prefix | Meaning | Lives in |
|---|---|---|
| `FR-###` | Functional requirement | `specs/<branch>/spec.md` |
| `SC-###` | Success criterion (measurable) | `specs/<branch>/spec.md` |
| `US#` | User story | `specs/<branch>/spec.md` |
| `T###` | Task | `specs/<branch>/tasks.md` |
| `SEC-###` | Security finding (root cause or variant) | `factory/security-findings.md` |

"Requirement ID" throughout the factory means `FR-###` or `SC-###`. In the bug
pipeline, where there is no spec, the failing test's path plays that role.

---

## 1. `/factory-feature`

Full Spec Kit flow. Two human gates.

```
/speckit-specify → /speckit-clarify → /speckit-plan
     → ▣ G1  maintainer approves spec and plan
        → /speckit-tasks → /speckit-analyze
           → /speckit-implement, task by task, small commits
              → ▣ G2  maintainer reviews the PR and runs functional tests
```

**Steps**

1. `/speckit-specify` — the spec. Requirements get `FR-###` IDs, success criteria
   get `SC-###`. No implementation detail in the spec.
2. `/speckit-clarify` — resolve every `[NEEDS CLARIFICATION]` marker. The spec
   does not reach G1 with markers left in it.
3. `/speckit-plan` — the plan. It names the layers touched, the ports added or
   changed, the migrations needed, and the projected diff size. If that projection
   exceeds 400 lines, the plan says how the work splits into stacked PRs.
4. **G1 — blocking.** The maintainer reads the spec and the plan. `security-reviewer`
   reviews the spec; `architecture-reviewer` reviews the plan. Gate report produced.
5. `/speckit-tasks` — the task list. **Every task cites the requirement IDs it
   covers**, written into the task line itself: `T012 [US1] (FR-003, FR-004) …`.
   The Spec Kit template does not do this by default; the factory requires it and
   the traceability job in `.github/workflows/factory-gates.yml` enforces it.
6. `/speckit-analyze` — cross-artifact consistency. Any inconsistency it reports
   is resolved before implementation starts, not noted and carried.
7. `/speckit-implement` — one task at a time, one commit per task, each commit
   message citing the requirement IDs. Tests ship with the behaviour, in the same
   commit.
8. **G2 — blocking.** The PR is opened with both gate reports attached. The
   maintainer reviews it and runs `make test` locally, because CI does not run the
   `functional` suite.

**Exit criteria**: definition of done in the constitution, plus every `FR-###` in
the spec traceable to at least one task and one commit.

---

## 2. `/factory-bug`

Reproduction first. No spec document — **the failing test is the spec.**

```
failing test, committed → fix → green
```

**Steps**

1. **Reproduce.** Write an automated test that fails because of the bug. Run it,
   watch it fail, and record the failure output. A test that has never been seen
   red proves nothing.
2. **Commit the failing test on its own**, before any production change. This
   commit is the specification of the bug, and its message states the observed
   wrong behaviour and the expected one.
3. **Fix.** The smallest change that turns the test green. No refactoring in the
   same commit.
4. **Verify.** The reproduction test passes; the full suite is green; static
   analysis is clean.

**Exit criteria**: the reproduction test passes, `make test` is green,
`make stan` is clean, style is clean. The PR shows both
commits — red then green — so a reviewer can see the test was written first.

**Escalation still applies.** If the fix touches a sensitive path, adds a
migration, changes a public API, or exceeds 400 lines, the maintainer reviews it
even though a bug fix has no G1.

**If the reproduction cannot be written**, stop and escalate. A bug that cannot
be reproduced by a test is either not understood yet, or is a design question in
disguise — in both cases it is not a bug-pipeline change.

---

## 3. `/factory-security`

Root cause first. Two human gates, neither of which can be skipped by any
configuration.

```
root-cause analysis → ▣ approve root cause → failing exploit test (PoC)
   → fix → variant analysis → ▣ approve fix and variant list
```

**Steps**

**(a) Root-cause analysis, written.** A document stating:
- **Vulnerability class** — injection, broken access control, race, insecure
  deserialization, weak crypto, information disclosure, and so on. Name the class,
  because the class is what the variant analysis searches for.
- **Entry points** — the routes, commands, message handlers or ingestion paths
  through which untrusted input reaches the flaw. Cite `file:line`.
- **Impact** — what an attacker gets: which data, whose account, what side effect.
  State the preconditions honestly, including authentication level required.

**(b) HUMAN GATE — blocking, never auto-passed.** The maintainer approves the root
cause. Approving the root cause is what authorises work on the fix; a fix built
before this gate is a fix built against a guess.

**(c) Failing exploit test (PoC), committed.** An automated test that exercises
the vulnerability and fails on the current code. Committed before the fix, same
rule and same reason as the bug pipeline. It lives with the other tests and stays
in the suite permanently as a regression guard.

**(d) Fix.** The smallest change that closes the vulnerability. If the fix must be
larger than 400 lines, split it and say so in the PR — but never split it in a way
that leaves the vulnerability half-open between PRs.

**(e) Variant analysis.** Search the **whole codebase** for other instances of the
same vulnerability class, including the frontend and the n8n workflows. List every
candidate with `file:line` and a one-line assessment.

> **Variants are logged as new security entries in `factory/security-findings.md`
> with fresh `SEC-###` IDs. They are never fixed in the same PR. One
> vulnerability, one PR.** Fixing three variants at once means one review covering
> three attack surfaces, and it means the PR cannot be reverted without reopening
> the others.

An empty variant list is a valid result, but it must be an actual search with the
search terms recorded — not an assumption.

**(f) HUMAN GATE — blocking, never auto-passed.** The maintainer reviews the fix
and the variant list together, then decides which variants get their own pipeline
run and in what order.

**Exit criteria**: the exploit test passes, the definition of done holds, the
variant list is recorded with IDs, and both gates carry a signed-off gate report.

**Disclosure**: nothing about an unfixed vulnerability goes into a public issue,
a public PR description, or a commit message before the maintainer says so. The
PR body describes the fix in terms of behaviour, and the details live in the gate
report until disclosure is agreed.

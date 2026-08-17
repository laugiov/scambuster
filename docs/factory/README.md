# The factory, day to day

A one-page guide. The detail lives in [pipelines.md](pipelines.md); this is what
you need to actually use it.

The idea in one sentence: **specify before building, stop at the points where a
human should decide, and never let the machine merge its own work.**

## Starting a change

Pick the pipeline by the nature of the work, then run its command:

| You want to | Run | What happens first |
|---|---|---|
| Build or change behaviour | `/factory-feature <what it should do>` | A spec is written. You approve it before any code. |
| Fix something broken | `/factory-bug <what breaks, how to trigger it>` | A test that reproduces the bug is written and committed **before** the fix. |
| Handle a vulnerability | `/factory-security <the suspected flaw>` | A root-cause analysis is written. You approve it before any fix is even sketched. |
| Change process, docs or CI only | no command — put `Pipeline: chore` in the PR | Ordinary CI, no traceability. Rejected if it touches application code. |

Not sure? If the correct behaviour is obvious and only the code is wrong, it is a
bug. If someone has to decide what "correct" means, it is a feature. If it touches
authentication, crypto, or what the system sends to a stranger, it is security.

## Three rules that apply to every change

**Test first, always.** The failing test is committed before the code it covers —
in every pipeline, features included. In a feature that means two commits per
task: the test-only one, then the implementation. CI checks the order in the
commit history, so it cannot be arranged after the fact. A task that truly cannot
be driven by a test carries `TDD-exempt: <reason>` in its commit body; that does
not fail the build, it shows up in the gate report for you to judge.

The order gate is deliberately dumb: an empty test satisfies it. `qa-reviewer`
reads the tests and asks the question the gate cannot — would this have failed
before the implementation landed?

**Green before review.** The suite is green before any reviewer or gate is asked
to look. Nobody reviews code that does not run.

**Documentation is part of the change.** Touch an HTTP controller, a console
command, routing, bundle config, a migration, a UI page, `.env.dist` or the
Makefile, and `docs/` moves too — or the PR body says why not:

```
Docs-impact: none — internal endpoint, absent from the API reference
```

Saying "none" is fine and takes ten seconds. Saying nothing fails the build,
because an unticked checkbox is how a runbook goes stale without anyone deciding
that it should.

**On test quality**, Infection runs on the files a PR changes and reports its
mutation score in the gate report. Coverage says a line ran; mutation says a test
would have noticed if the line were wrong. It does not block yet — it has never
run in CI on this codebase, so the threshold has to be calibrated on real PRs
first.

Switching mid-flight is normal and expected. Continuing in the wrong pipeline is
not.

## What you have to do at each gate

The factory stops and waits. Nothing proceeds until you answer.

**G1 — spec and plan (feature).** You read the spec. Not the plan in detail, not
the reviewer objections — the spec. It is the one document where a
misunderstanding is cheap to fix and expensive to miss. Ask yourself: if someone
built exactly this and nothing more, would I be happy?

**G2 — the pull request (feature).** You read the diff. Then **run `make test`
locally** — CI does not run the `functional` suite, roughly 855 controller tests,
so a green CI is weaker than a green `make test`.

**Security gate 1 — the root cause.** You approve the diagnosis before any fix
exists. If the analysis has a fix in it, that is a process violation: send it
back. A fix written before the cause is agreed is a fix built against a guess.

**Security gate 2 — the fix and the variants.** You review the fix, then decide
which of the listed variants get their own run and in what order. Variants are
never fixed in the same PR.

**Bug fixes have no gate** — but the escalation triggers still apply. If the fix
touches a sensitive path, adds a migration, changes a public API, adds a
dependency, or exceeds 400 changed lines, it comes to you anyway.

## Reading a gate report

You read reports and deltas, not whole artifacts — except at G1 and G2. A report
fits on a page and has five parts:

1. **What changed since the last gate.** The delta only.
2. **Objections**, one per line, in one format:
   `BLOCKING | ADVISORY ; requirement id or test path ; description`
   **BLOCKING** means it cites a requirement from the spec or comes with a failing
   test. Everything else is **ADVISORY** — it may be right, and it cannot stop the
   pipeline. That rule stops a reviewer blocking on a hunch, and stops one
   inventing a requirement id to gain authority.
3. **How each was resolved.** Fixed, withdrawn, downgraded, escalated.
4. **Advisory notes carried forward.** Never deleted. Each is either logged as a
   follow-up or explicitly accepted by you.
5. **Escalation triggers.** Every row answered, including the ones that did not
   fire — "none fired" has to be a statement someone made after checking.

Two things to look for: a criterion marked **`not run`** (allowed and honest —
`pass` for something that never ran is not), and a **disagreement summary**,
which appears when reviewers did not converge in two rounds. That one is a real
decision waiting for you: two claims, two pieces of evidence, and what would
settle it.

## Trusting the gates

**A red run is never merged on the assumption that it is infrastructure.** Either
the cause is found and named, or the run is re-run and passes — there is no third
outcome, and "probably a transient" is not a cause. The rule does not soften for a
failure that looks like someone else's network: PR #62 was merged past five red
runs diagnosed as a transient GitHub 504, and the same install then failed on
`main` at `3abdb7c`, in four jobs at once, for a reason that had been sitting in
the log the whole time — unauthenticated dependency downloads hitting GitHub's
per-IP rate limit, produced by this workflow's own concurrency. A repeated
failure is evidence against the transient reading, not noise around it. Judge the
diagnosis by whether it explains the repetition, and treat a failure you decide to
ignore as a gate switched off for every change after this one, not just for yours.

## Running the benchmark

The factory claims its gates catch defects. This checks whether they do.

**You run it, in a fresh session, with defects you seeded yourself.** The one
rule that makes the result mean anything: the answer key lives **outside this
repository**, because a pipeline agent can read anything inside the worktree.

```bash
python3 factory/benchmark/score.py \
  --ground-truth ~/scambuster-benchmarks/run-01.yaml \
  specs/042-persona-mirror/gate-reports/
```

A defect counts as **detected** only when a *blocking* objection cites its
requirement id. An advisory mention scores **partial** — someone noticed and
nothing stopped, which for shipping purposes is a miss. Read the detection rate
next to the count of blocking objections on requirements you did not seed: a
reviewer that blocks everything scores 100%.

Full protocol: [factory/benchmark/README.md](../../factory/benchmark/README.md).

## Where things live

| | |
|---|---|
| The rules that govern everything | `.specify/memory/constitution.md` |
| Gates, auto-pass criteria, escalation triggers | `factory/gates.yaml` |
| Reviewer profiles | `.claude/agents/` |
| Pipeline commands | `.claude/commands/` |
| Gate report template | `docs/factory/templates/gate-report.md` |
| Specs, per feature | `specs/<branch>/` |
| Known defects, not fixed | `factory/found-issues.md` |
| Security findings and variants | `factory/security-findings.md` |
| Setup state and decisions taken | `factory/STATE.md` |

## Two things the factory will never do

**It will never merge.** Every pipeline ends at an open pull request. You merge.

**It will never fix something it noticed in passing.** A defect spotted outside
the current change goes to `factory/found-issues.md` and stays there until you
decide it deserves its own run. That is why the file has entries in it already —
including hardcoded database credentials found while building the gates.

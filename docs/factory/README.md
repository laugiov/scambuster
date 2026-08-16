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

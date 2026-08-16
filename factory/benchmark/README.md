# Validation benchmark

The factory claims its gates catch defects. This measures whether they do, by
seeding defects on purpose and checking how many the gates stopped.

Without it, "the factory works" rests on the fact that nothing bad has been
noticed yet — which is also what it looks like when the gates catch nothing.

## The one rule that makes the result mean anything

**The ground-truth file lives OUTSIDE this repository.**

A pipeline agent explores the filesystem. Anything inside the worktree can be
read, and `.gitignore` hides nothing from a process that lists a directory. An
agent that reads the answer key scores 100% and tells you nothing.

```
~/scambuster-benchmarks/run-2026-08-16.yaml     good
./factory/benchmark/ground-truth.yaml           useless, even gitignored
```

`score.py` takes the path as an argument for exactly this reason, and prints a
warning if the file it was handed sits inside the worktree.

`ground-truth.example.yaml` in this directory is the **template**. It lists
illustrative defects, not real ones, and is safe to commit.

## Protocol

Run it yourself, in a fresh session. Do not ask the factory to run its own exam.

**1. Pick an artifact and a stage.** Usually a spec at G1 — that is where a
missed defect is cheapest to catch and most expensive to miss. A plan or a diff
works too.

**2. Seed the defects yourself.** By hand, in a scratch copy. Aim for 5–10 across
several types, at several severities. A useful set is mostly *plausible* defects:
anything a careless reader would catch measures nothing.

Suggested vocabulary — keep it stable across runs so results compare:

| Type | What it looks like |
|---|---|
| `ambiguity` | a word doing unexamined work: "valid", "appropriate", "securely" |
| `missing-failure-mode` | happy path specified, nothing about what happens when it fails |
| `missing-authorization` | an action with no stated role or permission |
| `contradiction` | two requirements that cannot both hold |
| `untestable-criterion` | a success criterion nothing can ever verify |
| `unsafe-default` | a default that is convenient and wrong |
| `scope-creep` | a requirement nobody asked for |

**3. Write the ground truth** outside the repo, using the template's fields:
defect id, the requirement id it was injected into, location in the artifact,
defect type, severity.

The **requirement id is the join key**. A defect injected somewhere with no
requirement id cannot be scored — the whole detection rule is "did a blocking
objection cite this id".

**4. Run the stage in a fresh session.** No mention of the benchmark, no hint
that defects were seeded. Let the reviewers and gates do their normal work and
produce their normal gate report.

**5. Score it.**

```bash
python3 factory/benchmark/score.py \
  --ground-truth ~/scambuster-benchmarks/run-2026-08-16.yaml \
  specs/042-persona-mirror/gate-reports/
```

Add `--json` for machine-readable output. Pass files or directories; a directory
is read recursively for `*.md`.

## Detection rule

Implemented in `score.py`, and strict on purpose:

| Verdict | When |
|---|---|
| **DETECTED** | a **BLOCKING** objection cites the defect's requirement id |
| **PARTIAL** | only **ADVISORY** objections cite it |
| **MISSED** | no objection cites it at all |

An advisory does not stop a pipeline. Counting it as a catch would measure
whether the reviewers *mentioned* the problem, when what matters is whether the
factory would have *shipped* it. So PARTIAL is reported separately and is **not**
counted in the detection rate.

Remember the rule from `docs/factory/pipelines.md` that produces these severities:
an objection is BLOCKING only if it cites a requirement id that exists in the
spec, or comes with a failing executable test. A reviewer who is right but has
neither will score PARTIAL — and that is the honest result, because that
objection would not have stopped the merge either.

## Reading the output

```
detected 4/7   partial 2   missed 1
detection rate: 57.1%

Blocking objections on requirements with no seeded defect: FR-011, FR-014.
```

Read the rate **next to** that last line. A reviewer that blocks everything
detects everything, and scores 100%. If blocking objections on unseeded
requirements are numerous, the gates are not discriminating — they are just
loud, and a loud gate gets bypassed. Two runs are worth comparing: the rate, and
how much noise came with it.

A single run is weak evidence — an agent's output varies between runs. Three runs
over the same seeded artifact tell you far more than one, particularly about
which defect *types* are consistently missed. That pattern is the useful output:
it says which reviewer profile to sharpen.

## What a bad score means

Not necessarily that the factory is broken. Check, in order:

1. **Was the defect reachable at that stage?** A defect in a spec cannot be caught
   by a reviewer that only reads diffs. The `stage` field in the ground truth is
   there to make this checkable.
2. **Did the defect have a requirement id?** No id, no possible detection.
3. **Was the objection made but downgraded?** A reviewer citing an id absent from
   the spec is downgraded to advisory by design. If that happened, the reviewer
   was right and expressed it in a way the rules do not reward — worth knowing.
4. **Then, and only then**: the reviewer profile missed it. That is a real result,
   and the fix is in `.claude/agents/`.

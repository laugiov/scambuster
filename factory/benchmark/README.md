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

The defect vocabulary — the taxonomy — is **append-only**:

| Type | What it looks like | Since |
|---|---|---|
| `ambiguity` | a word doing unexamined work: "valid", "appropriate", "securely" | 001 |
| `missing-failure-mode` | happy path specified, nothing about what happens when it fails | 001 |
| `missing-authorization` | an action with no stated role or permission | 001 |
| `contradiction` | two requirements that cannot both hold | 001 |
| `untestable-criterion` | a success criterion nothing can ever verify | 001 |
| `unsafe-default` | a default that is convenient and wrong | 001 |
| `scope-creep` | a requirement nobody asked for | 001 |
| `unjustified-assumption` | a technical or architectural choice asserted with no reason given | 002 |

**Append-only means: add a term, never rename or remove one.** The per-type
detection rates are the benchmark's most useful output — they say which reviewer
profile to sharpen — and they are only readable across runs if a type means the
same thing in run 002 as in run 012. Renaming a category silently rewrites the
history of every run that used it, and a run scored under the old name can no
longer be compared to anything. If a term turns out to be wrong, add the right
one and leave the old one in the table with a note; the cost of a slightly untidy
table is nothing next to the cost of a comparison you cannot trust.

`unjustified-assumption` arrived that way. Run 002 needed a category the table
did not have, used it, and left the decision to the maintainer rather than
bending an existing term — the nearest candidates, `unsafe-default` and
`scope-creep`, mean something else. It is added here rather than renamed in the
run, so run 002's per-type rates stand as measured.

**3. Write the ground truth** outside the repo, using the template's fields:
defect id, the requirement id it was injected into, location in the artifact,
defect type, severity.

The **requirement id is the join key**. A defect injected somewhere with no
requirement id cannot be scored — the whole detection rule is "did a blocking
objection cite this id". `score.py` therefore refuses to run unless every defect
carries a `requirement_id` in `FR-###` or `SC-###` form, rather than scoring a
malformed key as a string of misses and printing a 0% rate that reads like a
factory failure.

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

Implemented in `score.py`. A defect is caught when the objection it drew is at
least as strong as the defect deserved — so the rule needs the **seeded
severity**, and `score.py` refuses to run on a ground truth whose entries lack a
valid one:

| Seeded severity | Caught by an objection of | Why |
|---|---|---|
| `blocker` | **BLOCKING** | it must not ship |
| `major` | **BLOCKING** | it must not ship |
| `minor` | **ADVISORY** (BLOCKING also counts) | saying it out loud is the right call |

| Verdict | When |
|---|---|
| **DETECTED** | an objection cites the requirement id at or above that bar |
| **PARTIAL** | an objection cites it below the bar — a `blocker` or `major` that drew only advisories |
| **MISSED** | no objection cites it at all |

PARTIAL is reported separately and is **not** counted in the detection rate. A
blocker raised as an advisory does not stop a pipeline, and counting it would
measure whether the reviewers *mentioned* the problem when what matters is
whether the factory would have *shipped* it.

Remember the rule from `docs/factory/pipelines.md` that produces these
severities: an objection is BLOCKING only if it cites a requirement id that
exists in the spec, or comes with a failing executable test. A reviewer who is
right about a blocker but has neither will score PARTIAL — the honest result,
because that objection would not have stopped the merge either.

**This rule changed on 2026-08-17, after run 002 was scored.** It used to be flatly
"DETECTED iff BLOCKING", at every severity. That scored a `minor` defect correctly
raised as ADVISORY as a miss: it punished the reviewers for proportionate
judgement, and the only way to score well under it was to block on everything —
which is exactly what the unseeded-blocking count exists to warn about. The two
pressures pointed in opposite directions and the scoring rule was the one that
was wrong.

**Run 002's 60% stands as the number for that run.** It is not re-scored under
this rule and it is not comparable to any rate produced after it. Both of run
002's `minor` defects happened to draw BLOCKING objections, so re-scoring would
not move the figure — but a number that means "measured under the old rule" is
worth more than one silently recomputed, and the first rate produced under this
rule is a new baseline rather than a comparison.

`score.py` also now reports **minor defects that drew a BLOCKING objection**. They
count as detected, and they are the same over-blocking the unseeded count
measures, arriving on a seeded requirement where that count cannot see it.

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

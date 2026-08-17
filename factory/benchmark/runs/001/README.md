# Benchmark run 001 — ABANDONED before scoring

**Status**: abandoned, not scored. No detection rate exists for this run and none
should be quoted from it.

**Artifact**: `specs/001-cluster-stix-export/spec.md`
**Stage**: G1
**Abandoned**: 2026-08-17, after step A, before any defect was seeded.

This record exists because a benchmark that was started and thrown away is itself a
result. Deleting the directory would leave run 002 looking like the first attempt,
and the next person to hit the same trap would pay for it twice.

## Why it was abandoned

### 1. The clean spec entered git history (fatal, by construction)

The protocol in `factory/benchmark/README.md` protects the *ground truth* by keeping
it outside the worktree. It says nothing about the **clean spec**, and that turns out
to matter just as much.

The clean spec was committed as `9ca9b59` before the maintainer seeded defects into
it. Once the seeded version exists, `git diff` — or `git log -p` after the seeded
version is committed — shows exactly which lines carry the defects. The G1 reviewers
are subagents with `Bash` and `Grep`.

A reviewer that reads the diff instead of the spec scores near 100% and measures
nothing. It is the same corruption as a reviewer reading the answer key, arriving
from the other direction, and it is worse in one respect: reading the ground truth
produces an obviously absurd result, while reading the diff produces a *plausible*
one that nobody questions.

The commit was not a slip in judgement — the artifact was deliberately left untracked
for exactly this reason. A stop hook in the execution environment requires every
untracked file to be committed and pushed, and it fired at the end of the step that
produced the spec. See `factory/STATE.md`, "Benchmark artifacts live outside the
repository".

Mitigating this by instructing the reviewers not to run `git diff` would have been a
guarantee by instruction, against agents that explore filesystems by design. Run 002
removes the possibility instead of forbidding the act.

### 2. The feature was already implemented (independent, and enough on its own)

Run 001 specified *"export a threat actor cluster as a STIX 2.1 bundle, on demand,
from the UI"*. That capability already exists end to end:

| | |
|---|---|
| `backend-symfony/src/UI/Http/Clustering/ExportClusterStixController.php` | `GET /api/v1/clusters/{id}/export/stix` |
| `frontend-react/src/pages/ClusterDetail.tsx:104` | the button, the blob, the download |
| `docs/23_reading_the_threat_actor_screen.md:134` | documents the endpoint |

A reviewer exploring the repository would legitimately object that the spec proposes
work already done. Those objections are correct and they are noise for the
measurement: they land on requirements with no seeded defect, inflate the
false-positive line, and depress the reading of a rate that is supposed to describe
reviewer calibration and nothing else.

This alone would have justified restarting. It was found while grounding the spec, not
by auditing it.

## What survives

The spec itself is a legitimate specification of behaviour the existing export path
should have, and the gaps it names are real:

- the endpoint is gated on `ioc:read` while every other export surface uses
  `ioc:export` (`ExportClusterStixController.php:34` vs `ExportIocsStixController`,
  `ExportConversationStixController`);
- the UI swallows every export error — `catch { // silently fail }` at
  `ClusterDetail.tsx:117`;
- no export attempt is audited.

None of that is benchmark work and none of it is fixed here. If it is worth doing it
is worth its own `/factory-feature` run against that spec, and the maintainer decides
whether to start one.

`specs/001-cluster-stix-export/` is kept, and its history is left alone. It is
contaminated as a *benchmark* artifact, which says nothing about its quality as a
spec.

## Successor

Run 002, on a feature verified absent from the repository, with the clean spec written
outside the worktree and never copied into it.

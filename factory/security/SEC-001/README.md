# SEC-001 — security pipeline run

| | |
|---|---|
| **Finding** | `SEC-001` — hardcoded database credentials in source (`factory/security-findings.md`) |
| **Pipeline** | `security` (`docs/factory/pipelines.md`) |
| **Branch** | `claude/ci-integrity-security-triage-ynda74` |
| **Started** | 2026-08-18 |
| **Status** | stopped at **SEC-G1**, awaiting maintainer approval of the root cause |

## Contents

| File | What it is |
|---|---|
| [`root-cause.md`](root-cause.md) | Stage (a). Three sections, no fix. |
| [`gate-report-SEC-G1.md`](gate-report-SEC-G1.md) | The gate report for the first human gate. |
| [`friction.md`](friction.md) | Running note: where the pipeline was overhead, where a gate caught something. Updated as the run proceeds. |

## Where this run's files live, and why here

`docs/factory/templates/gate-report.md:10-11` gives two storage locations:
`specs/<branch>/gate-reports/` for the feature pipeline, and
`factory/gate-reports/<branch>-<gate-id>.md` for the bug pipeline, "which has no
specs/ directory". **The security pipeline is named in neither**, and it also has
no `specs/` directory. Rather than pick one and leave a future run to guess, this
run keeps everything under `factory/security/<SEC-###>/`, mirroring
`factory/benchmark/runs/002/` — the only per-run directory pattern the repository
already has. The convention gap is logged in `friction.md`; whether to write it
into the template is the maintainer's call, not something to settle by
precedent set in passing.

## One thing a future reader must not assume

The original SEC-001 prompt — session 2 of `prompts-test-factory.md` — was **not
available** when this run started. The framing used here was written fresh from
the finding and the pipeline definition. **This run is therefore not comparable
with that earlier session**: any difference in what the pipeline produced could
be a difference in the pipeline or a difference in the prompt, and nothing here
distinguishes them. Do not read this run as a second data point on the same
input.

Two additions in this run's framing that were not in any earlier prompt, and
that shaped the output:

1. During variant analysis (stage e), the run also searches for instances of
   **SEC-002's** class — a service reachable without authentication because the
   network is assumed trusted — and reports both classes. SEC-002 itself is not
   fixed here.
2. The root-cause analysis was written knowing it would be read for **what is
   absent from it**, not for whether what is present is correct. That is a
   deliberate bias in how `root-cause.md` is written: unverified claims are
   marked unverified, and each section ends with what it could not establish.

# TTP Extraction Quality

**Status**: method frozen, figures not yet produced
**Taxonomy version**: 1.0
**Codebook version**: 1.0.0 (`ttp-codebook-v1.md`)
**Spec**: 001-extraction-quality-audit

This document is where the measured precision of the TTP extractor goes. The method
below is complete and frozen. The figures are not in it yet, because no scoring round
has been run: producing them needs the production database and two people.

Until the Scoring results section carries real numbers, **no metric applies to the TTP
module**, and the notices saying so in the README and in `docs/06_roadmap.md` stay as
they are. They are updated only once this document carries figures, and they link here
when they do (Spec 001 FR-010).

---

## 1. What is measured, and what is not

**Measured**: precision on a sample. Of the TTP tags the extractor placed, what share
a human judges to match the taxonomy definition for the span the extractor pointed at.

**Not measured, by design**:

- **Recall.** Nothing here says how many behaviours the extractor missed. Measuring
  that needs exhaustive annotation of full messages, which is Spec 004's job on the
  public dataset.
- **Anything about the scam corpus.** The sample is drawn from a honeypot inbox. It
  describes the extractor's behaviour on that traffic, not fraud in general.
- **Anything beyond the sample.** The figures are descriptive counts. There is no
  extrapolation to the full corpus and no confidence interval (Constitution I,
  Spec 001 FR-007).

---

## 2. Method

### 2.1 Draw the sample

```bash
php bin/console scambuster:ttp:audit-sample \
    --seed 4242 --limit 100 \
    --output var/audit/sample-2026-xx-xx.csv
```

The seed is recorded in this document and in the audit log. Re-running the command
with the same seed against the same data returns the same rows, so a third party can
re-pull an identical sheet (Spec 001 FR-001, SC-004, Constitution VII).

Two draw modes exist, and the choice is part of the published method:

| Mode | Command | What its pooled figure means |
|------|---------|------------------------------|
| Uniform (default) | `--seed S --limit 100` | Every observation equally likely. The pooled figure **is** an overall precision on the extractor's output as it stands. |
| Stratified | `--seed S --limit 100 --stratified` | Round-robin across taxonomy codes. Rare codes get covered, but codes are over-sampled relative to their real frequency, so the pooled figure is **not** an overall precision. Read per code only. |

**The published overall figure comes from a uniform draw.** This resolves the open
question in Spec 001 FR-002: the honest reading of an overall precision number
requires that the sample reflect the real distribution of the extractor's output, and
a stratified draw destroys that property. Per-code coverage of rare codes is a
separate question and gets a separate, clearly-labelled stratified run when it is
needed — its numbers never feed the headline figure.

The exported CSV contains verbatim scammer evidence. It is the one sanctioned egress
for that text and it stays internal, on the machines of the people scoring it
(Constitution III, Spec 001 FR-009).

### 2.2 Score it

Two scorers work from `ttp-codebook-v1.md`, independently, on their own copy, with no
discussion until both sheets are complete (FR-004). Each appends the six scoring
columns the codebook defines and fills their own verdict column.

The sheets are then merged. Where the two verdicts agree, the agreed verdict becomes
`verdict_final`. Where they differ, an adjudicator picks one and writes a one-line
`adjudication_reason` (FR-006). Adjudication happens after both sheets are complete,
never before.

### 2.3 Compute the figures

```bash
php bin/console scambuster:ttp:audit-score var/audit/scored-2026-xx-xx.csv \
    --seed 4242 --draw uniform \
    --output var/audit/results-block.md
```

The command refuses to compute figures from an incomplete sheet: any row that is not
double-scored, not adjudicated, or that carries a disagreement with no written reason
is listed and the run exits non-zero. That is deliberate — a partly-scored sheet must
not turn quietly into a published number.

Its markdown output reads only verdict labels, codes and statuses. No verbatim
evidence can travel with it, so the block it produces is pasted into section 3 below
as-is.

---

## 3. Scoring results

> **Not yet produced.** No scoring round has been run. This section stays empty until
> one has been, and no figure from this project's TTP module may be quoted anywhere
> until it is filled (Constitution I).

<!-- Paste the output of scambuster:ttp:audit-score here. -->

---

## 4. Provenance to record with the figures

Filled in together with section 3. Every field is required; a figure without them is
not reviewable (FR-008).

| Field | Value |
|-------|-------|
| Seed | _not yet run_ |
| Draw mode | _not yet run_ |
| Sample size | _not yet run_ |
| Date of the draw | _not yet run_ |
| Date of the scoring | _not yet run_ |
| Taxonomy version | 1.0 |
| Codebook version | 1.0.0 |
| Extraction model distribution of the sampled rows | _not yet run_ |
| Prompt version distribution of the sampled rows | _not yet run_ |
| Scorer A role | _not yet assigned_ |
| Scorer B role | _not yet assigned_ |
| Adjudicator role | _not yet assigned_ |
| Replacements (unsamplable rows) | _not yet run_ |

Scorers are recorded by role, not by name. A name appears only with that person's
written consent (Constitution, Governance).

The extraction model and prompt version distributions matter because they scope the
result: a precision figure describes the model and prompt that produced the sampled
rows, not the module in the abstract. If a later model change lands, the figure
describes the older extractor until a new round is run.

---

## 5. Limits

These belong next to the figures wherever they are quoted, not only here.

- **Descriptive, not inferential.** Counts on one sample. No confidence interval, no
  extrapolation to the corpus.
- **Precision only.** Says nothing about behaviours the extractor missed.
- **Two scorers, one project.** Both scorers work on the same project and read the
  same codebook. Agreement between them measures whether the codebook is applied
  consistently — it is not independent external validation.
- **Honeypot traffic.** The sample comes from one honeypot inbox. It carries whatever
  bias that inbox has in scam type, language and volume.
- **Point in time.** The figure describes the extraction model and prompt version of
  the sampled rows. It expires when either changes.
- **The taxonomy is judged by its own definitions.** A tag is `correct` when it
  matches the taxonomy definition. If a definition is itself poor, a `correct` verdict
  inherits that. Scorer notes on weak definitions feed the next taxonomy version
  (Spec 003) rather than the verdict.

---

## 6. What this unblocks

- **Spec 004** shares the codebook, so the dataset labels and these verdicts are
  produced under one set of rules.
- **Spec 006** submissions have to cite a quality figure and its limits. Until section
  3 is filled, nothing is filed.
- Public texts and conference material can quote the figure — with the method and the
  limits attached, never the number alone (Constitution I).

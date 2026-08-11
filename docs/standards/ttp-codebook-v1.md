# TTP Scoring Codebook v1

**Codebook version**: 1.0.0
**Status**: FROZEN — do not edit during a scoring round
**Taxonomy version covered**: 1.0 (27 entries, `SB-T001`..`SB-T027`)
**Used by**: Spec 001 (extraction quality audit) and Spec 004 (annotated reference dataset)

This is the scoring guide two people follow, independently, when they judge whether a
TTP tag placed by the extractor is correct. It is frozen before scoring starts: if a
rule has to change mid-round, the round is void and restarts under a new codebook
version. That rule exists so a published agreement figure means something.

Read this file end to end before scoring your first row. Do not talk to the other
scorer about individual rows until both sheets are complete.

---

## 1. What a scorer is judging

One row of the audit CSV is one observation: the extractor claimed that TTP code
`X` is present in message `M`, and pointed at a span of text as its evidence.

You judge one thing only:

> Does the tagged code match its taxonomy definition, for the quoted or located span?

You are **not** judging whether the extractor missed other codes in the same message.
Missed tags are recall, and this audit does not measure recall (Spec 004 does). A row
is not "incorrect" because something else in the message went untagged.

You are **not** judging whether the taxonomy entry is well designed. If you think an
entry is badly defined, write it in the notes column; the verdict still follows the
definition as written.

---

## 2. The three verdicts

Every row gets exactly one of these.

### `correct`

The behaviour described by the taxonomy definition is present in the evidence span,
or in the immediate sentence that contains it.

Use `correct` when:

- The span shows the behaviour plainly, even when the wording differs from the
  examples in the taxonomy. The examples are illustrations, not a whitelist.
- The span shows the behaviour and *also* shows another behaviour. A message segment
  can carry several TTPs at once; the tag under review only has to be one of them.
- The span is a paraphrase of the message rather than a literal quote, and the
  behaviour is present in the message at that point (see §4, paraphrased rows).

### `incorrect`

The behaviour described by the definition is not present in the span, or the span
shows a different behaviour that a different taxonomy code describes better.

Use `incorrect` when:

- The span shows nothing that the definition describes.
- Another code in the taxonomy is the plain fit and the tagged code is not. "Better
  fit exists" alone is not enough — the tagged code must actually fail its own
  definition. See §3 for the neighbour pairs where this decision is easy to get
  wrong.
- The span quotes the *target's* words, not the scammer's. TTPs describe scammer
  behaviour only. A tag whose evidence is an outbound sentence is `incorrect`.
- The span quotes boilerplate that carries no behaviour: a signature block, a
  disclaimer, a quoted earlier message, an automatic footer.

### `unclear`

You cannot decide from the row and the message alone.

Use `unclear` when:

- The message is too short, too corrupted, or too heavily machine-translated to read.
- The span is genuinely ambiguous between two codes and the definitions do not
  separate them for this text.
- The evidence points outside the message body, or to a span you cannot locate.

`unclear` is a real answer, not a way to avoid a hard call. But do not use it for
"probably correct" or "probably incorrect" — commit to the verdict you lean towards
and record the doubt in the notes column instead. A scoring round where `unclear`
runs above roughly 10% of rows says the codebook needs work, not the extractor.

---

## 3. Neighbour pairs

These are the code pairs where scorers most often diverge. The rule for each pair is
binding: apply it even when the other reading feels defensible.

| Pair | Rule |
|------|------|
| `SB-T001` unsolicited opportunity lure vs `SB-T002` institutional authority impersonation | T001 is about *what is offered* (a windfall, a contract). T002 is about *who the sender claims to be*. A message that does both carries both; judge the tag under review on its own half. |
| `SB-T002` institutional authority vs `SB-T003` commercial brand | T002 is a public body: government, bank as regulator, court, police, UN, IMF. T003 is a private company acting as a vendor or service: courier, marketplace, retailer. A retail bank chasing "account validation" is T003; a central bank ordering compliance is T002. |
| `SB-T005` legitimacy document display vs `SB-T011` plausibility repair | T005 is *showing* a document as proof. T011 is *explaining away* an inconsistency in words. Sending a certificate to answer a doubt is T005; a sentence explaining why the certificate is late is T011. |
| `SB-T012` advance fee demand vs `SB-T013` payment instrument designation | T012 is the demand for a payment ("a $850 charge is required"). T013 is the coordinates to pay to (IBAN, wallet, receiver name). A message with both carries both. A demand with no coordinates is T012 only. |
| `SB-T012` advance fee vs `SB-T018` fee laddering | T018 requires a *new* fee appearing after an earlier fee or agreement in the same conversation. If the row's message is the first fee in the thread, it is T012, not T018. When you cannot see earlier turns, score T018 `unclear`. |
| `SB-T017` urgency deadline vs `SB-T020` threat of loss | T017 is a clock ("24 hours", "expires tonight"). T020 is a consequence (confiscation, arrest, closure). A deadline *with* a stated consequence carries both. |
| `SB-T020` threat of loss vs `SB-T025` final ultimatum | T025 is terminal: the scammer says this exchange ends. A threat that still invites a next step is T020. |
| `SB-T014` victim data harvesting vs `SB-T004` malicious resource delivery | T004 is the link or attachment. T014 is the request for the data. A link *to* a form asking for bank details carries both, one per half. |
| `SB-T023` off-channel solicitation vs `SB-T024` contact exclusivity | T023 moves the exchange elsewhere. T024 narrows it to one contact and forbids others. "WhatsApp me" is T023. "Reply only to this address, do not call the bank" is T024. |
| `SB-T008` fabricated social proof vs `SB-T027` fabricated gains display | T008 is about *other people* who benefited. T027 is about *this target's* balance, profit or return. |
| `SB-T006` rapport personalization vs `SB-T021` emotional pressure | T006 builds a bond with no ask attached. T021 leverages hardship or guilt to push an action. "How is your family" is T006. "My daughter is in hospital, please hurry" is T021. |

When a pair is not in this table and you cannot separate the two codes from their
definitions, score `unclear` and note both codes. Those notes drive the next
codebook version.

---

## 4. Row situations

### Paraphrased rows

Some rows carry evidence text that summarises the message instead of quoting it, and
carry no usable offsets. Judge these from the full message body: if the behaviour is
present anywhere in the scammer's message, the tag is `correct`. Flag the row
`paraphrased` in the flag column. These rows are reported separately, because they
are scored under a weaker rule than quoted rows.

### Unsamplable rows

A row whose message or conversation was soft-deleted between the draw and the scoring
cannot be judged. Mark it `unsamplable` in the flag column, leave both verdicts
empty, and replace it with the next row from the same seeded draw order. Log the
replacement (which obs_id left, which entered, why) in the audit log. Unsamplable
rows count in neither the numerator nor the denominator.

### Review-status rows

Rows with `status = review` are scored exactly like confirmed rows, under the same
definitions. They are reported as a separate line in the results, because a
confirmed-only precision figure and an all-rows figure answer different questions.

### Duplicate spans

If the same code is tagged twice on the same message with overlapping spans, score
each row on its own. Deduplication is an extractor concern, not a scoring one.

---

## 5. The scored sheet

Take the CSV produced by `scambuster:ttp:audit-sample` and add these columns. Column
names are exact — the scoring command reads them by name.

| Column | Filled by | Values |
|--------|-----------|--------|
| `verdict_a` | scorer A | `correct` / `incorrect` / `unclear` |
| `verdict_b` | scorer B | `correct` / `incorrect` / `unclear` |
| `verdict_final` | adjudicator | `correct` / `incorrect` / `unclear` |
| `adjudication_reason` | adjudicator | one line, required on every row where A and B differ |
| `flag` | either scorer | empty, `paraphrased`, `unsamplable`, or `replaced` |
| `notes` | either scorer | free text, optional |

Rules:

- A and B fill their column without seeing the other's. Two copies of the file, one
  each, merged afterwards.
- `verdict_final` equals the agreed verdict where A and B agree. Where they differ,
  the adjudicator picks one and writes `adjudication_reason`.
- The adjudicator may be scorer A or B. Adjudication happens after both sheets are
  complete and never before.
- The scored sheet stays internal. It carries verbatim scammer evidence, so it never
  leaves the machines of the people scoring it (Constitution III).

---

## 6. Producing the figures

```bash
php bin/console scambuster:ttp:audit-score var/audit/scored-sheet.csv \
    --output=var/audit/results.md
```

The command computes raw agreement, Cohen's kappa, overall precision and per-code
counts. It reads the evidence column only to check the file shape and never prints
it, so its output is safe to paste into a public document.

What the figures mean:

- **Raw agreement** — share of rows where A and B chose the same verdict.
- **Cohen's kappa** — agreement corrected for what chance alone would produce. Read
  it as: below 0.40 the codebook is not doing its job; 0.40–0.60 is moderate;
  0.60–0.80 is substantial; above 0.80 is strong. These are conventional reading
  bands, not thresholds this project claims to have validated.
- **Precision** — `correct` over (`correct` + `incorrect`) on adjudicated verdicts.
  `unclear` rows sit outside both terms and are reported as their own count.

The figure is descriptive of the sample. It is not extrapolated to the corpus, and
no confidence interval is claimed (Constitution I, Spec 001 FR-007).

---

## 7. Changing this codebook

A change bumps the codebook version and voids any round in progress:

- PATCH — typo or wording fix that changes no rule.
- MINOR — a new neighbour-pair rule, or a new row situation.
- MAJOR — a change to what `correct` means, or to the verdict vocabulary.

Every results document names the codebook version it was scored under. Two results
documents under different codebook versions are not comparable, and neither says the
extractor changed.

# ScamBuster Reference Dataset — TTP Labels

**Dataset version**: 1.0.0-unlabelled
**Taxonomy version**: 1.0
**Codebook version**: 1.0.0
**Proposed licence**: CC-BY-4.0 (see §6 — not yet confirmed)

The public sample `scambuster-dataset-sample.json` ships 36 conversations and 247
messages, 134 of them inbound, with IOC annotations and no TTP labels. This
directory adds the TTP layer: for every inbound message, which scammer behaviours
from the closed taxonomy a human found in it, and where in the text.

What that buys, and why it is worth doing: a labelled corpus turns a taxonomy from a
claim into a testable object. Anyone can run their own extractor over the same 134
messages, score it against these labels, and disagree with this project in public
with evidence. That is the whole point.

---

## 1. Current status

**Annotation has not started.** The label file carries a slot for all 134 inbound
messages, every one unannotated. Nothing here is a published result yet.

```bash
python3 scripts/standards/validate-dataset-labels.py
# Inbound messages in the sample: 134
# Annotated: 0/134 (0%)
# Double-annotated: 0/134 (0%, floor 30%)
```

The scaffolding is complete and the checks run. What remains is the human work:
reading 134 messages against the codebook, and a second annotator doing at least 30%
of them independently.

---

## 2. Files

| File | What it is |
|------|------------|
| `../scambuster-dataset-sample.json` | The corpus itself. Unchanged by this work. |
| `ttp-labels-v1.json` | The labels: one slot per inbound message. |
| `ttp-unlisted-log.json` | Behaviours seen in the corpus that no taxonomy code describes. |
| `../backend-symfony/config/standards/taxonomy-v1.0.json` | The closed vocabulary the codes come from. |
| `../docs/standards/ttp-codebook-v1.md` | The rules an annotator applies. Shared with the extraction-quality audit. |

The labels live beside the corpus rather than inside it. The sample file is already
published and referenced elsewhere; rewriting it to add a field would invalidate
anyone's copy. A separate file keyed by `(conversation_id, message_index)` leaves it
untouched.

---

## 3. Label format

```json
{
  "conversation_id": "317f4c27-d6e3-49a8-8045-d441c46c3cab",
  "message_index": 0,
  "body_sha256": "cfc7d550...",
  "body_length": 1221,
  "annotated": true,
  "ttps": [
    { "code": "SB-T001", "evidence_start": 42, "evidence_end": 96 }
  ]
}
```

- `message_index` is the position in the conversation's `messages` array, counting
  outbound messages too. It addresses a message without needing the sample to carry
  message ids.
- `body_sha256` pins the exact text the offsets were measured against. If the sample
  is ever regenerated with shifted text, the digest stops matching and the validator
  says the offsets are stale — instead of letting them silently point at the wrong
  words.
- `evidence_start` / `evidence_end` are character offsets into `body`, half-open.
  They must select non-whitespace text.
- `annotated: true` with an empty `ttps` list is a real result: a human read the
  message and found no taxonomy behaviour in it. That is different from an
  unannotated slot, and the two must never be conflated — the difference is exactly
  what recall is measured from.

Outbound messages are never labelled. The taxonomy describes scammer behaviour, and
the outbound side of these conversations is this project's own bait.

---

## 4. Annotating

1. Read `../docs/standards/ttp-codebook-v1.md` end to end. It is the same codebook
   the extraction-quality audit uses, at the same version, so labels and audit
   verdicts are produced under one set of rules.
2. Work through the slots in `ttp-labels-v1.json`, in file order.
3. For each inbound message: mark every taxonomy behaviour present, with the span
   that shows it. A message commonly carries several. Set `annotated` to `true`
   whether or not you found anything.
4. When you meet a behaviour no code describes, do **not** invent a code. Add an
   entry to `ttp-unlisted-log.json` describing it in plain words, with the
   conversation and message it came from.
5. A second annotator repeats steps 2–4 independently on at least 30% of the
   messages, recording their message keys in `double_annotated`.
6. Compute raw agreement and Cohen's kappa on that subset and record them in
   `agreement`.

Validate as you go:

```bash
python3 scripts/standards/validate-dataset-labels.py            # structural, passes while in progress
python3 scripts/standards/validate-dataset-labels.py --complete # release gate
```

The default mode runs in CI, so a malformed label fails the moment it is written
rather than at the end. `--complete` additionally requires full coverage, the
double-annotation floor, recorded agreement figures and a confirmed licence.

---

## 5. What this corpus does and does not represent

These limits belong next to any number computed from this dataset, not only here.

- **36 conversations.** Small. Descriptive and qualitative. No empirical claim about
  scam populations can rest on it, and none is made.
- **Honeypot-inbox bias.** The corpus is what arrived at one set of honeypot
  mailboxes, shaped by whatever those addresses attract. It is not a sample of fraud
  in general, or even of fraud email in general.
- **Bait-influenced.** These are two-sided conversations in which this project sent
  the replies. Scammer behaviour after turn one is a response to that bait, so the
  distribution of later-phase behaviours reflects the bait strategy as much as the
  scammer.
- **English-dominant.** Whatever language skew the inbox has, the corpus has.
- **A point in time.** Collected over a bounded window. Scam tradecraft moves.
- **Scores are dataset-scoped.** An extractor scored against these labels has a
  score on this dataset. It is not a production precision or recall figure, and it
  should never be quoted as one.

---

## 6. Licence

The repository is MIT. MIT is a software licence and fits a dataset poorly — it
speaks about source code, and it says nothing useful about attribution when someone
republishes derived data.

**Proposed**: CC-BY-4.0 for the files in this directory and for
`scambuster-dataset-sample.json`. It is the convention for research corpora, it
requires attribution, and it is what a reviewer will expect to find.

This is the project owner's decision to make, so it is recorded as `proposed` in the
label file and the `--complete` validation refuses to pass while it stays that way.
Nothing here is published as a licensed dataset until it is confirmed.

---

## 7. Sanitization

The sample was sanitized before its original release. One thing is worth re-checking
before labels ship: adding character offsets makes it possible to point at an exact
span, so any sanitization gap becomes easier to exploit than it was when only the
whole body was published.

Re-run the release checks over the sample before publishing labels:

```bash
bash scripts/check-honeypot-leak.sh
python3 scripts/check-credentials.py
```

The no-verbatim-evidence rule governs production data, and this sample is not
production data — it was sanitized for release. The re-check is not a suspicion that
the sanitization failed; it is that the cost of being wrong went up, so the check is
worth running again rather than assumed.

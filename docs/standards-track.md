# Standards track — status of the TTP taxonomy

This is the decision record the taxonomy export command points to. It says what the taxonomy is,
what it was mapped against, how the mapping was done, and why it is not published as a standard.

## What the taxonomy is

27 conversational scam techniques (`SB-T001`…`SB-T027`) over a six-phase kill chain:
`hook`, `trust-building`, `payment-request`, `escalation`, `channel-switch`, `exit`.

It is an **internal vocabulary**. It is versioned and stable (codes are never reused, STIX ids are
deterministic UUIDv5), but it is not a public standard and is not offered as one.

## Mapping to MITRE F3

### Source

| | |
|---|---|
| Repository | `github.com/center-for-threat-informed-defense/fight-fraud-framework` |
| File | `public/f3-stix-v1.1.json` (STIX 2.1 bundle) |
| Pinned commit | `f0c085839bf42ed2c898057aebbf5cad95166e0f` |
| SHA-256 | `e380161dfed59f4b7ddb49c873a15b4d07fa74a553edcbb9fb0b27d47e435980` (196213 bytes) |
| Parsed content | 123 attack-patterns, 8 tactics, 74 parent + 49 sub-techniques, 80 `F####` + 43 `T####` |
| Licence | Apache-2.0, per the repository's `LICENSE` file (read 2026-08-14) |

Pin a commit, not a file name. The repository publishes no releases and no tags, and on `main`
`f3-v1.json` and `f3-v1.1.json` are the same bytes, so a name like "v1" does not identify a version.

### How it was done

1. A BM25 screen (k1=1.5, b=0.75) scored each technique definition against the name and description
   of all 123 F3 entries. No hand-picking: the top 10 candidates went forward for every code.
2. All 123 descriptions were read.
3. Before writing any "no match found", a second pass ran declared substring probes over the 123
   descriptions, with the probe lists published alongside the results.
4. Every id quoted was checked by script against the closed set of 123 ids. That check is what
   caught `T1566`, `T1566.001`, `T1566.002` and `T1656` as ids that are **not in the bundle**.
5. Where the screen failed and a match was found by reading rather than by score, the row says so.
   Three such failures are recorded.

The full mapping, the candidate lists, the probe lists and the scripts are working documents kept
outside this repository. What is committed here is the outcome and the method.

### Outcome

Our reading of the pinned bundle assigns **2 as covered** by an F3 description, **13 as partially
aligned**, and **12 as behaviours our grid distinguishes and for which we found no F3 description
that distinguishes them**.

The 15 techniques with a match carry `mitre-f3` references, with the canonical url
(`https://ctid.mitre.org/fraud/techniques/<id>`, read from the bundle: all 123 entries carry it,
with no trailing slash and the dot kept on sub-techniques). The other 12 keep an empty
`external_refs`. **That empty array records that we found no match, not that F3 has a hole.**

These are 27 judgement calls made by one reader against one pinned file. There was no second coder
and no inter-annotator agreement.

### A note on the older ATT&CK references

Five of the six pre-existing `mitre-attack` references point at ids that are **not among the 123 ids
in the pinned bundle**: `T1566`, `T1566.001`, `T1566.002`, and `T1656` (which appears on two
entries). Only `T1598` is in both catalogues. Whether that reflects a scoping decision by the CTID
or something else is not something we checked — the bundle carries no scope statement. The practical
lesson is narrower: an id that looks fraud-adjacent in ATT&CK is not necessarily in F3, so check
membership against the bundle instead of assuming.

## Why it is not published as a standalone standard

The evidence base does not support it. Measured on 2026-08-14, **98.2% of the TTP observations in
the database are synthetic** — written by `scambuster:ttp:demo-seed`, which describes its own output
as "deterministic, phrase-matched approximations — NOT real model extractions". Real extraction has
produced **6 observations**, over 6 techniques, **one conversation each**, and only 2 of the 6 carry
a quote found verbatim in the source text.

The qualification threshold — five distinct conversations — was written down in the spec **before
the measurement**, not chosen afterwards. No technique reaches it.

Proposing techniques to a standards body on that basis would not hold up, so we do not.

## What would have to change first

1. Backfill real TTP extraction over the existing corpus and let the pipeline run.
2. Re-measure, always splitting on `extraction_model` and `prompt_version`. Anything tagged
   `demo-seed` is excluded from any count that supports a claim.
3. Measure accuracy, not just volume: `scambuster:ttp:audit-sample --ttp <code> --seed <n>`, scored
   by hand, against a precision floor written down in advance.
4. Redefine "distinct actors". Cluster membership is anchored on financial IOCs only, so a
   conversation with no IBAN or wallet joins no cluster. Early-phase techniques are penalised by
   construction, which is the opposite of what the measure is meant to show.

## A limit that volume does not fix

The 27 techniques were defined first, then a model was asked to find them using those definitions as
its only vocabulary, with an instruction never to emit a code outside the list. The corpus therefore
cannot contradict the taxonomy, nor surface a technique it does not already contain.

So the honest wording, here and in any future write-up, is **"behaviours our grid distinguishes that
F3 does not distinguish"** — never "behaviours F3 does not cover".

## Adding a source name

`taxonomy.schema.json` allows `mitre-attack` and `mitre-f3`. Adding a third is a public contract
change: it needs a per-entry mapping exercise like the one above, a taxonomy version bump, and a
compatibility note.

Charm Security's HVE was considered and dropped. As of 2026-08-14 we found no published technique
catalogue for it — only a CVE-style registration format (`arxiv.org/abs/2606.10083`), for which we
found no released records. There is nothing to map against.

We found no MISP galaxy for F3, so `TtpMispTagProvider` maps ATT&CK references only and F3
references produce no MISP tag. If a galaxy appears, that is the place to change.

## Artifact versioning

`taxonomy-v1.0.json` and `taxonomy-v1.1.json` are both committed. A published file is never
rewritten: v1.0 is exactly what it was, so anything that pinned it keeps working. v1.1 is v1.0 plus
the F3 references — no code, phase, or definition changed.

Note for anyone editing the taxonomy: the seed migration inserts with `ON CONFLICT (code) DO
NOTHING`, so on a database that already ran it, changing the seed updates no row. A mapping change
needs a data migration as well. `TtpTaxonomyConsistencyTest::testExternalRefsHaveABackfillMigration`
enforces that, and the backfill writes **all 27 codes**, empty ones included — writing only the
non-empty ones would leave a dropped reference in place forever.

_Last updated: 2026-08-14._

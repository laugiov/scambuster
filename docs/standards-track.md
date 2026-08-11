# Standards-Track Log

**Spec**: 006-standards-track-submissions
**Status**: BLOCKED — nothing has been filed, and nothing may be

This is the log of every standards-track submission: what was filed, where, when,
and what came back. It is also the place the container gate is recorded.

Today it has no entries, and that is the correct state.

---

## 1. The gate

> **No public artifact that positions a ScamBuster taxonomy as a normative standard —
> a published spec file, a registered public taxonomy, a framework name — ships
> before the container decision is made and recorded.**
> — Constitution IV

**The container decision has not been made.** Until it is, nothing in this document
is filed anywhere.

| Option | What it means | What it costs |
|--------|---------------|---------------|
| **A. Contribute to MITRE F3** | Propose the behaviours F3 does not cover as new F3 techniques. The taxonomy becomes part of an existing framework. | No new repository to maintain. Acceptance is not this project's decision, and the material may be reshaped. |
| **B. Research white paper** | Publish the taxonomy and the measured results as research. No normative claim. | Nothing to retract, nothing to govern. No shared vocabulary comes out of it. |
| **C. Standalone framework** | Publish and maintain the taxonomy as its own named framework. | A governance and maintenance commitment for as long as anyone uses it. Explicitly out of scope for this spec. |

The options are not exclusive: A and B compose well, and B remains available if A
declines. What the gate blocks is acting as though one had been chosen.

**To record the decision**: replace this section with the decision, the date, the
reasoning, and who made it. Everything below unblocks from there.

### Decisions that ride on the same gate

| Decision | Where it bites | Status |
|----------|----------------|--------|
| Container (A / B / C) | This entire document | **Open** |
| Kill chain name — keep `scambuster-scam-phases` or move to a neutral name | Every exported STIX attack-pattern. Renaming is breaking for consumers and is a MAJOR taxonomy change. The current name carries the product name, which reads as proprietary in a standards context. | **Open** — must be recorded before any submission uses the exported attack-patterns (Spec 003 FR-008) |
| Publishing `taxonomy-v1.0.json` as a standalone public standard | Repository root, release assets, external registries. Internal generation and use in exports is not gated and happens today. | **Open** (Spec 003 FR-007) |

---

## 2. Channel: MITRE F3 technique contribution

**Status**: blocked twice over.

F3 publicly invites exactly this: "techniques used by fraud actors in real-world,
cyber-based incidents that are not yet represented in F3", with a documented
contribution path through issues and pull requests with a DCO sign-off. It is the
channel that matches this project's actual delta — turn-by-turn conversational
behaviour — and it costs no new repository.

Two things block it, and the second is the harder one:

1. **The container gate** (§1).
2. **The mapping is not done.** Spec 006 FR-001 allows proposing only techniques
   whose Spec 002 decision is `none`. Every decision in
   `docs/standards/f3-mapping.md` is currently `pending`, because the F3 v1
   technique list is not in the repository and `ctid.mitre.org` is unreachable from
   the build environment. There is therefore nothing this project can honestly claim
   F3 does not cover.

### What a proposal will need, when it is unblocked

Per proposed technique:

- a name and a description in F3 house style;
- a proposed tactic placement;
- its relation to the nearest existing F3 technique, cited from the mapping table;
- a sourcing note.

The sourcing note is the part most likely to be written badly, so it is specified
here rather than left to the moment: production honeypot conversations, descriptive
and qualitative, the Spec 001 extraction-quality figure cited **with its limits**,
no large-scale empirical claim, and no verbatim scammer text (Constitution I and
III). "We observe this frequently" is not a sourcing note. "In a seeded sample of
100 tagged observations, two scorers agreed at kappa X and adjudicated precision was
Y; the corpus is 36 conversations from one honeypot inbox and supports no population
claim" is.

**Prerequisites**: Spec 001 complete (a quality figure exists), Spec 002 complete
(the mapping has `none` rows), the container decision recorded, and a second
reviewer signed off.

---

## 3. Channel: MISP taxonomy registration

**Status**: blocked on the container gate. The artifact is ready.

The platform already emits `scambuster:ttp="SB-Txxx"` machine tags. They are
well-formed, and no MISP instance can resolve them to a meaning, because the
`scambuster` namespace is not registered in the MISP taxonomies repository. A
consumer sees the tag as free text.

Registration fixes that for every MISP instance worldwide, which is also why it is
gated: a merged taxonomy PR propagates to instances that sync the repository, and it
is hard to retract.

`backend-symfony/config/standards/machinetag.json` is generated from the canonical
taxonomy seed by `scambuster:ttp:misp-machinetag` and checked by
`MispMachineTagGeneratorTest`: values and descriptions match the taxonomy verbatim,
the namespace and predicate are the two halves of the tag the platform actually
sends, and each entry carries only code, label and definition — no examples, no
evidence.

Generating and testing it is not gated (Constitution IV allows internal generation).
Filing it is.

---

## 4. Review and consent rules

These apply to every submission, under every container.

- **Second-reviewer sign-off before filing.** Standards-track material — taxonomy
  content, mapping decisions, submission text — is signed off by a second person
  before it goes anywhere public (Constitution, Governance). The project runs under
  a single maintainer, so this is a real prerequisite, not a formality to tick.
- **No unverified numbers.** Every figure in a submission traces to a document in
  this repository (Constitution I).
- **No verbatim evidence.** Constitution III has no exception. Submissions describe
  behaviour in the project's own words.
- **No reviewer named without written consent.** Acknowledgements list a name only
  when that person has agreed in writing; otherwise the contribution is recorded by
  role.
- **Plain B2 business English.** Use, help, work, make, get. No hype, no
  unverifiable superlatives (Constitution VIII).

---

## 5. Timing

The DefCamp CFP deadline is 2026-08-30. Nothing in this document can complete before
then, and nothing in it needs to: a CFP submission needs Specs 001 and 002 only.

---

## 6. Submission log

Empty. One row per submission, added when it is filed.

| Date | Channel | What was filed | URL | Outcome |
|------|---------|----------------|-----|---------|
| — | — | — | — | — |

An entry is added when the submission is **filed**, not when it is drafted, and its
outcome is updated to closure — including when the outcome is a decline. A declined
proposal is a recorded result, and the material stays publishable as research
observations under option B.

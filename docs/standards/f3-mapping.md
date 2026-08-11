# ScamBuster TTP Taxonomy — Mapping to MITRE F3

**Status**: blocked on an external input (see §2)
**Taxonomy version**: 1.0 (27 entries)
**Source of truth**: `backend-symfony/config/standards/f3-mapping.json`
**Spec**: 002-external-framework-mapping

MITRE's Fight Fraud Framework (F3) is a knowledge base of tactics and techniques used
by financial fraud actors. This document records, for every ScamBuster taxonomy entry,
how it relates to F3 — and, in the other direction, which F3 techniques describe
conversation-visible behaviour the ScamBuster taxonomy does not carry.

Its purpose is a rule, not a formality. ScamBuster maps to existing frameworks; it does
not compete with them. **No public text may claim a gap in F3 that is not backed by a
`none` row in the table below** (Constitution II, Spec 002 FR-008). Until a row says
`none`, the honest phrasing is "to be verified".

---

## 1. How to read a decision

Each entry carries exactly one relation:

| Relation | Meaning | Written to `external_refs`? |
|----------|---------|------------------------------|
| `equivalent` | Same behaviour, same granularity. | Yes |
| `narrower-than` | The ScamBuster entry is a subset of the F3 technique. | Yes |
| `broader-than` | The ScamBuster entry covers more than the F3 technique. | No |
| `related` | Adjacent, neither contains the other. | No |
| `none` | F3 v1 does not describe this behaviour. | No |
| `pending` | Not yet checked. Not a decision. | No |

`broader-than` is excluded from `external_refs` on purpose. This closes the open
question Spec 002 left in its edge-case list, in the direction the spec proposed: a
consumer who sees an attack-pattern referencing an F3 id reasonably reads it as "this
is that technique". Pointing a broader entry at a narrower id tells them something
false about scope, and a shared feed is the wrong place to be approximately right.
`related` is excluded for the same reason at lower strength.

A `none` decision still has to say what F3 covers nearby and why it does not describe
the behaviour. "F3 has nothing on this" is not a rationale; "F3 covers producing fake
documents under resource development, but not the conversational act of presenting one
at a chosen turn to repair trust" is.

### The same id under two source names

F3 references existing MITRE ATT&CK techniques where they apply to fraud. An entry can
therefore legitimately carry the same `external_id` twice — once under `mitre-attack`,
once under `mitre-f3`. Both are kept. They are different knowledge bases, and a
consumer resolving `mitre-f3:T1598` is doing something different from one resolving
`mitre-attack:T1598`.

### URLs

No URL is emitted for `mitre-f3` references. The canonical technique URL format on
ctid.mitre.org has not been verified against the live site, and a guessed URL in a
shared feed is worse than no URL: consumers follow it. `mitre-attack` references keep
their verified URL. This is enforced in code by an allowlist of URL bases in
`TtpAttackPatternBuilder`, not by convention.

---

## 2. Why this is blocked

The forward and reverse mappings both need the F3 v1 technique list — every technique
id, name and description — to be read entry by entry.

That input is not in this repository. `f3-v1.json` is not committed, and
`ctid.mitre.org` (where the F3 matrix and its data live) is not reachable from the
build environment: the egress policy denies it.

Recording a relation without reading the F3 description it cites would be an
unverified external claim, which Constitution I and II both forbid. So every entry
below is `pending`, and the table records what a reviewer must look for in F3 for each
one rather than a guess at what they will find.

**To unblock**: place the F3 v1 technique list in the repository (or work from
`https://ctid.mitre.org/fraud/#/matrix` on a machine that can reach it), then fill
`backend-symfony/config/standards/f3-mapping.json` and run:

```bash
php bin/console scambuster:ttp:f3-mapping
```

That validates the file and rewrites the generated table below. Set
`framework_version` and `checked_on` in the same edit — the validator rejects recorded
decisions that do not say which F3 version they were checked against, so a future F3
release invalidates this document visibly rather than silently (FR-006).

---

## 3. Mapping table

Generated from `backend-symfony/config/standards/f3-mapping.json`. Do not edit by hand: edit the JSON
and re-run the command. CI runs `scambuster:ttp:f3-mapping --check` and fails when this
block is stale.

<!-- BEGIN GENERATED MAPPING TABLE -->

**F3 version checked**: _not yet checked_
**Date of the check**: _not yet checked_

> **Blocked.** The F3 v1 technique list (f3-v1.json) is not in this repository and ctid.mitre.org is unreachable from the build environment. Every decision below is therefore 'pending': recording a relation without reading the F3 technique description it cites would be an unverified claim (Constitution I and II).

| Code | Label | Phase | Relation | F3 id(s) | Rationale |
|------|-------|-------|----------|----------|-----------|
| SB-T001 | Unsolicited opportunity lure | hook | `pending` | — | Behaviour to look for in F3: the opening pretext that names a windfall or business opportunity as the reason for contact. Nearest known anchor is ATT&CK T1566 (phishing), already carried. Check F3's initial-contact and social-engineering tactics for a lure-content technique. |
| SB-T002 | Institutional authority impersonation | hook | `pending` | — | Behaviour to look for in F3: impersonation of a public body (government, court, police, central bank, international organization) to command compliance. Nearest known anchor is ATT&CK T1656 (impersonation), already carried. F3 is likely to separate authority impersonation from brand impersonation; check both. |
| SB-T003 | Commercial brand impersonation | hook | `pending` | — | Behaviour to look for in F3: impersonation of a private company or consumer service (courier, marketplace, vendor). Nearest known anchor is ATT&CK T1656. If F3 carries a single impersonation technique covering both public and private entities, this entry and SB-T002 are both narrower-than it. |
| SB-T004 | Malicious resource delivery | hook | `pending` | — | Behaviour to look for in F3: delivery of a link or attachment the target must open to proceed. Nearest known anchors are ATT&CK T1566.001 and T1566.002, already carried. This is the entry most likely to be equivalent to an existing F3 technique, since it is the most conventional cyber behaviour in the taxonomy. |
| SB-T005 | Legitimacy document display | trust-building | `pending` | — | Behaviour to look for in F3: volunteering official-looking documents mid-conversation as proof of authenticity. F3 is expected to cover the manufacture of fake documents under a resource-development tactic; the ScamBuster entry is about the conversational act of showing one at a chosen turn, which is a different observation point. Check whether F3 separates producing from presenting. |
| SB-T006 | Rapport personalization | trust-building | `pending` | — | Behaviour to look for in F3: building an emotional or personal bond unrelated to the transaction. Check F3's social-engineering tactic for a rapport or grooming technique; romance-fraud coverage would be the likely home. |
| SB-T007 | Religious or moral appeal | trust-building | `pending` | — | Behaviour to look for in F3: invoking faith or moral duty to frame the exchange as righteous. Likely absent as a distinct technique; if so it may be narrower-than a general pretext or persuasion technique rather than none. |
| SB-T008 | Fabricated social proof | trust-building | `pending` | — | Behaviour to look for in F3: citing invented third parties who already benefited, as social proof. Check for a testimonial or social-proof technique; adjacent to fake-review and shill behaviours F3 may cover under a different tactic. |
| SB-T009 | Secrecy demand | trust-building | `pending` | — | Behaviour to look for in F3: instructing the target to keep the transaction confidential from family, banks or authorities. This is a well-documented fraud behaviour (it defeats third-party intervention), so F3 coverage is plausible. Check the tactic covering victim isolation or detection evasion. |
| SB-T010 | Intermediary introduction | trust-building | `pending` | — | Behaviour to look for in F3: introducing an additional persona (lawyer, banker, diplomat, agent) the target must now deal with. Check for a multi-persona or accomplice technique. |
| SB-T011 | Plausibility repair | trust-building | `pending` | — | Behaviour to look for in F3: explaining away inconsistencies, delays or contradictions the target has noticed. This is a reactive, turn-level behaviour and is a strong 'none' candidate: F3 techniques describe actor goals, and repairing a damaged pretext mid-conversation may have no equivalent. |
| SB-T012 | Advance fee demand | payment-request | `pending` | — | Behaviour to look for in F3: requiring an upfront payment to unlock a larger promised value. Advance-fee fraud is a named fraud category, so F3 coverage is very likely. Check the monetization tactic. |
| SB-T013 | Payment instrument designation | payment-request | `pending` | — | Behaviour to look for in F3: providing concrete payment coordinates (bank account, crypto wallet, money-transfer recipient). F3 is expected to cover payment rails in detail under monetization or cash-out; this entry may be broader-than several F3 techniques at once, one per rail. |
| SB-T014 | Victim data harvesting | payment-request | `pending` | — | Behaviour to look for in F3: requesting the target's identity documents, bank details, credentials or verification codes. Nearest known anchor is ATT&CK T1598 (phishing for information), already carried. Note the edge case: F3 reuses ATT&CK technique ids, so the same external_id may legitimately appear under both source names. |
| SB-T015 | Overpayment refund scheme | payment-request | `pending` | — | Behaviour to look for in F3: claiming an overpayment or showing fake payment proof, then asking for part of the money back. A named fraud pattern; F3 coverage likely under monetization. |
| SB-T016 | Payment method shift | payment-request | `pending` | — | Behaviour to look for in F3: changing the demanded payment rail mid-conversation (bank to crypto to gift cards). F3 may describe each rail as its own technique without describing the switch between them; if so this entry is a 'none' candidate on the switching behaviour specifically. |
| SB-T017 | Urgency deadline pressure | escalation | `pending` | — | Behaviour to look for in F3: imposing a hard deadline or countdown to force immediate action. Urgency is a standard social-engineering lever; check whether F3 models it as its own technique or as a property of others. |
| SB-T018 | Fee laddering | escalation | `pending` | — | Behaviour to look for in F3: adding new, previously unmentioned fees after an earlier payment. The escalation pattern (not the first fee, which is SB-T012) is the distinguishing part. Strong 'none' candidate if F3 models advance fees without modelling their repetition. |
| SB-T019 | Bureaucratic obstacle fabrication | escalation | `pending` | — | Behaviour to look for in F3: inventing official procedures, codes or certificates that block progress until resolved. This is the narrative wrapper around a fee rather than the fee itself. Check whether F3 separates the pretext from the demand. |
| SB-T020 | Threat of loss or legal action | escalation | `pending` | — | Behaviour to look for in F3: threatening forfeiture, arrest, account closure or legal consequences for non-compliance. Coercion is a standard lever; F3 coverage plausible. |
| SB-T021 | Emotional pressure appeal | escalation | `pending` | — | Behaviour to look for in F3: leveraging pity, guilt or personal hardship to compel action. Check the social-engineering tactic for an emotional-manipulation technique. |
| SB-T022 | Verification deflection | escalation | `pending` | — | Behaviour to look for in F3: refusing, evading or disqualifying the target's verification requests. This is a defensive conversational move and a strong 'none' candidate: it is only observable in a two-way exchange, which is the part of the problem space this taxonomy was built for. |
| SB-T023 | Off-channel solicitation | channel-switch | `pending` | — | Behaviour to look for in F3: moving the exchange to another channel (WhatsApp, Telegram, phone, personal email). F3 coverage plausible: channel migration is a documented fraud behaviour and defeats email-side controls. |
| SB-T024 | Contact exclusivity demand | channel-switch | `pending` | — | Behaviour to look for in F3: restricting communication to one designated contact and forbidding others. Adjacent to SB-T009 (secrecy) and to victim isolation; check whether F3 folds the two together. |
| SB-T025 | Final ultimatum | exit | `pending` | — | Behaviour to look for in F3: a last demand with the threatened termination of the deal or relationship. Terminal-turn behaviour; likely absent as a distinct F3 technique. |
| SB-T026 | Re-engagement attempt | exit | `pending` | — | Behaviour to look for in F3: returning after silence or refusal with a new angle, discount or persona. Re-victimisation and recovery-scam behaviour may cover part of this; check whether F3 models re-engagement within the same scheme as opposed to a follow-on scheme. |
| SB-T027 | Fabricated gains display | trust-building | `pending` | — | Behaviour to look for in F3: showing fabricated profits, balances or returns to induce further payment. Central to investment fraud, so F3 coverage is very likely. Check the tactic covering fake platforms and dashboards. |

### Decision counts

| Relation | Entries |
|----------|---------|
| `equivalent` | 0 |
| `narrower-than` | 0 |
| `broader-than` | 0 |
| `related` | 0 |
| `none` | 0 |
| `pending` | 27 |

Entries written to `external_refs` (relations `equivalent` and `narrower-than`): **0**.

### Reverse direction

Not yet recorded (status: `pending`). F3 techniques that describe conversation-visible behaviour absent from the ScamBuster taxonomy (Spec 002 FR-002). Filled in the same pass as the forward direction; blocked on the same input.

<!-- END GENERATED MAPPING TABLE -->

---

## 4. What happens to confirmed mappings

A relation of `equivalent` or `narrower-than` is not just a document row. It has to
reach the data and the exports (FR-003):

1. Add `{"source_name": "mitre-f3", "external_id": "<F3 id>"}` to the entry's
   `external_refs` in `TtpTaxonomySeed::ENTRIES` **and** in the `lkp_ttp` migration
   seeds. The consistency test locks the two copies together and fails if only one is
   edited.
2. The STIX attack-pattern picks the reference up automatically:
   `TtpAttackPatternBuilder` emits any source in its allowlist (`mitre-attack`,
   `mitre-f3`).
3. Nothing else changes. Adding a reference is a PATCH-level taxonomy change: it
   changes no meaning, and it must not change the STIX attack-pattern id (see
   `taxonomy-versioning.md`).

### MISP tags stay unchanged

`TtpMispTagProvider` emits no tag for an F3 reference, and that is deliberate
(FR-005). MISP galaxy tags have to resolve in a consumer's instance; no public F3 MISP
galaxy exists, so any tag string this project invented would resolve nowhere. The
provider keeps emitting the first-party `scambuster:ttp="SB-Txxx"` tag and the ATT&CK
galaxy tag for verified ATT&CK ids only. A test pins this behaviour so a later change
to the provider cannot start fabricating F3 tags by accident.

---

## 5. HVE mapping

**Blocked.** The Charm Security HVE specification has not been obtained. When it is,
the HVE mapping reuses this document's structure and the same relation vocabulary, in
the `hve` section of the same JSON file (FR-007). No HVE claim of any kind ships
before then.

---

## 6. Out of scope

- **Proposing new F3 techniques.** The `none` rows are the raw material for that, but
  the proposals themselves belong to Spec 006 and are gated on the container decision.
- **DISARM.** Adjacent domain (influence operations), not scam-financial. Revisited
  only if a reviewer asks.
- **Taxonomy renaming driven by the mapping.** If the mapping shows an entry is badly
  named or badly scoped, that is a taxonomy change and goes through the versioning
  process in `taxonomy-versioning.md`, not through this document.

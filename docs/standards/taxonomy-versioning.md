# TTP Taxonomy Versioning Policy

**Current taxonomy version**: 1.0
**Artifact**: `backend-symfony/config/standards/taxonomy-v1.0.json`
**Schema**: `backend-symfony/config/standards/taxonomy.schema.json`

This is the stability contract for the ScamBuster TTP taxonomy. It says what a
version number means, what a consumer may rely on, and what this project will never
do to a published version.

A consumer who pins `taxonomy_version: "1.0"` is making a bet. This document is what
they are betting on.

---

## 1. The artifact

The taxonomy is generated, not written:

```bash
php bin/console scambuster:ttp:taxonomy-export
```

It reads `TtpTaxonomySeed::ENTRIES` — the single source of truth production code
uses — and writes `taxonomy-v<version>.json`. Nobody hand-edits the JSON. To change
the taxonomy you change the seed and regenerate.

Three properties hold, and each is enforced rather than promised:

| Property | Enforced by |
|----------|-------------|
| Deterministic: two runs on the same seed are byte-identical | `TaxonomyArtifactGeneratorTest`, and the absence of any timestamp in the output |
| Matches the seed: the file cannot drift from the database | `scambuster:ttp:taxonomy-export --check` in CI |
| Valid against its schema | `scripts/standards/validate-taxonomy-artifact.py` in CI, using a third-party JSON Schema implementation |

Determinism is what makes the file citable. A reviewer can regenerate it and compare
bytes; they do not have to trust that this project generated it faithfully.

### One file per version

A new version gets a new file. `taxonomy-v1.0.json` is not overwritten when 1.1
ships. Consumers pin files, and a published version's artifact is never withdrawn or
edited in place — the versioning check fails a diff that removes one.

---

## 2. What the version number means

The taxonomy follows semantic versioning, read as a contract about *meaning*:

### MAJOR — a consumer's existing interpretation becomes wrong

- The meaning of an existing code changes. If a re-reading of the definition would
  make an annotator label differently, that is a meaning change, however small the
  wording edit looks.
- A code is deprecated (`active` goes to false).
- A phase is removed or renamed, or the kill chain name changes.

A MAJOR bump mints fresh STIX ids (see §4). Consumers must re-read the artifact and
re-map.

### MINOR — a consumer's existing interpretation stays correct, but is incomplete

- New codes.
- New phases.
- New examples on an existing entry.

Everything a consumer knew about version N still holds in N+1 minor. There is simply
more.

### PATCH — nothing observable changes

- Typo and grammar fixes that leave the meaning intact.
- Clarifying rewordings that would not change any annotator's verdict.
- Additions to `external_refs` — mapping an entry to MITRE F3 records a relationship
  to another framework; it does not change what the entry means.

**A PATCH must not change any STIX id.** If a change would, it was not a PATCH.

### The boundary case

"Wording fix" and "meaning change" are the same edit seen from two sides, and the
temptation is always to call it a PATCH. The test to apply: *would a scorer working
from the codebook reach a different verdict on some message under the new wording?*
If you have to think about it for more than a moment, it is a MAJOR.

---

## 3. Deprecation, never deletion

A code that stops being useful is deprecated: `active` becomes false and the row
stays, with its definition and its STIX id untouched.

It is never deleted. Observations in production reference it, exports already sent to
consumers reference it, and a consumer that resolved `SB-T0xx` last month must still
resolve it today. A dangling reference is worse than a retired one.

`scripts/standards/check-taxonomy-versioning.py` fails any diff that deletes a code,
whatever the version bump says.

Codes are also never reused. `SB-T027` means one thing forever; a new behaviour gets
a new number.

---

## 4. STIX id stability

Every entry carries a deterministic STIX attack-pattern id: a UUIDv5 over the
taxonomy code, seeded with the taxonomy major version.

```
seed = "scambuster:ttp:v<MAJOR>:<CODE>"
id   = UUIDv5(URL namespace, seed)
```

What this gives a consumer:

- **Stable within a major version.** The same code yields the same id in 1.0, 1.1 and
  1.9. Re-importing the same bundle deduplicates rather than creating a second copy.
- **Fresh on a new major.** Version 2.0 mints new ids through the changed seed
  prefix. That is deliberate: a major version means the meaning changed, and a
  consumer's stored object for the old id describes the old meaning. Reusing the id
  would silently redefine an object they already hold.

Adding an `external_refs` entry is PATCH-level precisely because it does not touch
the id: the reference is metadata about the entry, not part of its identity.

---

## 5. Changing the taxonomy

1. Edit `TtpTaxonomySeed::ENTRIES` **and** the `lkp_ttp` migration seeds. The
   consistency test fails if only one is edited. A migration keeps its own copy
   because reference rows reach production through migrations, and a migration must
   not depend on application code that can change under it.
2. Decide MAJOR / MINOR / PATCH by §2 and bump `Ttp::TAXONOMY_VERSION`.
3. Add a `CHANGELOG.md` entry saying what changed and why.
4. Regenerate: `php bin/console scambuster:ttp:taxonomy-export`.
5. For a MAJOR, also write the migration that stamps existing observations — rows
   carry the taxonomy version they were extracted under, and that must stay true.

CI fails the change if step 2 or step 3 is missing, and fails it separately if step 4
is missing.

---

## 6. What is not covered here

- **Publication.** Positioning this artifact as a public normative standard — at the
  repository root, as a release asset, or in an external registry — is gated on the
  container decision (see `docs/standards-track.md`). Generating it, testing it and using it in
  exports is not gated, and is what happens today.
- **The kill chain name.** `scambuster-scam-phases` appears in every exported
  attack-pattern. It carries the product name, which reads as proprietary in a
  standards context, and renaming it is breaking for every consumer. The decision is
  tied to the container decision and must be recorded before any external submission
  uses the exported attack-patterns. Until then the name stays.
- **Translations.** Definitions are English-only. A translated definition would need
  its own drift-control story and there is none yet.

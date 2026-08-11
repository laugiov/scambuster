# Interoperability Conformance Statement

**Scope**: STIX 2.1 export, TAXII 2.1 server, MISP event and tag export
**Spec**: 005-interoperability-conformance
**Last verified**: see the CI run of the `Standards-Track Guards` job

This document lists what ScamBuster claims about interoperability and, next to each
claim, the automated test that proves it. A claim with no test does not sit quietly
among the proven ones — it goes in §4, "stated, not yet proven".

The rule behind the document is Constitution V: interoperability is proven by
automated validation, not by stated intent. A reference platform earns trust because
its output imports everywhere, twice, without duplicates — not because its README
says it conforms.

---

## 1. STIX 2.1

### 1.1 Proven by an external validator

The bundles are validated by the OASIS-community `stix2-validator` against the OASIS
STIX 2.1 JSON schemas. Neither the validator nor the schemas are under this
project's control, which is the point: the repository already validates its own
custom extension schemas at build time, and self-validation convinces nobody in a
standards context.

| Claim | Proving test | CI job |
|-------|--------------|--------|
| The IOC bundle is valid STIX 2.1 | `scripts/standards/validate-stix-bundles.sh` → `ioc-bundle.json` | Standards-Track Guards |
| The cluster bundle (threat actor + TTP attack-patterns + sightings + property extension) is valid STIX 2.1 | same script → `cluster-bundle.json` | Standards-Track Guards |
| The conversation TTP bundle is valid STIX 2.1 | same script → `conversation-ttp-bundle.json` | Standards-Track Guards |
| The conformance gate actually fails on a broken bundle | same script, self-test step: a required property is removed from a copy and the validator must reject it | Standards-Track Guards |

Errors fail the job. Warnings are printed and do not — several are consequences of
deliberate design decisions, listed in §1.3.

The bundles are built by `ConformanceFixtureBuilder` from synthetic fixture data, so
the job needs no database and no production row. The fixtures are chosen for what
they stress rather than for coverage: the custom property extension on sightings
(this project's own contribution to the format, and so the least battle-tested thing
it emits), external references under two source names with and without URLs, the
single-point sighting where STIX forbids `stop_time <= start_time`, IOC types that
map to SCOs and IOC types that do not, and mixed TLP markings in one bundle.

### 1.2 Deterministic ids and re-import behaviour

| Claim | Proving test | CI job |
|-------|--------------|--------|
| Every content object id is identical across two export runs of the same data | `StixExportDeterminismTest::testContentObjectIdsAreIdenticalAcrossTwoExportRuns` | Backend Tests |
| No object type outside the documented exemptions generates a fresh id per export | `StixExportDeterminismTest::testOnlyTheDocumentedTypesCarryAPerExportId` | Backend Tests |
| Object ids are unique within a bundle | `StixExportDeterminismTest::testObjectIdsAreUniqueWithinABundle` | Backend Tests |
| The same taxonomy code is one attack-pattern object across bundle types | `StixExportDeterminismTest::testTheSameTaxonomyCodeYieldsOneAttackPatternAcrossBundleTypes` | Backend Tests |
| Each of the 27 taxonomy entries maps to a distinct, stable UUIDv5 attack-pattern id | `TtpTaxonomyConsistencyTest::testEveryEntryResolvesToAUniqueStixAttackPatternId`, `TtpStixIdGeneratorTest` | Backend Tests |

**Two object types carry a fresh id per export, by design:**

- `bundle` — the envelope. Bundles are transient containers, not content; consumers
  unpack them and deduplicate what is inside.
- `report` — one per export run. A report SDO describes what an export contained at
  a moment, so two exports genuinely are two reports. Its `object_refs` point at the
  deduplicating content objects.

Everything else — indicators, SCOs, observed-data, sightings, relationships,
attack-patterns, threat actors, extension definitions — is derived from the data. A
consumer importing the same bundle twice gets one copy of each.

### 1.3 Warnings the validator emits, and why they stand

These are reported by `stix2-validator` as best-practice warnings. Each is a
consequence of a decision this project made on purpose.

| Warning | Why it stands |
|---------|---------------|
| `{103}` id is not a valid UUIDv4 | The ids are UUIDv5, derived from the data. That is precisely what makes re-imports deduplicate. A UUIDv4 would satisfy the warning and break the property this project cares about most. |
| `{103}` SCO id is not a valid UUIDv5 (contributing-properties form) | SCO ids are deterministic over the observable's type and normalised value rather than over the spec's contributing-properties recipe. The dedup property holds; the derivation differs. Worth revisiting, and tracked in §4. |
| `{302}` external reference has a URL but no hash | ATT&CK technique URLs point at a live public site whose content changes with each ATT&CK release. A hash would be wrong within months. |
| `{303}` indicator SHOULD have both name and description | Indicator names would restate the pattern, and descriptions would risk carrying context derived from message content. Constitution III makes the second unacceptable and the first is noise. |
| `{219}` `threat_actor_types` value not in the open vocabulary | `criminal-financial` is the honest description of the actors observed. The property's vocabulary is open (`-ov`), so a value outside it is permitted. |

### 1.4 Custom extensions

Three extensions are declared with `extension-definition` SDOs and validated against
committed JSON Schemas at build time in `APP_ENV=test`:

| Extension | Schema | Proving test |
|-----------|--------|--------------|
| `x_scambuster_context` (on indicators) | `config/stix-schemas/x_scambuster_context.schema.json` | `ExtensionSchemaValidator` via the export handler tests |
| `x_scambuster_mirror` (on notes) | `config/stix-schemas/x_scambuster_mirror.schema.json` | same |
| `x_scambuster_ttp_sighting` (on sightings) | `config/stix-schemas/x_scambuster_ttp_sighting.schema.json` | same, plus the external validator via the cluster bundle |

The sighting extension is the one that matters most here. It is this project's own
addition to the format, it is the object type most likely to trip an external
validator, and it is carried in the cluster bundle that §1.1 validates.

---

## 2. TAXII 2.1

The server is **publish-only**. It implements discovery, collections and object
retrieval. It implements no write path: there are no `POST /objects` endpoints, no
status resources and no object-manifest write behaviour.

Saying so plainly matters more than the list below. A consumer who reads "TAXII 2.1
server" and plans to push data into it has been misled, and no amount of passing
tests fixes that.

| TAXII 2.1 behaviour | Claim | Proving test |
|---------------------|-------|--------------|
| Discovery endpoint | Returns the discovery resource with the required fields | `TaxiiDiscoveryTest::testReturnsCorrectStructure` |
| Media type | Responses carry the TAXII media type | `TaxiiDiscoveryTest::testContentTypeIsTaxii` |
| Authentication | Unauthenticated requests are refused | `TaxiiDiscoveryTest::testRequiresAuthentication`, `TaxiiApiKeyAuthenticatorTest` |
| Collections | Three collections are advertised with their permissions | `TaxiiCollectionsTest::testReturnsThreeCollections`, `::testCollectionsHaveCorrectPermissions` |
| Object retrieval | Objects are returned for a known collection; unknown collections 404 | `TaxiiObjectsTest::testReturnsObjectsForIocCollection`, `::testReturns404ForUnknownCollection` |
| Filtering: `added_after` | Delta sync returns only newer objects | `TaxiiObjectsTest::testAddedAfterFiltersResults` |
| Filtering: `limit` | Pagination limit is honoured | `TaxiiObjectsTest::testLimitParameterWorks` |
| Timestamps | UTC `Z` with milliseconds, never an offset | `StixOasisConformanceTest::testFormatIso8601IsUtcZWithMilliseconds` |
| Feed content policy | Non-actionable header IOC types are excluded from the feed | `TaxiiObjectsTest::testFeedExcludesNonActionableHeaderTypes` |

### Cross-collection references

The cluster bundle attributes a cluster to the indicators that anchored it, and
those indicators are published in the IOC collection rather than duplicated into the
cluster collection. A consumer subscribed to both resolves them; a consumer reading
a cluster bundle standalone will see those relationship endpoints unresolved.

This is enumerated by object type in
`StixExportDeterminismTest::CROSS_COLLECTION_REF_TYPES`, so a *new* unresolved
reference fails the test rather than blending into the existing ones.

---

## 3. MISP

| Claim | Status | Proving test |
|-------|--------|--------------|
| `scambuster:ttp="SB-Txxx"` machine tags are emitted for confirmed TTPs | Proven | `TtpMispTagProviderTest::testTagsForConfirmedTtpsWithGalaxyAllowlistAndFailSafe` |
| ATT&CK galaxy tags are emitted only for verified technique ids, never fabricated | Proven | same test, and `ScamTaxonomyMapperTest` |
| No MISP tag is emitted for a MITRE F3 reference | Proven | `TtpMispTagProviderTest::testF3ReferencesNeverBecomeMispTags` |
| Review-status observations never reach the tags | Proven | `TtpMispTagProviderTest::testTagsForConfirmedTtpsWithGalaxyAllowlistAndFailSafe` |
| Importing the same event twice deduplicates in a MISP instance | **Not yet proven** — see §4 | — |

### Why `scambuster:ttp` tags do not resolve yet

The tags are emitted and are well-formed machine tags, but the `scambuster`
namespace is not registered in the MISP taxonomies repository. Until it is, a
consumer's instance shows the tag as free text rather than resolving it to a
description. Registration is Spec 006 and is gated on the container decision.

---

## 4. Stated, not yet proven

Listed separately on purpose. These are claims the project would like to make and
has not yet earned.

| Claim | What is missing | Why it is not in CI |
|-------|-----------------|---------------------|
| A MISP instance deduplicates a re-imported ScamBuster event, and `scambuster:ttp` tags resolve as machine tags | One recorded round-trip against a real instance: import, check tag resolution, re-import, confirm no new attributes | A MISP service container in CI is heavy — a multi-hundred-megabyte image and a multi-minute boot on every pull request, for a check whose result changes only when the export format changes. It runs as a release gate instead: `scripts/standards/misp-roundtrip.md` is the procedure, and its result is recorded here with a date. This resolves Spec 005's open question in favour of the release-gate script. |
| SCO ids follow the STIX 2.1 contributing-properties recipe | SCO ids are deterministic but derived from type + normalised value instead | Changing the derivation would move every existing SCO id in every consumer that already imported one. It needs a taxonomy-style migration story, not a quiet fix. |
| OpenCTI imports the feed without manual mapping | One recorded import against a current OpenCTI release | Documented in `docs/11_opencti_integration.md` from an earlier manual run; not automated, and not re-verified against the current release. |
| The platforms named "unverified" in `docs/16_taxii_server.md` (TheHive, Splunk, QRadar, Elastic) consume the feed | One recorded run against each | Untested. The TAXII documentation already labels them unverified and that label stays until someone runs them. |

No public text may claim any row in this section as proven (Constitution I and V).
When one is earned, it moves up into §1, §2 or §3 with its proving test named.

---

## 5. Running the checks

```bash
# External STIX 2.1 validation of all three bundle types, plus the gate self-test
scripts/standards/validate-stix-bundles.sh

# Determinism, uniqueness and reference resolution
make testOne q=StixExportDeterminism

# Everything the standards CI job runs
make standards-check
```

The validator is installed into a virtualenv on first run. Two upstream packaging
issues are worked around there, and both are noted in the script: `stix2-validator`
depends on `cpe`, which fails to build under modern setuptools, and the published
wheel does not ship the OASIS JSON schemas.

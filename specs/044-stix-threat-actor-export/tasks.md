# Tasks: STIX Threat Actor Export

**Input**: Design documents from `/specs/044-stix-threat-actor-export/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/
**Status**: COMPLETED (2026-04-07)

**Tests**: Included — spec requires regression tests and new unit/integration tests.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: US1=ThreatActorBuilder, US2=Conversation STIX Export, US3=TAXII Enrichment, US4=Demo Data + Validation

---

## Phase 1: Setup

**Purpose**: Migration and foundational mapping data

- [x] T001 Create MITRE ATT&CK mapping migration in `backend-symfony/migrations/Version20260406180000.php`
- [x] T002 Update `backend-symfony/src/DataFixtures/Communication/ScamTypeFixtures.php` to match the 6 new MITRE mappings

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: MITRE technique name mapping needed by ThreatActorBuilder

- [x] T003 Create MITRE ATT&CK technique name mapping constant in `ThreatActorStixBuilder.php`

---

## Phase 3: User Story 1 — ThreatActorStixBuilder (Priority: P1) MVP

- [x] T004 [P] [US1] Create unit test `ThreatActorStixBuilderTest.php` — 17 tests covering deterministic UUID, sophistication, goals, constants, attack-pattern, extensions
- [x] T005 [US1] Implement `ThreatActorStixBuilder::buildThreatActor()`
- [x] T006 [US1] Implement `ThreatActorStixBuilder::buildAttackPatterns()`
- [x] T007 [US1] Implement `ThreatActorStixBuilder::buildActorRelationships()` — uses + indicates (attributed-to removed: rejected by OpenCTI)
- [x] T008 [US1] Implement `ThreatActorStixBuilder::inferSophistication()`
- [x] T009 [US1] Implement goals mapping — GOALS_MAP for 12 scam types
- [x] T010 [US1] Quality gates pass

---

## Phase 4: User Story 2 — Conversation STIX Export Enrichment (Pivoted from Campaign)

**Design change**: Pivoted from campaign-based to conversation-based. CampaignStixExportHandler left untouched.

- [x] T011 [P] [US2] Create integration test `ConversationStixExportWithActorTest.php` — 7 tests (threat-actor, attack-pattern, indicates, uses, backward compat, no-IOC, field validation)
- [x] T012 [US2] Modify `ConversationStixExportHandler.php` — enrich with threat-actor from conversation metrics, ioc_context excerpts, persona
- [x] T013 [US2] Modify `ExportConversationStixController.php` — read `include_threat_actor` query parameter
- [x] T014 [US2] Quality gates pass

---

## Phase 5: User Story 3 — TAXII IOC Collection Enrichment

- [x] T015 [US3] Modify `TaxiiService.php` — inject ThreatActorStixBuilder, add `enrichIocsWithThreatActors()` method
- [x] T016 [US3] TAXII regression tests pass (9 tests)
- [x] T017 [US3] Quality gates pass

---

## Phase 6: User Story 4 — Demo Data + Validation

- [x] T018 [US4] Demo data: threat-actor generated on-the-fly during STIX export (no pre-computation needed post-pivot)
- [x] T019 [US4] Frontend: existing STIX 2.1 export button on ConversationDetail already includes threat-actor
- [x] T020 [US4] Delete obsolete `GenerateDemoDataCommand.php` (1703 LOC, broken, replaced by `LoadDemoDataCommand`)
- [x] T021 [US4] Docs updated: CHANGELOG, API reference, TAXII guide
- [x] T022 [US4] `app:generate-actor-profiles` NOT scheduled (campaign-based, not needed post-pivot)

---

## Phase 7: Polish & Validation

- [x] T023 Full quality gates: `make test && make stan && make cs-fixer`
- [x] T024 OpenCTI import validation — bundle imported successfully, zero errors, 11 indicators + threat-actor + attack-pattern + 12 relations visible
- [x] T025 Fix: removed `attributed-to` relationship (OpenCTI rejects Report→Threat-Actor-Group)
- [x] T026 Fix: added threat-actor + attack-pattern to report `object_refs` for container linking
- [x] T027 Merged to main, pushed

---

## Bug Fixes Discovered During Implementation

- [x] B001 Fix `ActorProfileGenerator.php`: `mc.message_id` → `mc.msg_id` (2 occurrences)
- [x] B002 Fix `ActorProfileGenerator.php`: `m.direction = 3` → dynamic subquery for 'in' direction
- [x] B003 Fix `GenerateActorProfilesCommand.php`: `mc.message_id` → `mc.msg_id` (2 occurrences)

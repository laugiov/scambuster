# Spec 066a — Test directory reclassification

> **Parent**: `specs/066-ddd-architecture-compliance/`
> **Parent branch**: `roadmap/066-ddd-architecture-compliance`
> **Sprint**: 1 (structural moves, zero logic changes)
> **Effort**: 1 day
> **Sub-spec branch**: `066a-test-reclassification`
> **Internal marker tag**: `066a-merged`

## 1. Context

The backend test suite contains **297 test files** across three directories: `tests/Unit/` (131), `tests/Integration/` (129), `tests/EndToEnd/` (37). An audit on 2026-04-12 revealed that **59 files are in the wrong directory** and **9 files are duplicated** across directories.

The classification rules are unambiguous:

| Directory | Base class | Container | Database | HTTP requests |
|-----------|-----------|-----------|----------|---------------|
| `Unit/` | `PHPUnit\Framework\TestCase` | No | No | No |
| `Integration/` | `KernelTestCase` | Yes | Yes | No |
| `EndToEnd/` | `WebTestCase` | Yes | Yes | Yes (`$client->request()`) |

Any test extending `WebTestCase` and calling `$client->request()` is by definition an EndToEnd test. Any test extending `PHPUnit\Framework\TestCase` with no container access is by definition a Unit test.

### Current misclassification breakdown

| Category | Count | Current location | Correct location |
|----------|-------|-----------------|-----------------|
| WebTestCase in Integration/UI/Http/ | 51 | `tests/Integration/UI/Http/` | `tests/EndToEnd/` |
| WebTestCase in Integration/Auth/ | 5 | `tests/Integration/Auth/` | `tests/EndToEnd/Auth/` |
| WebTestCase in Integration/Communication/ | 1 | `tests/Integration/Communication/` | `tests/EndToEnd/Communication/` |
| PHPUnit\TestCase in Integration/ | 2 | `tests/Integration/` | `tests/Unit/` |
| Duplicate files (Integration ∩ EndToEnd) | 9 | Both directories | EndToEnd/ only |

**After this spec**: Integration/ drops from 129 to ~62 files, EndToEnd/ grows from 37 to ~93 files.

## 2. Goal

After this sub-spec ships:

1. Every file in `tests/Integration/` extends `KernelTestCase` (not `WebTestCase`, not `PHPUnit\Framework\TestCase`).
2. Every file in `tests/EndToEnd/` extends `WebTestCase`.
3. Every file in `tests/Unit/` extends `PHPUnit\Framework\TestCase`.
4. Zero duplicate test files exist across directories.
5. The `tests/Integration/UI/Http/` directory is deleted (empty after moves).
6. All tests pass: `make test` + `make endToEndTest`.
7. Namespaces in moved files match their new directory location.

## 3. Non-goals

- **Rewriting test logic** — files are moved as-is. If a test has poor assertions, that is a separate concern.
- **Changing test base classes** — a WebTestCase test that happens to not make HTTP calls is still moved to EndToEnd/ (its base class determines the classification, not what it currently does).
- **Adding new tests** — no new test methods are written. Only existing files are relocated.
- **CI enforcement** — a PHPStan rule to prevent future reclassification is desirable but out of scope (follow-up).
- **Verifying the other 10 tests/Integration/Auth/ files** — CsrfProtectionTest, LoginFailureTest, LoginRateLimitTest, LoginSuccessTest, ProtectedEndpointTest, RefreshTokenFailureTest, RefreshTokenTest, RoleAccessTest, TotpLoginTest, UserPersistenceTest extend KernelTestCase or a non-WebTestCase abstract base. They are correctly classified; only verify during implementation if in doubt.

## 4. Files to move

### 4.1. Integration/UI/Http/ → EndToEnd/ (51 files)

**Auth/ (4 files):**
| Current | Target |
|---------|--------|
| `tests/Integration/UI/Http/Auth/AdminControllerTest.php` | `tests/EndToEnd/Admin/AdminControllerTest.php` |
| `tests/Integration/UI/Http/Auth/LoginControllerTest.php` | `tests/EndToEnd/Auth/LoginControllerTest.php` |
| `tests/Integration/UI/Http/Auth/LogoutControllerTest.php` | `tests/EndToEnd/Auth/LogoutControllerTest.php` |
| `tests/Integration/UI/Http/Auth/RefreshControllerTest.php` | `tests/EndToEnd/Auth/RefreshControllerTest.php` |

**Campaign/ (11 files):**
| Current | Target |
|---------|--------|
| `tests/Integration/UI/Http/Campaign/ClusterAssignControllerTest.php` | `tests/EndToEnd/Campaign/ClusterAssignControllerTest.php` |
| `tests/Integration/UI/Http/Campaign/CompileCampaignRulesControllerTest.php` | `tests/EndToEnd/Campaign/CompileCampaignRulesControllerTest.php` |
| `tests/Integration/UI/Http/Campaign/ExportCampaignSTIXControllerTest.php` | `tests/EndToEnd/Campaign/ExportCampaignSTIXControllerTest.php` |
| `tests/Integration/UI/Http/Campaign/GetCampaignDetailControllerTest.php` | `tests/EndToEnd/Campaign/GetCampaignDetailControllerTest.php` |
| `tests/Integration/UI/Http/Campaign/GetCampaignMessagesControllerTest.php` | `tests/EndToEnd/Campaign/GetCampaignMessagesControllerTest.php` |
| `tests/Integration/UI/Http/Campaign/GetPromotionCandidatesControllerTest.php` | `tests/EndToEnd/Campaign/GetPromotionCandidatesControllerTest.php` |
| `tests/Integration/UI/Http/Campaign/HuntCampaignsControllerTest.php` | `tests/EndToEnd/Campaign/HuntCampaignsControllerTest.php` |
| `tests/Integration/UI/Http/Campaign/ProfileCampaignControllerTest.php` | `tests/EndToEnd/Campaign/ProfileCampaignControllerTest.php` |
| `tests/Integration/UI/Http/Campaign/PromoteCampaignControllerTest.php` | `tests/EndToEnd/Campaign/PromoteCampaignControllerTest.php` |
| `tests/Integration/UI/Http/Campaign/StoreRuleControllerTest.php` | `tests/EndToEnd/Campaign/StoreRuleControllerTest.php` |
| `tests/Integration/UI/Http/Campaign/TranspileRuleControllerTest.php` | `tests/EndToEnd/Campaign/TranspileRuleControllerTest.php` |

**Communication/ (15 files):**
| Current | Target |
|---------|--------|
| `tests/Integration/UI/Http/Communication/AttachmentControllerTest.php` | **DUPLICATE** — see section 4.5 |
| `tests/Integration/UI/Http/Communication/ConversationControllerTest.php` | **DUPLICATE** — see section 4.5 |
| `tests/Integration/UI/Http/Communication/ExportIocsStixControllerTest.php` | `tests/EndToEnd/Communication/ExportIocsStixControllerTest.php` |
| `tests/Integration/UI/Http/Communication/ExportMispControllerTest.php` | **DUPLICATE** — see section 4.5 |
| `tests/Integration/UI/Http/Communication/GetPersonaControllerTest.php` | `tests/EndToEnd/Communication/GetPersonaControllerTest.php` |
| `tests/Integration/UI/Http/Communication/IngestControllerTest.php` | **DUPLICATE** — see section 4.5 |
| `tests/Integration/UI/Http/Communication/IocContextControllerTest.php` | `tests/EndToEnd/Communication/IocContextControllerTest.php` |
| `tests/Integration/UI/Http/Communication/IocControllerTest.php` | **DUPLICATE** — see section 4.5 |
| `tests/Integration/UI/Http/Communication/IocDetailControllerTest.php` | `tests/EndToEnd/Communication/IocDetailControllerTest.php` |
| `tests/Integration/UI/Http/Communication/IocGraphControllerTest.php` | `tests/EndToEnd/Communication/IocGraphControllerTest.php` |
| `tests/Integration/UI/Http/Communication/MessageControllerTest.php` | **DUPLICATE** — see section 4.5 |
| `tests/Integration/UI/Http/Communication/ReplyControllerTest.php` | **DUPLICATE** — see section 4.5 |
| `tests/Integration/UI/Http/Communication/ScamTypeControllerTest.php` | **DUPLICATE** — see section 4.5 |
| `tests/Integration/UI/Http/Communication/TogglePersonaActiveControllerTest.php` | `tests/EndToEnd/Communication/TogglePersonaActiveControllerTest.php` |
| `tests/Integration/UI/Http/Communication/UpdatePersonaControllerTest.php` | `tests/EndToEnd/Communication/UpdatePersonaControllerTest.php` |

**Internal/ (1 file):**
| Current | Target |
|---------|--------|
| `tests/Integration/UI/Http/Internal/MailAccountActiveControllerTest.php` | `tests/EndToEnd/Internal/MailAccountActiveControllerTest.php` |

**Meta/ (1 file):**
| Current | Target |
|---------|--------|
| `tests/Integration/UI/Http/Meta/ConfigControllerTest.php` | `tests/EndToEnd/Meta/ConfigControllerTest.php` |

**Monitoring/ (11 files):**
| Current | Target |
|---------|--------|
| `tests/Integration/UI/Http/Monitoring/AnalyticsControllerTest.php` | `tests/EndToEnd/Monitoring/AnalyticsControllerTest.php` |
| `tests/Integration/UI/Http/Monitoring/AuditControllerTest.php` | `tests/EndToEnd/Monitoring/AuditControllerTest.php` |
| `tests/Integration/UI/Http/Monitoring/AutonomyMonitoringControllerTest.php` | **DUPLICATE** — see section 4.5 |
| `tests/Integration/UI/Http/Monitoring/ConvergenceHistoryControllerTest.php` | `tests/EndToEnd/Monitoring/ConvergenceHistoryControllerTest.php` |
| `tests/Integration/UI/Http/Monitoring/ConversationLifecycleControllerTest.php` | `tests/EndToEnd/Monitoring/ConversationLifecycleControllerTest.php` |
| `tests/Integration/UI/Http/Monitoring/HealthCheckControllerTest.php` | `tests/EndToEnd/Monitoring/HealthCheckControllerTest.php` |
| `tests/Integration/UI/Http/Monitoring/ImpactControllerTest.php` | `tests/EndToEnd/Monitoring/ImpactControllerTest.php` |
| `tests/Integration/UI/Http/Monitoring/LlmCostControllerTest.php` | `tests/EndToEnd/Monitoring/LlmCostControllerTest.php` |
| `tests/Integration/UI/Http/Monitoring/MetricsControllerTest.php` | `tests/EndToEnd/Monitoring/MetricsControllerTest.php` |
| `tests/Integration/UI/Http/Monitoring/RateLimitControllerTest.php` | `tests/EndToEnd/Monitoring/RateLimitControllerTest.php` |

**Scambaiting/ (5 files):**
| Current | Target |
|---------|--------|
| `tests/Integration/UI/Http/Scambaiting/CloseConversationControllerTest.php` | `tests/EndToEnd/Scambaiting/CloseConversationControllerTest.php` |
| `tests/Integration/UI/Http/Scambaiting/GetAllScambaitingStatsControllerTest.php` | `tests/EndToEnd/Scambaiting/GetAllScambaitingStatsControllerTest.php` |
| `tests/Integration/UI/Http/Scambaiting/GetPersonaPerformanceControllerTest.php` | `tests/EndToEnd/Scambaiting/GetPersonaPerformanceControllerTest.php` |
| `tests/Integration/UI/Http/Scambaiting/GetScambaitingStatsControllerTest.php` | `tests/EndToEnd/Scambaiting/GetScambaitingStatsControllerTest.php` |
| `tests/Integration/UI/Http/Scambaiting/SelectPersonaControllerTest.php` | `tests/EndToEnd/Scambaiting/SelectPersonaControllerTest.php` |

**Taxii/ (3 files):**
| Current | Target |
|---------|--------|
| `tests/Integration/UI/Http/Taxii/TaxiiCollectionsTest.php` | `tests/EndToEnd/Taxii/TaxiiCollectionsTest.php` |
| `tests/Integration/UI/Http/Taxii/TaxiiDiscoveryTest.php` | `tests/EndToEnd/Taxii/TaxiiDiscoveryTest.php` |
| `tests/Integration/UI/Http/Taxii/TaxiiObjectsTest.php` | `tests/EndToEnd/Taxii/TaxiiObjectsTest.php` |

**User/ (1 file):**
| Current | Target |
|---------|--------|
| `tests/Integration/UI/Http/User/MeControllerTest.php` | `tests/EndToEnd/User/MeControllerTest.php` |

### 4.2. Integration/Auth/ WebTestCase → EndToEnd/Auth/ (5 files)

| Current | Target |
|---------|--------|
| `tests/Integration/Auth/AbstractAuthBase.php` | `tests/EndToEnd/Auth/AbstractAuthBase.php` |
| `tests/Integration/Auth/AuthServiceWiringTest.php` | `tests/EndToEnd/Auth/AuthServiceWiringTest.php` |
| `tests/Integration/Auth/HealthCheckTest.php` | `tests/EndToEnd/Auth/HealthCheckTest.php` |
| `tests/Integration/Auth/MeControllerTest.php` | `tests/EndToEnd/Auth/MeControllerTest.php` |
| `tests/Integration/Auth/TotpSetupTest.php` | `tests/EndToEnd/Auth/TotpSetupTest.php` |

**Note**: `AbstractAuthBase.php` is the shared base class for AuthServiceWiringTest, HealthCheckTest, MeControllerTest, TotpSetupTest. It extends WebTestCase, so it moves with them. If any of the remaining 10 `Integration/Auth/` tests imports `AbstractAuthBase`, their import must be updated too (but they stay in Integration/ if they extend KernelTestCase).

### 4.3. Integration/Communication/ WebTestCase → EndToEnd/ (1 file)

| Current | Target |
|---------|--------|
| `tests/Integration/Communication/IocEnrichmentTest.php` | `tests/EndToEnd/Communication/IocEnrichmentTest.php` |

### 4.4. Integration/ → Unit/ (2 files)

| Current | Target | Reason |
|---------|--------|--------|
| `tests/Integration/Communication/MimeMailParserIntegrationTest.php` | `tests/Unit/Infrastructure/Mime/MimeMailParserTest.php` | Extends `PHPUnit\Framework\TestCase`, no container, no DB |
| `tests/Integration/Campaign/STIXExporterTest.php` | `tests/Unit/Application/Campaign/STIXExporterTest.php` | Extends `PHPUnit\Framework\TestCase`, no container, no DB |

### 4.5. Duplicate consolidation (9 pairs)

For each duplicate pair, the Integration/ version is deleted and the EndToEnd/ version is kept. Before deleting, compare both files: if the Integration/ version has test methods not present in the EndToEnd/ version, those methods must be merged into the EndToEnd/ file.

| # | Integration/ (DELETE after merge) | EndToEnd/ (KEEP) |
|---|----------------------------------|-----------------|
| 1 | `Integration/UI/Http/Communication/AttachmentControllerTest.php` | `EndToEnd/Communication/AttachmentControllerTest.php` |
| 2 | `Integration/UI/Http/Communication/ConversationControllerTest.php` | `EndToEnd/Communication/ConversationControllerTest.php` |
| 3 | `Integration/UI/Http/Communication/ExportMispControllerTest.php` | `EndToEnd/Communication/ExportMispControllerTest.php` |
| 4 | `Integration/UI/Http/Communication/IngestControllerTest.php` | `EndToEnd/Communication/IngestControllerTest.php` |
| 5 | `Integration/UI/Http/Communication/IocControllerTest.php` | `EndToEnd/Communication/IocControllerTest.php` |
| 6 | `Integration/UI/Http/Communication/MessageControllerTest.php` | `EndToEnd/Communication/MessageControllerTest.php` |
| 7 | `Integration/UI/Http/Communication/ReplyControllerTest.php` | `EndToEnd/Communication/ReplyControllerTest.php` |
| 8 | `Integration/UI/Http/Communication/ScamTypeControllerTest.php` | `EndToEnd/Communication/ScamTypeControllerTest.php` |
| 9 | `Integration/UI/Http/Monitoring/AutonomyMonitoringControllerTest.php` | `EndToEnd/Monitoring/AutonomyMonitoringControllerTest.php` |

**Merge protocol**: For each pair:
1. Read both files completely
2. List all test methods in each
3. If Integration/ has methods NOT in EndToEnd/ → copy those methods into EndToEnd/ file
4. If Integration/ has unique `use` imports → add them to EndToEnd/ file
5. Delete the Integration/ file
6. Run `make endToEndTest` to verify

## 5. User stories

### Story 1 — New developer orientation

> **As** a new developer joining the ScamBuster project,
> **when** I need to write a test that makes HTTP requests,
> **I look** in `tests/EndToEnd/` and find all similar tests there (not scattered across Integration/),
> **so that** I follow the correct pattern without confusion.

### Story 2 — CI pipeline optimization

> **As** a CI engineer optimizing the test pipeline,
> **when** I want to run only fast tests (Unit + Integration, no HTTP),
> **I run** `make test` knowing it will not accidentally execute WebTestCase HTTP tests,
> **so that** my fast feedback loop is actually fast.

### Story 3 — Test count accuracy

> **As** a project lead reviewing test coverage,
> **when** I count tests by category (Unit: 131, Integration: 62, EndToEnd: 93),
> **I get** accurate numbers that reflect what each suite actually tests,
> **so that** I can make informed decisions about coverage gaps.

## 6. Acceptance criteria

### Structural

- [ ] **AC-S1**: `tests/Integration/UI/Http/` directory does not exist (all files moved).
- [ ] **AC-S2**: `grep -r 'extends WebTestCase' tests/Integration/` returns zero results.
- [ ] **AC-S3**: `grep -r 'extends TestCase' tests/Integration/ | grep -v KernelTestCase` returns zero results (no pure PHPUnit\TestCase in Integration/).
- [ ] **AC-S4**: Every `.php` file in `tests/EndToEnd/` either extends `WebTestCase` or is a base class that extends `WebTestCase`.
- [ ] **AC-S5**: Zero duplicate filenames exist across `tests/Integration/` and `tests/EndToEnd/` for the same controller.
- [ ] **AC-S6**: Every moved file has its `namespace` declaration updated to match its new directory.

### Test suite health

- [ ] **AC-T1**: `make test` passes (unit + integration).
- [ ] **AC-T2**: `make endToEndTest` passes.
- [ ] **AC-T3**: PHPStan level 6 clean on all touched files.
- [ ] **AC-T4**: Total test count before and after is identical (minus any duplicate methods that were already identical).

### Commit structure

- [ ] **AC-C1**: One atomic commit per subdirectory move (Auth, Campaign, Communication, etc.) — not one giant commit.
- [ ] **AC-C2**: Duplicate consolidation is a separate commit per pair (or grouped if trivially identical).
- [ ] **AC-C3**: Unit reclassification (2 files) is its own commit.

## 7. Implementation notes

### Namespace update pattern

For each moved file, update the namespace. Example:

```php
// Before (in Integration/UI/Http/Campaign/):
namespace App\Tests\Integration\UI\Http\Campaign;

// After (in EndToEnd/Campaign/):
namespace App\Tests\EndToEnd\Campaign;
```

### PHPUnit configuration

Verify that `phpunit.xml.dist` and `phpunit-e2e.xml.dist` test suite definitions use directory-based discovery (`<directory>tests/Integration</directory>`, `<directory>tests/EndToEnd</directory>`). If so, no config changes are needed — moved files are auto-discovered.

### Fixture references

Some Integration/ tests may reference fixture files via relative paths (e.g., `__DIR__ . '/../../fixtures/...'`). After moving to EndToEnd/, these paths may break. Check and fix any `__DIR__`-relative fixture references.

### AbstractAuthBase dependency

`tests/Integration/Auth/AbstractAuthBase.php` is used by other Integration/Auth/ tests that stay. If those tests `use` the abstract base, they will break when it moves to EndToEnd/Auth/. Two options:
1. **Preferred**: Copy AbstractAuthBase to both locations (EndToEnd/Auth/ and Integration/Auth/) with appropriate namespaces.
2. **Alternative**: Create a shared `tests/Shared/AbstractAuthBase.php` used by both suites.

Decision to be made during implementation based on actual usage analysis.

## 8. Risk assessment

| Risk | Probability | Impact | Mitigation |
|------|-------------|--------|------------|
| Broken `use` imports after move | High | Low | Search-replace in moved files; PHPStan catches missing imports |
| Broken `__DIR__` fixture paths | Medium | Low | Grep for `__DIR__` in moved files; fix relative paths |
| CI config doesn't discover moved files | Low | High | Verify phpunit.xml.dist before and after; run full suite |
| AbstractAuthBase breaks Integration/ tests | Medium | Medium | Analyze dependents before moving; keep copy if needed |
| Test count changes due to duplicate merge | Low | Low | Document before/after test count per file |

## 9. Out of scope

- Adding new test methods or improving test quality.
- Changing test base classes (a WebTestCase test stays WebTestCase even if it doesn't currently make HTTP calls).
- Writing CI enforcement rules (PHPStan custom rules to prevent regression).
- Touching `tests/Fake/` or `tests/Fixtures/` — supporting infrastructure stays.
- Reclassifying any test that is debatable — only clear violations (WebTestCase in Integration/, TestCase in Integration/) are moved.

## 10. References

- Architecture audit: 2026-04-12, findings T1-T5
- Parent spec: `specs/066-ddd-architecture-compliance/spec.md`
- Symfony testing docs: WebTestCase = functional/E2E, KernelTestCase = integration

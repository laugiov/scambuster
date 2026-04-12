# Spec 066e — Console command service extraction

> **Parent**: `specs/066-ddd-architecture-compliance/`
> **Parent branch**: `roadmap/066-ddd-architecture-compliance`
> **Sprint**: 2 (logic refactoring)
> **Effort**: 2-3 days
> **Sub-spec branch**: `066e-command-service-extraction`
> **Internal marker tag**: `066e-merged`
> **Prerequisite**: 066b merged (commands already in `src/UI/Console/`)

## 1. Context

Console commands are UI-layer entry points — the CLI equivalent of HTTP controllers. Like controllers, they should delegate business logic to Application services rather than injecting `EntityManagerInterface` or `Connection` directly.

The audit found **15 commands injecting EntityManagerInterface** and **13 commands injecting Connection** across `src/Command/` (pre-066b) and `src/UI/Console/`. After analysis, the picture is nuanced:

### Already correctly delegating (no action needed)

| Command | Delegates to | EM usage |
|---------|-------------|----------|
| `CalculateRewardsCommand` | `ConversationClosureService` | EM for query builder (acceptable) |
| `CloseStaleConversationsCommand` | `ConversationClosureService` | EM for query builder (acceptable) |
| `PreprodGenerateConversationsCommand` | `ConversationGenerator` | EM for `clear()` (memory management) |
| `ClusteringBackfillCommand` | `IocClusteringService` | Connection for batch orchestration |
| `ComputeIocContextCommand` | `IocContextService` | Connection for batch orchestration |
| `VerifyAuditChainCommand` | `AuditHmacChainer` | Connection for batch read |

### Diagnostic/debug (acceptable as-is)

| Command | EM usage | Justification |
|---------|----------|---------------|
| `CheckMessageHeadersCommand` | `find()` single entity | Dev-only debug tool, minimal coupling |
| `TestConversationContextCommand` | `find()` single entity | Dev-only debug tool, minimal coupling |
| `TestContextCommand` | None | Already clean |
| `TestReplyGenerateCommand` | None | Already clean |

### Connection-only batch operations (pragmatic DDD: acceptable)

In pragmatic DDD, `Connection` (DBAL) is a thin SQL abstraction — less coupling than EntityManager. For batch operations that process thousands of rows, using Connection directly in commands is acceptable and often necessary for performance. These are **low priority** refactoring targets:

| Command | Tables | Connection usage |
|---------|--------|-----------------|
| `WeeklyCleanupCommand` | conversation, llm_usage | Batch UPDATE/DELETE |
| `PreprodCopyConversationsCommand` | conversation, message | dblink bulk copy |
| `ClusterExportStixCommand` | threat_actor_cluster | Read-only aggregation |
| `CleanupPlatformContaminationCommand` | observed_ioc, indicator | 7-phase transactional cleanup |
| `GenerateActorProfilesCommand` | campaign, actor_profile | Batch INSERT |
| `GenerateEmbeddingsCommand` | message, message_vector | Batch INSERT |
| `LoadDemoDataCommand` | ALL tables | Bulk seed (150 conversations) |

### Genuinely needs extraction (9 commands — THIS SPEC'S SCOPE)

| Command | Current EM usage | New Application service |
|---------|-----------------|----------------------|
| `AnalyzeThreadingCommand` | `getConnection()` for raw SQL | `MessageThreadingAnalyzer` |
| `BanditDailyReportCommand` | `getConnection()` + `persist()` + `flush()` | `BanditConvergenceReporter` |
| `DetectPromptInjectionCommand` | `getRepository()` + `flush()` | `PromptInjectionBatchAnalyzer` |
| `LinkScamTypesPersonasCommand` | `getRepository()` + `persist()` + `flush()` | `ScamTypePersonaLinker` |
| `SiemExportCommand` | `getConnection()` for audit queries | `AuditEventBatchFetcher` |
| `MigrateHeaderIocsCommand` | `getRepository()` + query builder | `HeaderIocMigrator` |
| `MigrateIocsExportMetadataCommand` | `getRepository()` + `flush()` | `IocExportMetadataEnricher` |
| `PreprodClearConversationsCommand` | `getConnection()` for TRUNCATE | `PreprodDataCleaner` |
| `PreprodCopyConversationsCommand` | Hardcoded DSN + raw SQL | `PreprodDataCopier` |

## 2. Goal

After this sub-spec ships:

1. The 9 targeted commands delegate ALL persistence logic to Application services.
2. None of the 9 targeted commands inject `EntityManagerInterface`.
3. Each new Application service has comprehensive unit tests (mocked dependencies).
4. Existing integration tests for commands still pass.
5. No functional behavior changes.

## 3. Non-goals

- **Extracting Connection from batch commands** — the 7 Connection-only batch commands listed above are pragmatically acceptable and out of scope.
- **Extracting EM from commands that already delegate correctly** — CalculateRewardsCommand, CloseStaleConversationsCommand, PreprodGenerateConversationsCommand are fine.
- **Removing debug commands** — they stay as-is.
- **Creating generic batch processing abstractions** — each service is purpose-built.
- **Adding tests for untested commands** — only the new services get tests; if a command has no test, adding one is out of scope.

## 4. Detailed extractions

### 4.1. AnalyzeThreadingCommand → MessageThreadingAnalyzer

**Current**: Command injects EM, calls `$em->getConnection()`, runs raw SQL to analyze message threading by subject pattern.

**New service**: `src/Application/Communication/MessageThreadingAnalyzer.php`
```php
namespace App\Application\Communication;

final class MessageThreadingAnalyzer
{
    public function __construct(
        private readonly Connection $connection,
    ) {}

    /** @return array<array{subject: string, count: int, conversations: int}> */
    public function analyzeBySubjectPattern(): array { /* raw SQL stays here */ }
}
```

**Command change**: Replace `EntityManagerInterface` with `MessageThreadingAnalyzer`.

**Tests**: Unit test mocking Connection, asserting result shape.

---

### 4.2. BanditDailyReportCommand → BanditConvergenceReporter

**Current**: Command injects EM, queries `lkp_scam_type`, `persona_performance_stats`, `persona`, computes convergence metrics, persists to `bandit_convergence_log`.

**New service**: `src/Application/Monitoring/BanditConvergenceReporter.php`
```php
namespace App\Application\Monitoring;

final class BanditConvergenceReporter
{
    public function __construct(
        private readonly Connection $connection,
    ) {}

    /** @return array{snapshots: int, scam_types: int} */
    public function generateDailySnapshot(): array { /* query + persist logic */ }
}
```

**Command change**: Replace `EntityManagerInterface` with `BanditConvergenceReporter`.

**Tests**: Unit test with mocked Connection, asserting snapshot computation.

---

### 4.3. DetectPromptInjectionCommand → PromptInjectionBatchAnalyzer

**Current**: Command injects EM, queries messages via query builder, delegates analysis to `PromptInjectionDetector`, calls `flush()` to persist results.

**New service**: `src/Application/Communication/PromptInjectionBatchAnalyzer.php`
```php
namespace App\Application\Communication;

final class PromptInjectionBatchAnalyzer
{
    public function __construct(
        private readonly EntityManagerInterface $em,  // stays in Application for ORM query builder
        private readonly PromptInjectionDetector $detector,
    ) {}

    /** @return array{analyzed: int, detected: int} */
    public function analyzeUnscannedMessages(int $limit = 100): array { /* query + detect + flush */ }
}
```

**Note**: This service still uses EM (Application layer pragmatic DDD exception for the 46-file pattern). The key improvement is that the COMMAND no longer injects EM — it delegates to an Application service.

**Command change**: Replace `EntityManagerInterface` + `PromptInjectionDetector` with `PromptInjectionBatchAnalyzer`.

**Tests**: Unit test mocking EM + detector, asserting batch analysis counts.

---

### 4.4. LinkScamTypesPersonasCommand → ScamTypePersonaLinker

**Current**: Command injects EM, contains hardcoded scam-type-to-persona mapping array, queries both repositories, calls `persist()` + `flush()`.

**New service**: `src/Application/Communication/ScamTypePersonaLinker.php`
```php
namespace App\Application\Communication;

final class ScamTypePersonaLinker
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /** @return array{linked: int, skipped: int} */
    public function linkAll(): array { /* mapping + query + persist */ }
}
```

**Command change**: Replace `EntityManagerInterface` with `ScamTypePersonaLinker`.

**Tests**: Unit test mocking EM, asserting linking counts.

---

### 4.5. SiemExportCommand → AuditEventBatchFetcher

**Current**: Command injects EM, calls `$em->getConnection()`, runs raw SQL on `audit_log` table to fetch events in a date range, feeds them to `SiemExporterInterface`.

**New service**: `src/Application/Audit/AuditEventBatchFetcher.php`
```php
namespace App\Application\Audit;

final class AuditEventBatchFetcher
{
    public function __construct(
        private readonly Connection $connection,
    ) {}

    /** @return array<array<string, mixed>> */
    public function fetchByDateRange(\DateTimeInterface $from, \DateTimeInterface $to, int $limit = 1000): array
    { /* raw SQL */ }
}
```

**Command change**: Replace `EntityManagerInterface` with `AuditEventBatchFetcher`.

**Tests**: Unit test mocking Connection, asserting date range filtering.

---

### 4.6. MigrateHeaderIocsCommand → HeaderIocMigrator

**Current**: Command injects EM, queries messages by direction, iterates and calls `IocHandler` for each.

**New service**: `src/Application/Communication/HeaderIocMigrator.php`
```php
namespace App\Application\Communication;

final class HeaderIocMigrator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IocHandler $iocHandler,
    ) {}

    /** @return array{processed: int, extracted: int, errors: int} */
    public function migrateHeaders(int $batchSize = 100): array { /* query + extract + persist */ }
}
```

**Command change**: Replace `EntityManagerInterface` + `IocHandler` with `HeaderIocMigrator`.

**Tests**: Unit test mocking EM + IocHandler, asserting migration counts.

---

### 4.7. MigrateIocsExportMetadataCommand → IocExportMetadataEnricher

**Current**: Command injects EM, calls `findAll()` on observed_ioc, enriches with `IocExportMapper`, calls `flush()`.

**New service**: `src/Application/Communication/IocExportMetadataEnricher.php`
```php
namespace App\Application\Communication;

final class IocExportMetadataEnricher
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IocExportMapper $mapper,
    ) {}

    /** @return array{enriched: int, skipped: int} */
    public function enrichAll(): array { /* findAll + map + flush */ }
}
```

**Command change**: Replace `EntityManagerInterface` + `IocExportMapper` with `IocExportMetadataEnricher`.

**Tests**: Unit test mocking EM + mapper, asserting enrichment counts.

---

### 4.8. PreprodClearConversationsCommand → PreprodDataCleaner

**Current**: Command injects EM, runs TRUNCATE on conversation/message tables.

**New service**: `src/Application/Preprod/PreprodDataCleaner.php`
```php
namespace App\Application\Preprod;

final class PreprodDataCleaner
{
    public function __construct(
        private readonly Connection $connection,
    ) {}

    /** @return array{tables_cleared: int} */
    public function clearAll(): array { /* TRUNCATE with safety checks */ }
}
```

**Command change**: Replace `EntityManagerInterface` with `PreprodDataCleaner`.

**Tests**: Unit test mocking Connection, asserting TRUNCATE SQL calls.

---

### 4.9. PreprodCopyConversationsCommand → PreprodDataCopier

**Current**: Command injects Connection, contains HARDCODED preprod DSN string, runs dblink INSERT statements.

**New service**: `src/Application/Preprod/PreprodDataCopier.php`
```php
namespace App\Application\Preprod;

final class PreprodDataCopier
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $preprodDsn,  // from env var, not hardcoded
    ) {}

    /** @return array{tables_copied: int, rows: int} */
    public function copyFromPreprod(): array { /* dblink SQL */ }
}
```

**Command change**: Replace `Connection` + hardcoded DSN with `PreprodDataCopier`.

**Tests**: Unit test mocking Connection, asserting dblink SQL calls with parameterized DSN.

---

## 5. User stories

### Story 1 — Testability

> **As** a developer writing unit tests for batch operations,
> **I can** mock the Application service instead of the entire EntityManager,
> **so that** my tests are focused, fast, and don't require a database.

### Story 2 — Reusability

> **As** a developer building a new admin endpoint,
> **I can** reuse `AuditEventBatchFetcher` from both the console command AND a hypothetical API endpoint,
> **because** the logic lives in a service, not inside the command class.

### Story 3 — Security

> **As** a security reviewer auditing the codebase,
> **when** I check the preprod copy command,
> **I see** the DSN coming from an environment variable via DI,
> **not** a hardcoded connection string in the PHP source.

## 6. Acceptance criteria

### Per extraction (repeated 9 times)

- [ ] **AC-E{n}.1**: Command class does NOT inject `EntityManagerInterface`.
- [ ] **AC-E{n}.2**: New Application service exists with the documented constructor signature.
- [ ] **AC-E{n}.3**: New unit test exists for the Application service with 3+ test cases.
- [ ] **AC-E{n}.4**: Command delegates to the service and is reduced to pure CLI concerns (argument parsing, output formatting, exit codes).
- [ ] **AC-E{n}.5**: Existing integration test for the command (if any) still passes.

### Global

- [ ] **AC-G1**: After all extractions, `grep -r 'EntityManagerInterface' src/UI/Console/` returns only the 6 "acceptable" commands listed in section 1 + the Connection-only batch commands.
- [ ] **AC-G2**: PHPStan level 6 clean on all new and modified files.
- [ ] **AC-G3**: `make test` passes.
- [ ] **AC-G4**: `make endToEndTest` passes.
- [ ] **AC-G5**: PHP-CS-Fixer clean on all touched files.
- [ ] **AC-G6**: PreprodCopyConversationsCommand no longer contains a hardcoded DSN string.

## 7. Commit structure

One atomic commit per extraction (service + updated command + tests):

1. `refactor(threading): extract MessageThreadingAnalyzer from AnalyzeThreadingCommand`
2. `refactor(bandit): extract BanditConvergenceReporter from BanditDailyReportCommand`
3. `refactor(injection): extract PromptInjectionBatchAnalyzer from DetectPromptInjectionCommand`
4. `refactor(personas): extract ScamTypePersonaLinker from LinkScamTypesPersonasCommand`
5. `refactor(siem): extract AuditEventBatchFetcher from SiemExportCommand`
6. `refactor(migration): extract HeaderIocMigrator from MigrateHeaderIocsCommand`
7. `refactor(migration): extract IocExportMetadataEnricher from MigrateIocsExportMetadataCommand`
8. `refactor(preprod): extract PreprodDataCleaner from PreprodClearConversationsCommand`
9. `refactor(preprod): extract PreprodDataCopier from PreprodCopyConversationsCommand`

## 8. TDD protocol

For each extraction:

1. **Red**: Write the Application service unit test first. It fails because the service doesn't exist.
2. **Green**: Create the service, extracting logic from the command. Test passes.
3. **Refactor**: Update the command to inject the service instead of EM. Run integration tests.
4. **Gate**: PHPStan + CS-Fixer + `make test` + `make endToEndTest`.

## 9. Risk assessment

| Risk | Probability | Impact | Mitigation |
|------|-------------|--------|------------|
| Batch performance regression (ORM overhead) | Low | Medium | Services use Connection for batch ops, not ORM |
| PreprodCopy DSN env var missing in preprod | Medium | Low | Default to empty string, validate in service constructor |
| Circular dependency between services | Low | High | Each service is standalone; no cross-service calls |
| Missing `flush()` after extraction | Medium | High | Unit tests verify persistence calls; integration tests catch omissions |

## 10. Out of scope

- Extracting Connection from the 7 batch commands classified as "pragmatic DDD acceptable".
- Adding integration tests for commands that currently have none.
- Reorganizing Application services into subdomain directories.
- Creating abstract batch processing base classes.
- Performance optimization of batch operations.

## 11. References

- Architecture audit: 2026-04-12, findings E1 and E2
- Parent spec: `specs/066-ddd-architecture-compliance/spec.md`
- CLAUDE.md: "Handlers: private readonly EM" (Application layer pragmatic exception)
- Spec 065f: Introduced SiemExportCommand and VerifyAuditChainCommand
- Spec 065b: Introduced CheckLlmBudgetCommand

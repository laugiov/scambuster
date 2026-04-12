# Spec 066b — Console command namespace unification

> **Parent**: `specs/066-ddd-architecture-compliance/`
> **Parent branch**: `roadmap/066-ddd-architecture-compliance`
> **Sprint**: 1 (structural moves, zero logic changes)
> **Effort**: 1 day
> **Sub-spec branch**: `066b-command-namespace-unification`
> **Internal marker tag**: `066b-merged`

## 1. Context

In a DDD architecture, console commands are the CLI equivalent of HTTP controllers — they are UI entry points. The canonical location is `src/UI/Console/`, mirroring `src/UI/Http/` for HTTP controllers.

The codebase currently has commands split between two locations:

| Location | Files | Namespace |
|----------|-------|-----------|
| `src/Command/` | 22 | `App\Command\` |
| `src/UI/Console/` | 11 | `App\UI\Console\` |

The `src/Command/` location is a Symfony default that was never cleaned up. All 22 commands there should be in `src/UI/Console/` with the `App\UI\Console\` namespace.

This spec is purely mechanical: move files, update namespaces, update service definitions, update test imports. Zero business logic changes.

## 2. Goal

After this sub-spec ships:

1. `src/Command/` directory does not exist (deleted after all moves).
2. All 33 console commands live under `src/UI/Console/` with namespace `App\UI\Console\`.
3. All service definitions referencing `App\Command\*` are updated.
4. All test files referencing `App\Command\*` have updated imports.
5. All commands are discoverable and functional: `php bin/console list` shows all commands.
6. Full test suite green.

## 3. Non-goals

- **Refactoring command logic** — commands are moved as-is, even if they inject EntityManager. That is spec 066e's scope.
- **Adding missing tests** — 6 commands have no tests (ComputeIocContextCommand, PreprodCopyConversationsCommand, PreprodGenerateConversationsCommand, TestContextCommand, TestConversationContextCommand, VerifyAuditChainCommand). Writing them is out of scope.
- **Organizing by subdomain** — commands are moved flat into `src/UI/Console/`, not into subdirectories. Subdomain organization (e.g., `Console/Communication/`, `Console/Monitoring/`) is a future concern.
- **Removing debug commands** — TestContextCommand, TestConversationContextCommand, TestReplyGenerateCommand are dev-only debug tools. They stay (could be gated behind `kernel.debug` later).

## 4. Files to move

### 4.1. Complete inventory (22 files)

| # | Current path | Target path | EM/Conn? | Has tests? |
|---|-------------|------------|----------|------------|
| 1 | `src/Command/AnalyzeThreadingCommand.php` | `src/UI/Console/AnalyzeThreadingCommand.php` | EM | Yes |
| 2 | `src/Command/BanditDailyReportCommand.php` | `src/UI/Console/BanditDailyReportCommand.php` | EM | Yes |
| 3 | `src/Command/CalculateRewardsCommand.php` | `src/UI/Console/CalculateRewardsCommand.php` | EM | Yes |
| 4 | `src/Command/CheckLlmBudgetCommand.php` | `src/UI/Console/CheckLlmBudgetCommand.php` | — | Yes |
| 5 | `src/Command/CheckMessageHeadersCommand.php` | `src/UI/Console/CheckMessageHeadersCommand.php` | EM | Yes |
| 6 | `src/Command/CloseStaleConversationsCommand.php` | `src/UI/Console/CloseStaleConversationsCommand.php` | EM | Yes |
| 7 | `src/Command/ClusterExportStixCommand.php` | `src/UI/Console/ClusterExportStixCommand.php` | Conn | Yes |
| 8 | `src/Command/ClusteringBackfillCommand.php` | `src/UI/Console/ClusteringBackfillCommand.php` | Conn | Yes |
| 9 | `src/Command/ComputeIocContextCommand.php` | `src/UI/Console/ComputeIocContextCommand.php` | Conn | No |
| 10 | `src/Command/DetectPromptInjectionCommand.php` | `src/UI/Console/DetectPromptInjectionCommand.php` | EM | Yes |
| 11 | `src/Command/GenerateLoginHashCommand.php` | `src/UI/Console/GenerateLoginHashCommand.php` | — | Yes |
| 12 | `src/Command/LinkScamTypesPersonasCommand.php` | `src/UI/Console/LinkScamTypesPersonasCommand.php` | EM | Yes |
| 13 | `src/Command/PreprodCopyConversationsCommand.php` | `src/UI/Console/PreprodCopyConversationsCommand.php` | Conn | No |
| 14 | `src/Command/PreprodGenerateConversationsCommand.php` | `src/UI/Console/PreprodGenerateConversationsCommand.php` | EM | No |
| 15 | `src/Command/PurgeRgpdCommand.php` | `src/UI/Console/PurgeRgpdCommand.php` | — | Yes |
| 16 | `src/Command/SiemExportCommand.php` | `src/UI/Console/SiemExportCommand.php` | EM | Yes (Unit + Integration) |
| 17 | `src/Command/SiemTestCommand.php` | `src/UI/Console/SiemTestCommand.php` | — | Yes (Unit + Integration) |
| 18 | `src/Command/TestContextCommand.php` | `src/UI/Console/TestContextCommand.php` | — | No |
| 19 | `src/Command/TestConversationContextCommand.php` | `src/UI/Console/TestConversationContextCommand.php` | EM | No |
| 20 | `src/Command/TestReplyGenerateCommand.php` | `src/UI/Console/TestReplyGenerateCommand.php` | — | No |
| 21 | `src/Command/VerifyAuditChainCommand.php` | `src/UI/Console/VerifyAuditChainCommand.php` | Conn | No |
| 22 | `src/Command/WeeklyCleanupCommand.php` | `src/UI/Console/WeeklyCleanupCommand.php` | Conn | Yes |

**Legend**: EM = EntityManagerInterface, Conn = Connection, — = neither.

### 4.2. Service definitions to update

Only 1 command has an explicit service definition in `config/`:

- `config/packages/llm.yaml` or `config/services.yaml`: `App\Command\CheckLlmBudgetCommand` → `App\UI\Console\CheckLlmBudgetCommand`

All other commands rely on Symfony autowiring + autoconfigure. Verify that `config/services.yaml` has an `App\UI\Console\` resource entry for autodiscovery. If not, add one:

```yaml
App\UI\Console\:
    resource: '../src/UI/Console/'
    tags: ['console.command']
```

**Also check**: `config/services.yaml` likely has `App\Command\` as a resource entry. Remove it after all commands are moved.

### 4.3. Test files with import updates

Each test file that imports a command from `App\Command\*` must be updated. Known test locations:

| Test file | Current import | New import |
|-----------|---------------|------------|
| `tests/Integration/Command/AnalyzeThreadingCommandTest.php` | `App\Command\AnalyzeThreadingCommand` | `App\UI\Console\AnalyzeThreadingCommand` |
| `tests/Integration/Command/BanditDailyReportCommandTest.php` | `App\Command\BanditDailyReportCommand` | `App\UI\Console\BanditDailyReportCommand` |
| `tests/Integration/Command/CalculateRewardsCommandTest.php` | `App\Command\CalculateRewardsCommand` | `App\UI\Console\CalculateRewardsCommand` |
| `tests/Integration/Command/CheckLlmBudgetCommandTest.php` | `App\Command\CheckLlmBudgetCommand` | `App\UI\Console\CheckLlmBudgetCommand` |
| `tests/Integration/Command/CheckMessageHeadersCommandTest.php` | `App\Command\CheckMessageHeadersCommand` | `App\UI\Console\CheckMessageHeadersCommand` |
| `tests/Integration/Command/CloseStaleConversationsCommandTest.php` | `App\Command\CloseStaleConversationsCommand` | `App\UI\Console\CloseStaleConversationsCommand` |
| `tests/Integration/Clustering/ExportStixCommandTest.php` | `App\Command\ClusterExportStixCommand` | `App\UI\Console\ClusterExportStixCommand` |
| `tests/Integration/Clustering/BackfillCommandTest.php` | `App\Command\ClusteringBackfillCommand` | `App\UI\Console\ClusteringBackfillCommand` |
| `tests/Integration/Command/DetectPromptInjectionCommandTest.php` | `App\Command\DetectPromptInjectionCommand` | `App\UI\Console\DetectPromptInjectionCommand` |
| `tests/Integration/Command/GenerateLoginHashCommandTest.php` | `App\Command\GenerateLoginHashCommand` | `App\UI\Console\GenerateLoginHashCommand` |
| `tests/Integration/Command/LinkScamTypesPersonasCommandTest.php` | `App\Command\LinkScamTypesPersonasCommand` | `App\UI\Console\LinkScamTypesPersonasCommand` |
| `tests/Integration/Command/PurgeRgpdCommandTest.php` | `App\Command\PurgeRgpdCommand` | `App\UI\Console\PurgeRgpdCommand` |
| `tests/Integration/Command/SiemExportCommandTest.php` | `App\Command\SiemExportCommand` | `App\UI\Console\SiemExportCommand` |
| `tests/Integration/Command/SiemTestCommandTest.php` | `App\Command\SiemTestCommand` | `App\UI\Console\SiemTestCommand` |
| `tests/Integration/Command/WeeklyCleanupCommandTest.php` | `App\Command\WeeklyCleanupCommand` | `App\UI\Console\WeeklyCleanupCommand` |
| `tests/Unit/Command/SiemExportCommandTest.php` | `App\Command\SiemExportCommand` | `App\UI\Console\SiemExportCommand` |
| `tests/Unit/Command/SiemTestCommandTest.php` | `App\Command\SiemTestCommand` | `App\UI\Console\SiemTestCommand` |
| `tests/Integration/Command/MigrateHeaderIocsCommandSkipOutgoingTest.php` | Verify if it imports any `App\Command\*` | Update if needed |

**Also search**: `grep -r 'App\\Command\\' tests/` to catch any unlisted references.

### 4.4. Internal cross-references

Search for any `use App\Command\*` inside `src/` itself (e.g., a service referencing a command class). Unlikely but must be verified.

## 5. User stories

### Story 1 — Architectural consistency

> **As** a developer navigating the codebase,
> **when** I need to find or create a console command,
> **I go to** `src/UI/Console/` — the single canonical location,
> **not** wondering whether it's in `src/Command/` or `src/UI/Console/`.

### Story 2 — DDD layer clarity

> **As** an architect reviewing the DDD structure,
> **when** I look at `src/UI/`,
> **I see** `Http/` for HTTP controllers and `Console/` for CLI commands — both are user interface entry points,
> **so that** the layered architecture is visually and structurally clear.

## 6. Acceptance criteria

### Structural

- [ ] **AC-S1**: `src/Command/` directory does not exist.
- [ ] **AC-S2**: `grep -r 'namespace App\\Command' src/` returns zero results.
- [ ] **AC-S3**: `grep -r 'use App\\Command\\' src/ tests/` returns zero results.
- [ ] **AC-S4**: `php bin/console list` in the dev container shows all 33 commands (verify count matches before/after).
- [ ] **AC-S5**: `config/services.yaml` has no `App\Command\` resource entry.
- [ ] **AC-S6**: Every moved file has `namespace App\UI\Console;` as its namespace declaration.

### Test suite health

- [ ] **AC-T1**: `make test` passes (unit + integration).
- [ ] **AC-T2**: `make endToEndTest` passes.
- [ ] **AC-T3**: PHPStan level 6 clean on all touched files.
- [ ] **AC-T4**: PHP-CS-Fixer clean on all touched files.

### Commit structure

- [ ] **AC-C1**: One commit per batch of related commands (e.g., all SIEM commands together, all clustering commands together, all debug commands together). Target: 4-6 atomic commits, not 22 individual commits and not 1 monolithic commit.
- [ ] **AC-C2**: Service definition updates are in the same commit as the corresponding command move.
- [ ] **AC-C3**: Test import updates are in the same commit as the corresponding command move.

## 7. Implementation notes

### Namespace update pattern

```php
// Before:
namespace App\Command;

// After:
namespace App\UI\Console;
```

### Symfony autodiscovery

Verify `config/services.yaml` includes:
```yaml
App\UI\Console\:
    resource: '../src/UI/Console/'
```

If the existing entry is `App\Command\:` → change it to `App\UI\Console\:`.
If both entries exist → remove `App\Command\:`, keep `App\UI\Console\:`.

### Command name preservation

Symfony console command names (the string in `#[AsCommand(name: 'app:xxx')]`) do NOT change. Only the PHP namespace and file location change. Users calling `php bin/console app:close-stale-conversations` are unaffected.

### Suggested commit grouping

1. **Monitoring commands**: CheckLlmBudgetCommand, VerifyAuditChainCommand, SiemExportCommand, SiemTestCommand, BanditDailyReportCommand
2. **Lifecycle commands**: CloseStaleConversationsCommand, PurgeRgpdCommand, WeeklyCleanupCommand, CalculateRewardsCommand
3. **Communication commands**: AnalyzeThreadingCommand, CheckMessageHeadersCommand, DetectPromptInjectionCommand, ComputeIocContextCommand
4. **Clustering commands**: ClusteringBackfillCommand, ClusterExportStixCommand
5. **Data/Setup commands**: LinkScamTypesPersonasCommand, GenerateLoginHashCommand, PreprodGenerateConversationsCommand, PreprodCopyConversationsCommand
6. **Debug commands**: TestContextCommand, TestConversationContextCommand, TestReplyGenerateCommand
7. **Cleanup**: Remove `src/Command/` directory, remove `App\Command\` service resource entry

## 8. Risk assessment

| Risk | Probability | Impact | Mitigation |
|------|-------------|--------|------------|
| Symfony autoconfigure fails to discover moved commands | Low | High | Verify services.yaml resource entry; run `bin/console list` |
| Missed `use App\Command\*` in test files | Medium | Low | `grep -r` before committing; PHPStan catches it |
| Scheduler/cron references hard-coded class names | Low | Medium | Search docker-compose, crontab, n8n workflows for class names |
| Explicit service definition not updated | Low | Medium | Only 1 known (CheckLlmBudgetCommand); search `config/` for all |

## 9. Out of scope

- Refactoring command internals (EntityManager removal → spec 066e).
- Adding tests for untested commands.
- Organizing commands into subdirectories (Console/Monitoring/, Console/Communication/, etc.).
- Renaming command names (`app:xxx` strings stay the same).
- Moving test files (e.g., `tests/Integration/Command/` to `tests/Integration/Console/`) — that would be a naming preference, not a DDD violation.

## 10. References

- Architecture audit: 2026-04-12, finding C1
- Parent spec: `specs/066-ddd-architecture-compliance/spec.md`
- Symfony console docs: commands are auto-discovered when in a resource directory with `autoconfigure: true`

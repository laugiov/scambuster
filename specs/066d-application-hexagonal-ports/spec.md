# Spec 066d — Application layer hexagonal ports

> **Parent**: `specs/066-ddd-architecture-compliance/`
> **Parent branch**: `roadmap/066-ddd-architecture-compliance`
> **Sprint**: 2 (logic refactoring)
> **Effort**: 1 day
> **Sub-spec branch**: `066d-application-hexagonal-ports`
> **Internal marker tag**: `066d-merged`

## 1. Context

In hexagonal (ports & adapters) architecture, Application services define **port interfaces** in the Domain or Application layer and receive **adapters** (implementations) from the Infrastructure layer via dependency injection. The Application layer should NEVER import Doctrine's `EntityManagerInterface` directly — it should use repository interfaces that the Infrastructure layer implements.

The audit on 2026-04-12 found one Application service violating this principle:

**`EntityReferenceResolver`** (`src/Application/Communication/EntityReferenceResolver.php`)

This service was extracted from `IngestHandler` during spec 065h (god class decomposition). During extraction, the existing `$this->em->getRepository()` calls were moved verbatim instead of being replaced with proper port interfaces. The extraction reduced coupling at the handler level but introduced a hexagonal violation at the service level.

### Current code (simplified)

```php
namespace App\Application\Communication;

use Doctrine\ORM\EntityManagerInterface;

final class EntityReferenceResolver
{
    public function __construct(
        private readonly EntityManagerInterface $em,  // VIOLATION
        private readonly LoggerInterface $logger,
    ) {}

    public function resolve(string $accountId, string $channelCode = 'email'): ResolvedReferences
    {
        $account = $this->em->getRepository(MailAccount::class)->find($accountId);      // line 36
        $channel = $this->em->getRepository(Channel::class)->findOneBy([...]);          // line 44
        $direction = $this->em->getRepository(Direction::class)->findOneBy([...]);      // line 52
        // ...
    }
}
```

Three distinct entity lookups, each of which should go through a Domain repository interface.

## 2. Goal

After this sub-spec ships:

1. `EntityReferenceResolver` does NOT import or inject `EntityManagerInterface`.
2. Three new Domain repository interfaces exist: `MailAccountRepositoryInterface`, `ChannelRepositoryInterface`, `DirectionRepositoryInterface`.
3. Three new Infrastructure Doctrine implementations exist, one per interface.
4. Service wiring binds interfaces to implementations.
5. All existing tests pass unchanged.
6. `grep -r 'EntityManagerInterface' src/Application/Communication/EntityReferenceResolver.php` returns zero results.

## 3. Non-goals

- **Refactoring ALL Application services that use EntityManager** — there are 46 files with this pattern. Only `EntityReferenceResolver` is in scope because it was specifically extracted during spec 065h and should have been done properly then.
- **Creating a generic RepositoryInterface** — each entity gets its own focused interface with only the methods actually needed.
- **Adding methods to interfaces that aren't used** — interfaces expose the minimum surface (e.g., `findById()` not `findAll()`).
- **Moving existing Doctrine repositories** — if `MailAccountRepository` already exists in Infrastructure, it implements the new interface. No file moves.

## 4. Detailed changes

### 4.1. New Domain repository interfaces

**File 1**: `src/Domain/Communication/Repository/MailAccountRepositoryInterface.php`

```php
namespace App\Domain\Communication\Repository;

use App\Domain\Communication\MailAccount;

interface MailAccountRepositoryInterface
{
    public function findById(string $id): ?MailAccount;
}
```

**File 2**: `src/Domain/Communication/Repository/ChannelRepositoryInterface.php`

```php
namespace App\Domain\Communication\Repository;

use App\Domain\Communication\Channel;

interface ChannelRepositoryInterface
{
    public function findByCode(string $code): ?Channel;
}
```

**File 3**: `src/Domain/Communication/Repository/DirectionRepositoryInterface.php`

```php
namespace App\Domain\Communication\Repository;

use App\Domain\Communication\Direction;

interface DirectionRepositoryInterface
{
    public function findByCode(string $code): ?Direction;
}
```

### 4.2. New or updated Infrastructure implementations

Check if Doctrine repository classes already exist for these entities. If they do, make them implement the new interface. If they don't, create minimal implementations.

**Option A — Existing repository exists** (e.g., `Infrastructure/Doctrine/Repository/MailAccountRepository.php`):
```php
// Add interface to class declaration:
class MailAccountRepository extends ServiceEntityRepository implements MailAccountRepositoryInterface
{
    // findById() may already exist as find(); add alias method if needed
}
```

**Option B — No existing repository** (create new):
```php
namespace App\Infrastructure\Doctrine\Repository;

use App\Domain\Communication\Repository\MailAccountRepositoryInterface;
use App\Domain\Communication\MailAccount;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineMailAccountRepository implements MailAccountRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function findById(string $id): ?MailAccount
    {
        return $this->em->getRepository(MailAccount::class)->find($id);
    }
}
```

Same pattern for `DoctrineChannelRepository` and `DoctrineDirectionRepository`.

### 4.3. Updated EntityReferenceResolver

```php
namespace App\Application\Communication;

use App\Domain\Communication\Repository\MailAccountRepositoryInterface;
use App\Domain\Communication\Repository\ChannelRepositoryInterface;
use App\Domain\Communication\Repository\DirectionRepositoryInterface;
use Psr\Log\LoggerInterface;

final class EntityReferenceResolver
{
    public function __construct(
        private readonly MailAccountRepositoryInterface $mailAccountRepo,
        private readonly ChannelRepositoryInterface $channelRepo,
        private readonly DirectionRepositoryInterface $directionRepo,
        private readonly LoggerInterface $logger,
    ) {}

    public function resolve(string $accountId, string $channelCode = 'email'): ResolvedReferences
    {
        $account = $this->mailAccountRepo->findById($accountId);
        $channel = $this->channelRepo->findByCode($channelCode);
        $direction = $this->directionRepo->findByCode('in');
        // ... rest of logic unchanged
    }
}
```

### 4.4. Service wiring

Add to `config/services.yaml` or `config/packages/doctrine.yaml`:

```yaml
App\Domain\Communication\Repository\MailAccountRepositoryInterface:
    class: App\Infrastructure\Doctrine\Repository\DoctrineMailAccountRepository

App\Domain\Communication\Repository\ChannelRepositoryInterface:
    class: App\Infrastructure\Doctrine\Repository\DoctrineChannelRepository

App\Domain\Communication\Repository\DirectionRepositoryInterface:
    class: App\Infrastructure\Doctrine\Repository\DoctrineDirectionRepository
```

### 4.5. Tests

**New unit tests** (3 files):

1. `tests/Unit/Infrastructure/Doctrine/Repository/DoctrineMailAccountRepositoryTest.php`
2. `tests/Unit/Infrastructure/Doctrine/Repository/DoctrineChannelRepositoryTest.php`
3. `tests/Unit/Infrastructure/Doctrine/Repository/DoctrineDirectionRepositoryTest.php`

Each test mocks EntityManager and verifies the repository delegates correctly.

**New unit test** for EntityReferenceResolver:

4. `tests/Unit/Application/Communication/EntityReferenceResolverTest.php`

This test injects mock repository interfaces and verifies:
- Happy path: all 3 entities found → returns ResolvedReferences
- MailAccount not found → appropriate error/null handling
- Channel not found → appropriate error/null handling
- Direction not found → appropriate error/null handling

**Existing tests**: Any integration test that exercises `IngestHandler` (which calls `EntityReferenceResolver`) must still pass. The real Doctrine implementations will be autowired in the test container.

## 5. User stories

### Story 1 — Testing in isolation

> **As** a developer writing a unit test for `EntityReferenceResolver`,
> **I can** mock the three repository interfaces independently,
> **instead of** setting up a full Doctrine EntityManager mock with repository factory methods,
> **so that** my test is focused, readable, and fast.

### Story 2 — Infrastructure swap

> **As** an architect evaluating a future move from Doctrine to a different ORM (or to raw SQL),
> **I know** that only the Infrastructure layer implementations need to change,
> **because** the Application layer depends on interfaces, not on Doctrine directly.

## 6. Acceptance criteria

### Structural

- [ ] **AC-S1**: `EntityReferenceResolver` does not import any `Doctrine\*` namespace.
- [ ] **AC-S2**: Three interfaces exist in `src/Domain/Communication/Repository/`.
- [ ] **AC-S3**: Three implementations exist in `src/Infrastructure/Doctrine/Repository/` (or existing repositories implement the interfaces).
- [ ] **AC-S4**: Service wiring binds each interface to its implementation.
- [ ] **AC-S5**: `grep -r 'EntityManagerInterface' src/Application/Communication/EntityReferenceResolver.php` returns zero results.

### Test coverage

- [ ] **AC-T1**: Unit test for `EntityReferenceResolver` with mocked repository interfaces (4+ test cases).
- [ ] **AC-T2**: Unit tests for each Doctrine repository implementation (3 files, 2+ cases each).
- [ ] **AC-T3**: `make test` passes (unit + integration).
- [ ] **AC-T4**: `make endToEndTest` passes.
- [ ] **AC-T5**: PHPStan level 6 clean on all new and modified files.

### Quality

- [ ] **AC-Q1**: Interfaces expose ONLY the methods actually called by `EntityReferenceResolver` (minimum surface principle).
- [ ] **AC-Q2**: No `findAll()`, `findBy()`, or `count()` methods on interfaces unless explicitly needed.
- [ ] **AC-Q3**: Interface methods use Domain types in their signatures, not Doctrine types.

## 7. Commit structure

1. **Commit 1**: Create 3 Domain repository interfaces (no implementation yet).
2. **Commit 2**: Create 3 Infrastructure implementations + unit tests.
3. **Commit 3**: Update `EntityReferenceResolver` to use interfaces + unit test + service wiring.
4. **Commit 4**: Run full suite, verify green.

## 8. Risk assessment

| Risk | Probability | Impact | Mitigation |
|------|-------------|--------|------------|
| Existing Doctrine repos conflict with new interfaces | Medium | Low | Check existing repos first; add interface, don't create duplicate |
| Service wiring mismatch (wrong impl bound) | Low | High | Integration tests will catch immediately |
| ResolvedReferences DTO changes needed | Low | Low | DTO is a simple value object; unlikely to need changes |
| Other Application services also need these interfaces | Medium | Low | Out of scope; they can use the same interfaces later |

## 9. Out of scope

- Creating repository interfaces for entities not accessed by `EntityReferenceResolver`.
- Refactoring other Application services to use these interfaces (that is item A3 from the architecture audit — a much larger effort).
- Adding query methods beyond what `EntityReferenceResolver` needs.
- Changing the `ResolvedReferences` value object.

## 10. References

- Architecture audit: 2026-04-12, finding V2
- Parent spec: `specs/066-ddd-architecture-compliance/spec.md`
- Spec 065h: Extraction of EntityReferenceResolver from IngestHandler
- Hexagonal Architecture: Alistair Cockburn (ports = interfaces in Domain, adapters = implementations in Infrastructure)

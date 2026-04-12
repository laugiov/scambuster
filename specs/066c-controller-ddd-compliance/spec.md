# Spec 066c — Controller DDD compliance

> **Parent**: `specs/066-ddd-architecture-compliance/`
> **Parent branch**: `roadmap/066-ddd-architecture-compliance`
> **Sprint**: 2 (logic refactoring)
> **Effort**: 0.5 day
> **Sub-spec branch**: `066c-controller-ddd-compliance`
> **Internal marker tag**: `066c-merged`

## 1. Context

The project's DDD rules for controllers are explicit (from CLAUDE.md and development-instructions.md):

> Controllers: `final`, `__invoke` only, NO EntityManager, delegate to handler.

Two controllers violate these rules:

| Controller | Violation | Severity |
|-----------|-----------|----------|
| `LoginController` | Injects `EntityManagerInterface` to check user TOTP status | MEDIUM |
| `AdminLlmKillSwitchController` | Has 2 public methods (`getState()` + `toggle()`) instead of `__invoke` | MEDIUM |

Both were introduced during spec 065 (security quality hardening). They work correctly — the violations are structural, not functional.

## 2. Goal

After this sub-spec ships:

1. `LoginController` does NOT inject `EntityManagerInterface`. The TOTP check is delegated to a service.
2. `AdminLlmKillSwitchController` is replaced by two single-action `__invoke` controllers.
3. All existing tests pass unchanged.
4. No functional behavior changes — the HTTP API contract (routes, request/response shapes) is identical.

## 3. Non-goals

- **Refactoring LoginController authentication logic** — the `AuthServiceInterface` delegation is correct. Only the TOTP check EM usage is in scope.
- **Adding new API endpoints** — the routes stay identical, just split across two controller files.
- **Changing response formats** — HTTP responses are identical byte-for-byte.
- **Adding new tests** — existing test coverage is sufficient for the refactoring.

## 4. Detailed changes

### 4.1. LoginController — Extract TOTP check

**File**: `src/UI/Http/Auth/LoginController.php`

**Current violation** (line 71):
```php
private readonly EntityManagerInterface $em,
```

**Current usage** (around line 161):
```php
$user = $this->em->getRepository(User::class)->findOneBy(['email' => $dto->email]);
if ($user && $user->isTotpEnabled()) {
    // return 2FA required response
}
```

**Fix**: Create a `UserTotpCheckerInterface` port in the Application layer and implement it in Infrastructure.

**New files**:

1. **Port** — `src/Application/Auth/Port/UserTotpCheckerInterface.php`:
```php
namespace App\Application\Auth\Port;

interface UserTotpCheckerInterface
{
    /**
     * Check if the given user email has TOTP (2FA) enabled.
     */
    public function isTotpRequired(string $email): bool;
}
```

2. **Implementation** — `src/Infrastructure/Auth/DoctrineUserTotpChecker.php`:
```php
namespace App\Infrastructure\Auth;

use App\Application\Auth\Port\UserTotpCheckerInterface;
use App\Domain\Auth\User;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineUserTotpChecker implements UserTotpCheckerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function isTotpRequired(string $email): bool
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        return $user !== null && $user->isTotpEnabled();
    }
}
```

3. **Updated LoginController** — remove `EntityManagerInterface` from constructor, inject `UserTotpCheckerInterface` instead:
```php
public function __construct(
    private readonly AuthServiceInterface $handler,
    private readonly UserTotpCheckerInterface $totpChecker,
    // ... other deps (no EntityManagerInterface)
) {}

// Usage becomes:
if ($this->totpChecker->isTotpRequired($dto->email)) {
    // return 2FA required response
}
```

4. **Service wiring** — add to `config/packages/security.yaml` or `config/services.yaml`:
```yaml
App\Application\Auth\Port\UserTotpCheckerInterface:
    class: App\Infrastructure\Auth\DoctrineUserTotpChecker
```

**Tests**:
- New unit test: `tests/Unit/Infrastructure/Auth/DoctrineUserTotpCheckerTest.php` (mock EM, test true/false/null cases)
- Existing `LoginControllerTest` and `LoginEndToEndTest` must still pass (no behavior change)

### 4.2. AdminLlmKillSwitchController — Split into two __invoke controllers

**File**: `src/UI/Http/Admin/AdminLlmKillSwitchController.php`

**Current violation**: 2 public methods:
- `public function getState(): JsonResponse` (line ~61, `#[Route(..., methods: ['GET'])]`)
- `public function toggle(Request $request): JsonResponse` (line ~101, `#[Route(..., methods: ['POST'])]`)

**Fix**: Split into two `final` single-action controllers.

**New files**:

1. **`src/UI/Http/Admin/GetLlmKillSwitchStateController.php`**:
```php
namespace App\UI\Http\Admin;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/admin/llm/killswitch', name: 'admin_llm_killswitch_get', methods: ['GET'])]
final class GetLlmKillSwitchStateController
{
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
    ) {}

    public function __invoke(): JsonResponse
    {
        // Move getState() body here
    }
}
```

2. **`src/UI/Http/Admin/ToggleLlmKillSwitchController.php`**:
```php
namespace App\UI\Http\Admin;

use App\Application\Audit\AuditLogger;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/admin/llm/killswitch', name: 'admin_llm_killswitch_toggle', methods: ['POST'])]
final class ToggleLlmKillSwitchController
{
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        // Move toggle() body here
    }
}
```

3. **Delete** the original `AdminLlmKillSwitchController.php`.

**Tests**:
- Update `tests/EndToEnd/Admin/AdminLlmKillSwitchControllerTest.php` — imports and class references change, but test logic stays identical (same routes, same HTTP methods, same responses).
- Run full E2E suite to verify.

### 4.3. Frontend impact

The frontend calls these endpoints by URL, not by PHP class name. No frontend changes are needed. The routes are identical:
- `GET /api/v1/admin/llm/killswitch` — same URL, same response
- `POST /api/v1/admin/llm/killswitch` — same URL, same response

## 5. User stories

### Story 1 — Architecture reviewer

> **As** an architect reviewing the UI/Http layer,
> **when** I inspect any controller,
> **I see** exactly one public method (`__invoke`) and zero EntityManager injection,
> **so that** the DDD rules are consistently enforced.

### Story 2 — New feature developer

> **As** a developer adding a new admin endpoint,
> **I follow** the established pattern of one-action controllers,
> **because** every existing controller in the codebase demonstrates this pattern — including the kill switch endpoints.

## 6. Acceptance criteria

### LoginController

- [ ] **AC-L1**: `LoginController` constructor does NOT inject `EntityManagerInterface`.
- [ ] **AC-L2**: `UserTotpCheckerInterface` exists in `src/Application/Auth/Port/`.
- [ ] **AC-L3**: `DoctrineUserTotpChecker` exists in `src/Infrastructure/Auth/` and implements the interface.
- [ ] **AC-L4**: Service wiring exists binding the interface to the implementation.
- [ ] **AC-L5**: A unit test for `DoctrineUserTotpChecker` exists with at least 3 cases (TOTP enabled, TOTP disabled, user not found).
- [ ] **AC-L6**: All existing login tests pass unchanged.

### AdminLlmKillSwitchController

- [ ] **AC-K1**: `AdminLlmKillSwitchController.php` is deleted.
- [ ] **AC-K2**: `GetLlmKillSwitchStateController.php` exists with a single `__invoke` method.
- [ ] **AC-K3**: `ToggleLlmKillSwitchController.php` exists with a single `__invoke` method.
- [ ] **AC-K4**: `GET /api/v1/admin/llm/killswitch` returns the same response as before.
- [ ] **AC-K5**: `POST /api/v1/admin/llm/killswitch` returns the same response as before.
- [ ] **AC-K6**: All existing kill switch E2E tests pass unchanged (or with minimal import updates).

### General

- [ ] **AC-G1**: `grep -r 'EntityManagerInterface' src/UI/Http/` returns zero results.
- [ ] **AC-G2**: Every PHP file in `src/UI/Http/` has exactly one public method (`__invoke`), excluding abstract base classes.
- [ ] **AC-G3**: PHPStan level 6 clean.
- [ ] **AC-G4**: `make test` + `make endToEndTest` green.

## 7. Commit structure

1. **Commit 1**: Extract `UserTotpCheckerInterface` + `DoctrineUserTotpChecker` + unit test. LoginController not yet changed.
2. **Commit 2**: Update `LoginController` to use `UserTotpCheckerInterface` instead of EM. Wire service. Run tests.
3. **Commit 3**: Split `AdminLlmKillSwitchController` into 2 controllers. Delete original. Update E2E test. Run tests.

## 8. Risk assessment

| Risk | Probability | Impact | Mitigation |
|------|-------------|--------|------------|
| Route collision after split | Low | High | Verify route names are unique; test both GET and POST |
| Missing security annotation on new controllers | Medium | High | Copy `#[IsGranted]` from original; E2E tests verify 403 for non-admin |
| TOTP check behavior subtly changes | Low | Medium | Unit test covers edge cases; E2E login tests verify full flow |

## 9. Out of scope

- Extracting other services from LoginController (e.g., rate limiter logic). Only EM removal.
- Adding OpenAPI annotations to new controllers. They should be copied from the original but not enhanced.
- Refactoring the kill switch business logic into an Application service. The logic is trivial (cache get/set) and acceptable in the controller layer.

## 10. References

- Architecture audit: 2026-04-12, findings V1 and V3
- Parent spec: `specs/066-ddd-architecture-compliance/spec.md`
- CLAUDE.md: "Controllers: final, __invoke only, NO EntityManager, delegate to handler"
- Spec 065b: Introduced CheckLlmBudgetCommand and kill switch
- Spec 065e: Introduced LoginController rate limiter + TOTP check

# Spec 067a — TOTP controllers EntityManager extraction

> **Parent**: `specs/067-remaining-controller-compliance/`
> **Sprint**: 1 | **Effort**: 0.5 day
> **Sub-spec branch**: `067a-totp-controller-em-extraction`

## Scope

3 controllers inject EntityManagerInterface to look up User entity for TOTP operations.
All 3 are already `final` + `__invoke`. Only EM needs replacing.

| Controller | Route | EM usage |
|-----------|-------|---------|
| `TotpLoginController` | POST `/api/v1/auth/2fa/login` | `$em->getRepository(User::class)->findOneBy(['email' => ...])` |
| `TotpSetupController` | POST `/api/v1/2fa/setup` | `$em->getRepository(User::class)->findOneBy(['email' => ...])` + `$em->flush()` |
| `TotpVerifyController` | POST `/api/v1/2fa/verify` | `$em->getRepository(User::class)->findOneBy(['email' => ...])` + `$em->flush()` |

## Fix

Create `UserRepositoryInterface` in Domain with methods:
- `findByEmail(string $email): ?User`
- `save(User $user): void` (wraps flush for TOTP enable/verify)

Implement as `DoctrineUserRepository` in Infrastructure.

Replace EM injection in all 3 controllers with `UserRepositoryInterface`.

## Acceptance criteria
- [ ] Zero `EntityManagerInterface` in `src/UI/Http/Auth/`
- [ ] `UserRepositoryInterface` exists in `src/Domain/User/Repository/`
- [ ] All login/TOTP E2E tests pass unchanged

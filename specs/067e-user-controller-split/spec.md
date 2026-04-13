# Spec 067e — User controller cleanup

> **Parent**: `specs/067-remaining-controller-compliance/`
> **Sprint**: 2 | **Effort**: 0.5 day
> **Sub-spec branch**: `067e-user-controller-split`

## Scope

2 controllers in `src/UI/Http/User/` with route collisions and multiple public methods:

| Controller | Methods | Issue |
|-----------|---------|-------|
| `ProtectedController` | `someProtectedEndpoint` (POST `/some-protected-endpoint`), `adminProtectedEndpoint` (POST `/admin-protected-endpoint`) | Multi-action + route overlap with TestProtected |
| `TestProtectedController` | `protectedEndpoint` (POST `/some-protected-endpoint`), `adminEndpoint` (POST `/admin/endpoint`) | Multi-action + route collision |

**Route collision**: Both controllers define POST `/api/v1/some-protected-endpoint`.

## Fix

1. Delete `TestProtectedController` (duplicate/scaffolding)
2. Split `ProtectedController` into 2 `__invoke` controllers:
   - `UserProtectedController` → POST `/api/v1/some-protected-endpoint`
   - `AdminProtectedController` → POST `/api/v1/admin-protected-endpoint`

## Acceptance criteria
- [ ] `TestProtectedController.php` deleted
- [ ] `ProtectedController.php` deleted
- [ ] 2 new `__invoke` controllers
- [ ] No route collisions
- [ ] All tests pass

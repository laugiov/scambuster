# Spec 067b — Controller Connection extraction

> **Parent**: `specs/067-remaining-controller-compliance/`
> **Sprint**: 1 | **Effort**: 0.5 day
> **Sub-spec branch**: `067b-controller-connection-extraction`

## Scope

2 controllers inject `Doctrine\DBAL\Connection` for raw SQL queries.
Both are already `final` + `__invoke`. Only Connection needs replacing.

| Controller | Route | Connection usage |
|-----------|-------|-----------------|
| `IocContextController` | GET `/api/v1/iocs/{indicatorId}/context` | Raw SQL query on ioc_context table |
| `AuditController` | GET `/api/v1/monitoring/audit` | Raw SQL query on audit_log table |

## Fix

- `IocContextController` → extract SQL to `IocContextQueryService` (Application)
- `AuditController` → extract SQL to `AuditQueryService` (Application)

Controllers inject the new services instead of Connection.

## Acceptance criteria
- [ ] Zero `Connection` in `src/UI/Http/`
- [ ] 2 new Application services created
- [ ] All Functional + E2E tests pass unchanged

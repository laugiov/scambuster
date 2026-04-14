# Spec 070d — Doctrine Performance Hardening

> **Parent**: `specs/070-code-quality-hardening/`
> **Effort**: 1 day
> **Branch**: `070d-doctrine-performance`

## 1. Context

Audit found:
- `auto_generate_proxy_classes: true` globally (should be false in prod)
- No second-level cache configured
- 6 potential N+1 patterns (low count but should be guarded)
- No SQL query count assertions in integration tests

## 2. Changes

### 2.1 Proxy generation per environment
- `config/packages/doctrine.yaml`: `auto_generate_proxy_classes: true` (keep for dev)
- `config/packages/prod/doctrine.yaml`: `auto_generate_proxy_classes: false`
- Add `bin/console doctrine:proxy:generate` to deployment script

### 2.2 N+1 query guard assertions
Add SQL query count assertions in critical integration tests:
- IngestHandler: verify max N queries per ingest (not proportional to attachments/IOCs)
- IocQueryService: verify batch queries (not per-IOC)
- ClusterQueryService: verify detail query count is bounded

Pattern:
```php
$this->getContainer()->get('doctrine.dbal.default_connection')->getConfiguration()
    ->setSQLLogger(new DebugStack());
// ... execute code ...
$queryCount = count($sqlLogger->queries);
$this->assertLessThanOrEqual(15, $queryCount, 'Ingest should not generate N+1 queries');
```

### 2.3 Eager loading where needed
Review and add `fetch: EAGER` on:
- `Message::$direction` (always accessed, lookup table)
- `Message::$channel` (always accessed, lookup table)
- `Conversation::$scamType` (almost always accessed)

### 2.4 Second-level cache (optional)
Evaluate Doctrine SLC for lookup tables (Direction, Channel, ScamType). These are read-heavy, write-never.

## 3. Acceptance criteria

- [ ] `auto_generate_proxy_classes: false` in prod config
- [ ] At least 3 integration tests with SQL query count assertions
- [ ] Eager loading added for lookup table relations
- [ ] `make test` + `make endToEndTest` green
- [ ] No performance regression (query count stable or improved)

## 4. Tasks

- [ ] **T1** Create `config/packages/prod/doctrine.yaml` with proxy gen disabled
- [ ] **T2** Add query count assertions to IngestHandler integration test
- [ ] **T3** Add query count assertions to ClusterQueryService test
- [ ] **T4** Add query count assertions to IocQueryService test
- [ ] **T5** Add `fetch: EAGER` on Message→Direction, Message→Channel, Conversation→ScamType
- [ ] **T6** Evaluate and optionally configure SLC for lookup tables
- [ ] **T7** Full CI validation

### Integration process per commit:
```
1. Make change
2. phpstan analyse src/ --level 6 --memory-limit=512M
3. php-cs-fixer fix src/ --dry-run
4. make test
5. make endToEndTest
6. git commit
```

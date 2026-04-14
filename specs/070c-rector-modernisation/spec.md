# Spec 070c — Rector PHP Modernisation

> **Parent**: `specs/070-code-quality-hardening/`
> **Effort**: 0.5 day
> **Branch**: `070c-rector-modernisation`

## 1. Context

Codebase targets PHP 8.2+ but some legacy patterns remain. Rector automates mechanical refactoring to modern PHP idioms.

Audit found:
- 16 `array_key_exists()` that could use `isset()` or null coalescing
- 2 `switch` statements convertible to `match`
- 5 `readonly class` opportunities on DTOs/VOs
- Missing `#[\Override]` attributes (PHP 8.3)

## 2. Approach

1. Install Rector
2. Configure with PHP 8.3 target + Symfony set
3. Run in dry-run to assess scope
4. Apply fixes in batches (one commit per Rector rule category)
5. Verify all tests pass after each batch
6. Remove Rector from dependencies (dev-only tool, run once)

## 3. Rule categories to apply

| Category | Rules | Expected changes |
|----------|-------|-----------------|
| PHP 8.3 dead code | `json_validate()`, typed class constants | ~5 files |
| Code quality | `array_key_exists` → null coalescing, strict comparisons | ~16 files |
| Type declarations | Add `#[\Override]`, narrow return types | ~20 files |
| Early return | Convert nested if/else to early return | ~10 files |

## 4. Acceptance criteria

- [ ] All Rector fixes applied
- [ ] PHPStan level 6 clean after changes
- [ ] CS-Fixer clean
- [ ] `make test` + `make endToEndTest` green
- [ ] No behavior changes (pure refactoring)

## 5. Tasks

- [ ] **T1** Install Rector: `composer require --dev rector/rector`
- [ ] **T2** Create `rector.php` config with PHP 8.3 + Symfony sets
- [ ] **T3** Run `vendor/bin/rector --dry-run` — review output
- [ ] **T4** Apply batch 1: code quality rules + commit
- [ ] **T5** Apply batch 2: type declaration rules + commit
- [ ] **T6** Apply batch 3: early return + dead code + commit
- [ ] **T7** Run full CI validation (PHPStan + CS-Fixer + all tests)
- [ ] **T8** Remove Rector from dependencies (optional, keep config for future runs)

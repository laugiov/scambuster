# Tasks 075c: Re-Enrichment Semantic Roles (Batch Fix)

## Task 1: Write integration tests (TDD red)
**File**: `tests/Integration/UI/Console/FixSemanticRolesCommandTest.php`
- [ ] Test `test_fixes_sha256_footer_role`: SHA256 at 85% of body → role changed to IDENTITY_DOCUMENT
- [ ] Test `test_does_not_fix_sha256_in_body`: SHA256 at 30% of body → role unchanged (MALWARE_DOWNLOAD_URL)
- [ ] Test `test_dry_run_does_not_persist`: --dry-run → role still MALWARE_DOWNLOAD_URL
- [ ] Test `test_skips_non_sha256_types`: URL with MALWARE_DOWNLOAD_URL → unchanged
- [ ] Run: `make test` — new tests fail

## Task 2: Write E2E test (TDD red)
**File**: `tests/EndToEnd/CriticalFlow/SemanticRoleFixFlowTest.php`
- [ ] Test `test_fix_semantic_roles_updates_footer_sha256`: ingest + manual role set + run command + GET assert
- [ ] Run: `make endToEndTest` — new test fails

## Task 3: Implement console command
**File**: `src/UI/Console/FixSemanticRolesCommand.php`
- [ ] Create `FixSemanticRolesCommand` extending `Command`
- [ ] Configure name `app:fix:semantic-roles` with `--dry-run` option
- [ ] Inject `Connection` via constructor
- [ ] Query: join ioc_context + indicator + observed_ioc + message WHERE semantic_role = 'MALWARE_DOWNLOAD_URL' AND type = 'sha256'
- [ ] Footer heuristic: find SHA256 position in body_text, if position >= 80% body length → IDENTITY_DOCUMENT
- [ ] Batch update via DBAL (100-row batches)
- [ ] Output: total scanned, fixed, skipped
- [ ] CSV report on --dry-run

## Task 4: Integration process
- [ ] PHPStan L6+: `make stan` clean on `src/`
- [ ] CS-Fixer: `make cs-fixer` clean
- [ ] `make test` — all tests pass (4087+ tests)
- [ ] `make endToEndTest` — all tests pass (305+ tests)
- [ ] Atomic commit: `feat(075c): add app:fix:semantic-roles command for SHA256 footer role correction`

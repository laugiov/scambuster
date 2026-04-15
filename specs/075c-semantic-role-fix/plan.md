# Plan 075c: Re-Enrichment Semantic Roles (Batch Fix)

## Phase 1: Tests (1h)

### Integration tests
**File**: `tests/Integration/UI/Console/FixSemanticRolesCommandTest.php`
1. Test `test_fixes_sha256_footer_role`: Create message with SHA256 in last 20% of body + ioc_context with role MALWARE_DOWNLOAD_URL → run command → assert role changed to IDENTITY_DOCUMENT
2. Test `test_does_not_fix_sha256_in_body`: Create message with SHA256 in first 50% of body → run command → assert role unchanged
3. Test `test_dry_run_does_not_persist`: Same as test 1 but with --dry-run → assert role still MALWARE_DOWNLOAD_URL after command
4. Test `test_skips_non_sha256_types`: Create URL with role MALWARE_DOWNLOAD_URL → run command → assert role unchanged

### E2E test
**File**: `tests/EndToEnd/CriticalFlow/SemanticRoleFixFlowTest.php`
1. Test `test_fix_semantic_roles_updates_footer_sha256`: Ingest message with SHA256 in footer → manually set role to MALWARE_DOWNLOAD_URL → run command → GET ioc context → assert IDENTITY_DOCUMENT

## Phase 2: Command Implementation (1.5h)

**File**: `src/UI/Console/FixSemanticRolesCommand.php`

1. Extend `Command` with name `app:fix:semantic-roles`
2. Add `--dry-run` option (InputOption::VALUE_NONE)
3. Query logic:
   ```sql
   SELECT ic.obs_id, ic.semantic_role, ic.indicator_id,
          i.value, i.type,
          oi.msg_id, m.body_text
   FROM ioc_context ic
   JOIN indicator i ON ic.indicator_id = i.indicator_id  
   JOIN observed_ioc oi ON ic.obs_id = oi.obs_id
   JOIN message m ON oi.msg_id = m.msg_id
   WHERE ic.semantic_role = 'MALWARE_DOWNLOAD_URL'
     AND i.type = 'sha256'
   ```
4. Footer heuristic implementation:
   - Calculate message body length
   - Find position of SHA256 value in body (case-insensitive)
   - If position >= 80% of body length → footer → change to IDENTITY_DOCUMENT
   - If not found or position < 80% → skip
5. Batch update with `$conn->executeStatement()` (flush every 100)
6. Output: total scanned, total fixed, total skipped
7. CSV report to stdout when `--dry-run`

## Phase 3: Validation (0.5h)

1. PHPStan L6+ clean
2. CS-Fixer clean
3. `make test` (4087+ tests)
4. `make endToEndTest` (305+ tests)
5. Atomic commit

## Technical Approach

Use DBAL `Connection` directly (not ORM) for the query to avoid loading full entity graphs. The footer heuristic (last 20% of body) is simple and conservative — it avoids false positives on SHA256 hashes that appear inline as malware references. The command is idempotent: running it twice produces the same result.

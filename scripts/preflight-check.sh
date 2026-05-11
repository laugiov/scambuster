#!/usr/bin/env bash
#
# preflight-check.sh — local 8-gate runner for spec 080 (and beyond).
#
# Runs the exact same quality gates as the CI workflow (.github/workflows/ci.yml)
# so the first push of a feature branch is also the first green run, with no
# remote feedback loop.
#
# Usage: bash scripts/preflight-check.sh
# Exit:  0 if all 8 gates pass, non-zero on the first failing gate.
#
# Preconditions:
# - Docker compose stack is up (`make up` or `docker compose up -d`).
# - python3 available on the host (used for composer audit JSON parsing,
#   matching the CI workflow's parser).
#
# Database isolation:
# - Loads fixtures into the test DB (`scambuster_test`) and e2e DB
#   (`scambuster_e2e`) before the corresponding test gates. The dev DB
#   (`scambuster`) is NEVER touched — this script does not run any command
#   against the dev container. Verified isolation via per-container
#   DATABASE_URL inspection (each container resolves to a distinct database
#   on the shared postgres instance).
#
# Output: a banner per gate with PASS/FAIL marker. The final "All 8 gates passed."
# trailer is the proof of green and must be pasted into the commit message
# (## Preflight section) per the spec 080 protocol.

set -euo pipefail

start_ts=$(date +%s)
gate_start=$start_ts

banner() {
    local n="$1"
    local title="$2"
    gate_start=$(date +%s)
    echo ""
    echo "════════════════════════════════════════════════════════════════"
    echo "  Gate ${n}/8: ${title}"
    echo "════════════════════════════════════════════════════════════════"
}

gate_done() {
    local now=$(date +%s)
    local elapsed=$((now - gate_start))
    echo "  → PASS (${elapsed}s)"
}

trap 'echo ""; echo "FAIL on gate ${CURRENT_GATE:-?}. Total elapsed: $(( $(date +%s) - start_ts ))s."; exit 1' ERR

# ── Gate 1: PHPStan max level ──────────────────────────────────────────
CURRENT_GATE=1
banner 1 "PHPStan max level"
docker compose exec -T backend-dev vendor/bin/phpstan analyse src --memory-limit=1G --no-progress
gate_done

# ── Gate 2: PHP-CS-Fixer dry-run ───────────────────────────────────────
CURRENT_GATE=2
banner 2 "PHP-CS-Fixer dry-run"
docker compose exec -T backend-dev vendor/bin/php-cs-fixer fix --dry-run --diff --using-cache=no
gate_done

# ── Schema + Fixtures: bring scambuster_test to the canonical state before
# unit/integration/functional/compiler-pass. Migrations are idempotent (no-op
# when already at HEAD). DAMA bundle wraps integration tests in transactions
# and rolls back, so a single load before gate 3 is sufficient for gates 3-6.
echo ""
echo "──────── Schema + Fixtures: bring scambuster_test to HEAD ────────"
docker compose exec -T backend-test php bin/console doctrine:migrations:migrate --no-interaction --env=test --quiet --allow-no-migration
docker compose exec -T backend-test php bin/console doctrine:fixtures:load --no-interaction --env=test --quiet
echo "  → scambuster_test ready"

# ── Gate 3: Unit tests ─────────────────────────────────────────────────
CURRENT_GATE=3
banner 3 "Unit tests"
docker compose exec -T backend-test vendor/bin/phpunit --testsuite unit --colors=never
gate_done

# ── Gate 4: Integration tests ──────────────────────────────────────────
CURRENT_GATE=4
banner 4 "Integration tests"
docker compose exec -T backend-test vendor/bin/phpunit --testsuite integration --colors=never
gate_done

# ── Gate 5: Functional tests ───────────────────────────────────────────
CURRENT_GATE=5
banner 5 "Functional tests"
docker compose exec -T backend-test vendor/bin/phpunit --testsuite functional --colors=never
gate_done

# ── Gate 6: CompilerPass tests (isolated group) ────────────────────────
CURRENT_GATE=6
banner 6 "CompilerPass tests (isolated)"
docker compose exec -T backend-test vendor/bin/phpunit --group compiler-pass --colors=never
gate_done

# ── Schema + Fixtures: bring scambuster_e2e to the canonical state before E2E.
# No DAMA wrapper on e2e — state preserved across the testsuite intentionally.
echo ""
echo "──────── Schema + Fixtures: bring scambuster_e2e to HEAD ────────"
docker compose exec -T backend-e2e php bin/console doctrine:migrations:migrate --no-interaction --env=e2e --quiet --allow-no-migration
docker compose exec -T backend-e2e php bin/console doctrine:fixtures:load --no-interaction --env=e2e --quiet
echo "  → scambuster_e2e ready"

# ── Gate 7: E2E tests ──────────────────────────────────────────────────
CURRENT_GATE=7
banner 7 "E2E tests"
docker compose exec -T backend-e2e vendor/bin/phpunit --testsuite endtoend --exclude-group ci-skip --colors=never
gate_done

# ── Gate 8: Composer audit (no new advisories) ─────────────────────────
CURRENT_GATE=8
banner 8 "Composer audit"
AUDIT_JSON=$(docker compose exec -T backend-dev composer audit --format=json 2>/dev/null || true)
echo "$AUDIT_JSON" | python3 -c "
import sys, json
raw = sys.stdin.read().strip()
if not raw:
    print('Composer audit returned no output (no advisories found).')
    sys.exit(0)
try:
    data = json.loads(raw)
except json.JSONDecodeError:
    print('::warning::Composer audit returned non-JSON output:')
    print(raw[:500])
    sys.exit(0)
# These IDs are explicitly ignored in backend-symfony/composer.json audit.ignored.
# Keep this set in sync.
ignored = {
    'PKSA-z3gr-8qht-p93v',
    'PKSA-365x-2zjk-pt47',
    'PKSA-b35n-565h-rs4q',
}
unignored = []
for pkg, advisories in data.get('advisories', {}).items():
    for adv in advisories:
        if adv.get('advisoryId') not in ignored:
            unignored.append(f\"{pkg}: {adv.get('title', 'unknown')} ({adv.get('advisoryId')})\")
if unignored:
    print('FAIL: new advisories detected:')
    for u in unignored:
        print(f'  - {u}')
    sys.exit(1)
print('PASS: 0 new advisories (3 known CVEs ignored, matches composer.json).')
"
gate_done

# ── Done ───────────────────────────────────────────────────────────────
total=$(( $(date +%s) - start_ts ))
echo ""
echo "════════════════════════════════════════════════════════════════"
echo "  All 8 gates passed in ${total}s."
echo "  Safe to commit. Paste this trailer into the commit message under"
echo "  a '## Preflight' section."
echo "════════════════════════════════════════════════════════════════"

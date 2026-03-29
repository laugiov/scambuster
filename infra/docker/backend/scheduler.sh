#!/bin/sh
# ──────────────────────────────────────────────────────────────
# ScamBuster Scheduler — runs recurring tasks in the backend container
# Designed for Docker: lightweight loop, no cron daemon required.
# ──────────────────────────────────────────────────────────────
set -e

# Allow disabling scheduler via environment variable
if [ "${SCHEDULER_ENABLED:-true}" = "false" ]; then
    echo "[scheduler] Scheduler is DISABLED (SCHEDULER_ENABLED=false). Exiting."
    exit 0
fi

echo "[scheduler] Starting ScamBuster scheduler (PID $$)"
echo "[scheduler] Timezone: $(date +%Z) | UTC offset: $(date +%z)"
echo "[scheduler] Tasks:"
echo "  - app:close-stale-conversations  every 6h"
echo "  - app:bandit:daily-report        daily at ~06:00 UTC"
echo "  - app:cleanup:weekly             weekly (Sunday ~04:00 UTC)"
echo "  - pg_dump backup                 daily at ~02:00 UTC"

LAST_BANDIT_DAY=""
LAST_CLEANUP_WEEK=""
LAST_BACKUP_DAY=""

while true; do
    CURRENT_HOUR=$(date -u +%H)
    CURRENT_DAY=$(date -u +%Y-%m-%d)
    CURRENT_DOW=$(date -u +%u)  # 1=Monday, 7=Sunday

    # ── Every 6 hours: close stale conversations ──
    echo "[scheduler] $(date -u +%Y-%m-%dT%H:%M:%SZ) Running close-stale-conversations"
    php /app/bin/console app:close-stale-conversations --no-interaction 2>&1 || \
        echo "[scheduler] WARNING: close-stale-conversations failed"

    # Backfill rewards for any orphan closures (conversations closed without reward)
    echo "[scheduler] $(date -u +%Y-%m-%dT%H:%M:%SZ) Running calculate-rewards (backfill)"
    php /app/bin/console preprod:calculate-rewards --no-interaction 2>&1 || \
        echo "[scheduler] WARNING: calculate-rewards failed"

    # Prompt injection forensic analysis on all unanalyzed inbound messages
    echo "[scheduler] $(date -u +%Y-%m-%dT%H:%M:%SZ) Running prompt injection detection"
    php /app/bin/console app:detect-prompt-injection --no-interaction 2>&1 || \
        echo "[scheduler] WARNING: detect-prompt-injection failed"

    # Generate semantic embeddings for unprocessed messages
    echo "[scheduler] $(date -u +%Y-%m-%dT%H:%M:%SZ) Running generate-embeddings"
    php /app/bin/console app:generate-embeddings --no-interaction --limit=500 2>&1 || \
        echo "[scheduler] WARNING: generate-embeddings failed"

    # ── Daily at 06:00 UTC: bandit convergence report + actor profiles ──
    if [ "$CURRENT_HOUR" -ge 6 ] && [ "$LAST_BANDIT_DAY" != "$CURRENT_DAY" ]; then
        echo "[scheduler] $(date -u +%Y-%m-%dT%H:%M:%SZ) Running bandit:daily-report"
        php /app/bin/console app:bandit:daily-report --no-interaction 2>&1 || \
            echo "[scheduler] WARNING: bandit:daily-report failed"

        echo "[scheduler] $(date -u +%Y-%m-%dT%H:%M:%SZ) Running generate-actor-profiles"
        php /app/bin/console app:generate-actor-profiles --no-interaction 2>&1 || \
            echo "[scheduler] WARNING: generate-actor-profiles failed"

        LAST_BANDIT_DAY="$CURRENT_DAY"
    fi

    # ── Daily at 02:00 UTC: PostgreSQL backup ──
    if [ "$CURRENT_HOUR" -ge 2 ] && [ "$LAST_BACKUP_DAY" != "$CURRENT_DAY" ]; then
        BACKUP_DIR="/backups"
        BACKUP_FILE="${BACKUP_DIR}/scambuster_${CURRENT_DAY}.sql.gz"
        echo "[scheduler] $(date -u +%Y-%m-%dT%H:%M:%SZ) Running PostgreSQL backup"
        if pg_dump "${DATABASE_URL}" 2>/dev/null | gzip > "${BACKUP_FILE}"; then
            BACKUP_SIZE=$(stat -f%z "${BACKUP_FILE}" 2>/dev/null || stat -c%s "${BACKUP_FILE}" 2>/dev/null || echo "0")
            if [ "$BACKUP_SIZE" -gt 0 ]; then
                echo "[scheduler] Backup OK: ${BACKUP_FILE} (${BACKUP_SIZE} bytes)"
                # Cleanup backups older than 7 days
                find "${BACKUP_DIR}" -name "scambuster_*.sql.gz" -mtime +7 -delete 2>/dev/null || true
            else
                echo "[scheduler] WARNING: Backup file is empty, removing"
                rm -f "${BACKUP_FILE}"
            fi
        else
            echo "[scheduler] WARNING: pg_dump failed"
            rm -f "${BACKUP_FILE}"
        fi
        LAST_BACKUP_DAY="$CURRENT_DAY"
    fi

    # ── Weekly on Sunday at 04:00 UTC: cleanup ──
    if [ "$CURRENT_DOW" = "7" ] && [ "$CURRENT_HOUR" -ge 4 ] && [ "$LAST_CLEANUP_WEEK" != "$CURRENT_DAY" ]; then
        echo "[scheduler] $(date -u +%Y-%m-%dT%H:%M:%SZ) Running cleanup:weekly"
        php /app/bin/console app:cleanup:weekly --no-interaction 2>&1 || \
            echo "[scheduler] WARNING: cleanup:weekly failed"
        LAST_CLEANUP_WEEK="$CURRENT_DAY"
    fi

    # Sleep 6 hours before next cycle
    echo "[scheduler] Next cycle in 6 hours..."
    sleep 21600
done

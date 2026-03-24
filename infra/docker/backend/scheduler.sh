#!/bin/sh
# ──────────────────────────────────────────────────────────────
# ScamBuster Scheduler — runs recurring tasks in the backend container
# Designed for Docker: lightweight loop, no cron daemon required.
# ──────────────────────────────────────────────────────────────
set -e

echo "[scheduler] Starting ScamBuster scheduler (PID $$)"
echo "[scheduler] Timezone: $(date +%Z) | UTC offset: $(date +%z)"
echo "[scheduler] Tasks:"
echo "  - app:close-stale-conversations  every 6h"
echo "  - app:bandit:daily-report        daily at ~06:00 UTC"
echo "  - app:cleanup:weekly             weekly (Sunday ~04:00 UTC)"

LAST_BANDIT_DAY=""
LAST_CLEANUP_WEEK=""

while true; do
    CURRENT_HOUR=$(date -u +%H)
    CURRENT_DAY=$(date -u +%Y-%m-%d)
    CURRENT_DOW=$(date -u +%u)  # 1=Monday, 7=Sunday

    # ── Every 6 hours: close stale conversations ──
    echo "[scheduler] $(date -u +%Y-%m-%dT%H:%M:%SZ) Running close-stale-conversations"
    php /app/bin/console app:close-stale-conversations --no-interaction 2>&1 || \
        echo "[scheduler] WARNING: close-stale-conversations failed"

    # ── Daily at 06:00 UTC: bandit convergence report ──
    if [ "$CURRENT_HOUR" -ge 6 ] && [ "$LAST_BANDIT_DAY" != "$CURRENT_DAY" ]; then
        echo "[scheduler] $(date -u +%Y-%m-%dT%H:%M:%SZ) Running bandit:daily-report"
        php /app/bin/console app:bandit:daily-report --no-interaction 2>&1 || \
            echo "[scheduler] WARNING: bandit:daily-report failed"
        LAST_BANDIT_DAY="$CURRENT_DAY"
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

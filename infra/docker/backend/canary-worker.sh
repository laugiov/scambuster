#!/bin/sh
# ──────────────────────────────────────────────────────────────
# ScamBuster canary worker — drains prompt-validation jobs (GUARD).
# Each pass processes ONE pending job (a full real-LLM smoke, ~35min) then sleeps.
# Runs in its own container, isolated from the main scheduler, so a long validation
# never blocks the recurring task loop. Disable with CANARY_WORKER_ENABLED=false.
# ──────────────────────────────────────────────────────────────
set -e

if [ "${CANARY_WORKER_ENABLED:-true}" = "false" ]; then
    # A clean `exit 0` would restart-flap forever under `restart: unless-stopped`; block instead
    # so the container stays Up-but-idle (stop it with `docker compose stop canary-worker`).
    echo "[canary-worker] DISABLED (CANARY_WORKER_ENABLED=false). Idling."
    exec tail -f /dev/null
fi

# Graceful shutdown: forward SIGTERM/SIGINT to the in-flight child and exit. Installing a trap
# also makes PID 1 actually act on the signal (a default-disposition PID 1 would ignore it), so
# `docker stop` neither stalls for the grace period nor SIGKILLs a job mid-run. Children are run
# in the background and waited on, so the trap can interrupt an in-progress pass or sleep.
child=""
terminate() {
    echo "[canary-worker] received stop signal — shutting down"
    [ -n "$child" ] && kill "$child" 2>/dev/null || true
    exit 0
}
trap terminate TERM INT

echo "[canary-worker] Starting (PID $$)"

# The poll interval must be a positive integer; fall back to 60 on an empty or invalid value so
# a misconfigured CANARY_WORKER_POLL_SECONDS can never break `sleep` (which would crash-loop).
POLL_INTERVAL="${CANARY_WORKER_POLL_SECONDS:-60}"
case "$POLL_INTERVAL" in
    '' | *[!0-9]*) POLL_INTERVAL=60 ;;
esac
echo "[canary-worker] Draining scambuster:guard:canary:work every ${POLL_INTERVAL}s"

# In dev the container can start before `composer install` finishes writing the shared
# bind-mounted vendor/, so the first console command would fail. Wait for the app. In prod
# vendor is baked into the image, so this passes immediately.
while [ ! -f /app/vendor/autoload_runtime.php ]; do
    echo "[canary-worker] waiting for /app/vendor (composer install) ..."
    sleep 5 &
    child=$!
    wait "$child" || true
done

while true; do
    # One job per pass; a crash/error is logged and the loop continues (the next pass's
    # failStale sweep terminates any job stranded mid-run).
    php /app/bin/console scambuster:guard:canary:work --no-interaction 2>&1 &
    child=$!
    wait "$child" || echo "[canary-worker] WARNING: guard:canary:work failed"

    sleep "$POLL_INTERVAL" &
    child=$!
    wait "$child" || true
done

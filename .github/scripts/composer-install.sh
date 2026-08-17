#!/usr/bin/env bash
#
# Run `composer install` inside a running compose service, with a bounded retry
# and a host-side Composer cache.
#
# Why this exists rather than a bare `docker compose exec ... composer install`:
#
# Composer fetches every package as a dist archive from api.github.com or
# codeload.github.com. Unauthenticated, those endpoints are rate-limited per IP
# (HTTP 429) and shed load under pressure (HTTP 504) — and because Composer
# reports `Source fallback is disabled`, a single failed download aborts the
# whole install with exit 100. A CI run resolves the dependency tree eleven
# times (six here, plus one or two inside each image build), across jobs that
# all start in the same second, so the ceiling gets reached by our own
# concurrency rather than by bad luck. Run 747 on `main` lost four jobs to it
# simultaneously.
#
# Three mitigations, cheapest first:
#
#   COMPOSER_CACHE_DIR   a directory under the bind-mounted /app, restored by
#                        actions/cache and keyed on composer.lock. A warm run
#                        downloads nothing at all, which is the only fix that
#                        removes the requests instead of retrying them.
#   COMPOSER_AUTH        the job's GITHUB_TOKEN. Raises the GitHub API ceiling
#                        from 60 requests/hour per IP to 1000.
#   retry                three attempts with backoff, for what is left and
#                        genuinely transient.
#
# Usage: composer-install.sh <compose-service> [extra composer args...]

set -euo pipefail

SERVICE="${1:?usage: composer-install.sh <compose-service> [composer args...]}"
shift

# /app is the bind mount of ./backend-symfony (see docker-compose.yml), so a
# cache written here by the container lands on the host where actions/cache can
# save it. It must stay out of /app/var: CI chowns that tree to uid 10001, and
# actions/cache's post step runs as the runner and could not then read it.
HOST_CACHE="backend-symfony/.composer-cache"
CONTAINER_CACHE="/app/.composer-cache"

mkdir -p "$HOST_CACHE"

# An empty COMPOSER_AUTH is worse than an absent one — Composer parses it as
# JSON and complains — so only pass the variable through when it has a value.
exec_env=(-e "COMPOSER_CACHE_DIR=$CONTAINER_CACHE")
if [ -n "${COMPOSER_AUTH:-}" ]; then
  exec_env+=(-e "COMPOSER_AUTH=$COMPOSER_AUTH")
fi

attempts=3
delay=15

for attempt in $(seq 1 "$attempts"); do
  if docker compose exec --user root "${exec_env[@]}" "$SERVICE" \
      composer install --no-interaction --no-progress "$@"; then
    break
  fi

  if [ "$attempt" -ge "$attempts" ]; then
    echo "::error::composer install failed $attempts times in '$SERVICE'. This is" \
         "not a flake to re-run past: read the log for the failing host, and see" \
         "the gate-trust rule in docs/factory/README.md."
    exit 1
  fi

  echo "::warning::composer install failed in '$SERVICE' (attempt $attempt/$attempts); retrying in ${delay}s."
  sleep "$delay"
  delay=$((delay * 2))
done

# The install runs as root inside the container, so the cache lands on the host
# owned by root and actions/cache's post step — which runs as the runner user —
# cannot read it. Without this the cache silently never saves, and every run
# stays cold while looking like it is caching.
chown -R "$(id -u):$(id -g)" "$HOST_CACHE" 2>/dev/null \
  || sudo chown -R "$(id -u):$(id -g)" "$HOST_CACHE" 2>/dev/null \
  || echo "::warning::could not reclaim $HOST_CACHE for the runner; the Composer cache will not be saved."

echo "Composer cache: $(du -sh "$HOST_CACHE" 2>/dev/null | cut -f1) in $HOST_CACHE"

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
# Where the cost actually is, measured on the last green run (31988125536):
# these containerised steps take 6-12 seconds, because the image carries a warm
# Composer cache from its own build and COMPOSER_HOME sits outside /app, so the
# bind mount does not shadow it. The 2-5 minutes, and every failure, are in the
# *image build*. Read that before assuming this script is the hot path.
#
# Three mitigations:
#
#   COMPOSER_AUTH        the job's GITHUB_TOKEN. Raises the GitHub API ceiling
#                        from 60 requests/hour per IP to 1000. This is the fix
#                        that addresses the cause; it reaches the image build
#                        too, as a build arg.
#   retry                three attempts with backoff, for what is genuinely
#                        transient once the ceiling is raised.
#   COMPOSER_CACHE_DIR   a directory under the bind-mounted /app, restored by
#                        actions/cache and keyed on composer.lock. It carries
#                        the delta when composer.lock moves ahead of the cache
#                        baked into the image, and it is seeded from that image
#                        cache below so it can never be colder than doing
#                        nothing. It does NOT reach the image build — a build
#                        cannot write back to the host — which is why it is
#                        listed last rather than first.
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

# Seed the host cache from the one already inside the image, the first time.
#
# This step is not an optimisation, it is what stops the redirect above from
# being a regression. The image build ran `composer install`, so the image
# carries a warm Composer cache at COMPOSER_HOME — and COMPOSER_HOME is outside
# /app, so the bind mount does not shadow it. That is why these install steps
# take 6-12 seconds today rather than minutes: they are already warm. Pointing
# COMPOSER_CACHE_DIR at an empty host directory without seeding it would make a
# cold run re-download the whole tree — slower than doing nothing, and ~200 more
# requests aimed at exactly the endpoint that rate-limits us.
docker compose exec --user root -e SEED_DEST="$CONTAINER_CACHE" "$SERVICE" sh -c '
  mkdir -p "$SEED_DEST"
  # Only when the destination is empty: on a warm run actions/cache has already
  # restored a better copy, and re-scanning a few hundred MB per job is waste.
  if [ -z "$(ls -A "$SEED_DEST" 2>/dev/null)" ]; then
    src=$(composer config --global cache-dir 2>/dev/null) || src=""
    [ -n "$src" ] || src=/root/.composer/cache
    if [ -d "$src" ]; then cp -a "$src/." "$SEED_DEST/" 2>/dev/null || true; fi
  fi
' || echo "::warning::could not seed the Composer cache from the image; this install will be cold."

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

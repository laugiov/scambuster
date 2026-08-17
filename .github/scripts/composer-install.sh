#!/usr/bin/env bash
#
# Run `composer install` inside a running compose service, with a bounded retry.
#
# Read this before assuming it is the hot path — it is not. Measured on run
# 31988125536, these steps take 6-12 seconds, because the image already carries a
# warm Composer cache from its own build and COMPOSER_HOME sits outside /app, so
# the `./backend-symfony:/app` bind mount shadows /app/vendor but not the cache.
# All the minutes, and every dependency failure, are in the image build. The
# caching that matters is the BuildKit layer cache in the `build-backend-image`
# job; a host-side Composer cache cannot reach inside a build, and an earlier
# version of this script that redirected COMPOSER_CACHE_DIR at a host directory
# was removed for exactly that reason — it added a moving part and bought nothing.
#
# What remains here:
#
#   COMPOSER_AUTH               the job's GITHUB_TOKEN, raising the
#                               api.github.com ceiling from 60 requests/hour per
#                               IP to 1000. Measurably removed the HTTP 504s.
#   COMPOSER_MAX_PARALLEL_HTTP  passed through from the workflow (6, against
#                               Composer's default of 12).
#   retry                       three attempts, 60-89s then 120-149s, jittered
#                               from /dev/urandom. The jitter is load-bearing: a
#                               fixed backoff makes every job of a run retry in
#                               the same second, which recreates the burst that
#                               drew the 429 in the first place.
#
# None of this helps when codeload.github.com is itself refusing traffic — see
# the note in ci.yml. It is not meant to.
#
# Usage: composer-install.sh <compose-service> [extra composer args...]

set -euo pipefail

SERVICE="${1:?usage: composer-install.sh <compose-service> [composer args...]}"
shift

# An empty COMPOSER_AUTH is worse than an absent one — Composer parses it as
# JSON and complains — so only pass a variable through when it has a value.
exec_env=()
if [ -n "${COMPOSER_AUTH:-}" ]; then
  exec_env+=(-e "COMPOSER_AUTH=$COMPOSER_AUTH")
fi
if [ -n "${COMPOSER_MAX_PARALLEL_HTTP:-}" ]; then
  exec_env+=(-e "COMPOSER_MAX_PARALLEL_HTTP=$COMPOSER_MAX_PARALLEL_HTTP")
fi

attempts=3

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

  jitter=$(od -An -N1 -tu1 /dev/urandom | tr -d ' ')
  delay=$((attempt * 60 + jitter % 30))
  echo "::warning::composer install failed in '$SERVICE' (attempt $attempt/$attempts); retrying in ${delay}s."
  sleep "$delay"
done

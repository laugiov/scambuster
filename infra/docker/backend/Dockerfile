# --- Build stage: install PHP dependencies and copy source code ---
FROM php:8.3.27-cli AS build

WORKDIR /app

# Install system dependencies for Composer
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git unzip curl postgresql-client libpq-dev libzip-dev \
    && (pecl install mailparse redis \
        || (sleep 15 && pecl install -f mailparse redis) \
        || (sleep 30 && pecl install -f mailparse redis)) \
    && docker-php-ext-enable mailparse redis \
    && docker-php-ext-install pdo pdo_pgsql zip \
    && rm -rf /var/lib/apt/lists/*

# Install Composer (inside the container)
RUN curl -sS https://getcomposer.org/installer | php -- \
        --install-dir=/usr/local/bin --filename=composer

# Copy Composer files and install ALL dependencies (dev+prod) for build
#
# Composer pulls every package as a dist archive from api.github.com, which
# redirects to codeload.github.com. `Source fallback is disabled` means a single
# failed download of ~200 aborts the build with exit 100.
#
# What each lever does, measured rather than assumed (runs 747-751):
#
#   COMPOSER_AUTH_JSON          raises the api.github.com ceiling from 60/hour
#                               per IP to 1000. Removed every HTTP 504. It does
#                               nothing for codeload: Composer does not carry the
#                               Authorization header across a redirect to another
#                               host, so codeload sees an anonymous request.
#   COMPOSER_MAX_PARALLEL_HTTP  12 open connections by default; 6 here. Reduces
#                               the burst, does not change the ceiling. Set
#                               literally, NOT from the environment: a build only
#                               sees variables declared as ARG and passed with
#                               --build-arg, and this one is neither. It used to
#                               read ${COMPOSER_MAX_PARALLEL_HTTP:-6}, which was
#                               a lie with the right answer — the fallback always
#                               won, so changing the workflow env would have
#                               silently changed nothing here. Tune it by editing
#                               this line in all three Dockerfiles.
#   retry, jittered             a fixed backoff makes every job of a run retry in
#                               the same second and recreate the burst.
#   --prefer-source (attempt 3) the one that changes *which host* is asked.
#                               Composer clones from github.com instead of
#                               pulling archives from codeload, which is a
#                               different service with different limits. Slower —
#                               ~200 git clones — and that is the point: a slow
#                               build that finishes beats a fast one that does
#                               not. Run 751 exhausted all three dist attempts in
#                               482s while codeload was returning 429 even for
#                               GitHub's own action tarballs.
#
# Note the ordering: two fast dist attempts first, so the common case stays fast,
# and the slow path only on the third. `git` is installed above, which is what
# makes --prefer-source possible at all.
#
# COMPOSER_AUTH_JSON is declared and consumed in this build stage only. An ARG
# value is recorded in the history of the stage that declares it, and this stage
# is discarded by the `COPY --from=build` below, so the token never reaches the
# image that ships.
ARG COMPOSER_AUTH_JSON=""
COPY backend-symfony/composer.* ./
RUN set -eu; \
    if [ -n "$COMPOSER_AUTH_JSON" ]; then export COMPOSER_AUTH="$COMPOSER_AUTH_JSON"; fi; \
    export COMPOSER_MAX_PARALLEL_HTTP=6; \
    ok=0; \
    extra=""; \
    for attempt in 1 2 3; do \
        if composer install --no-interaction --no-scripts --no-progress $extra; then ok=1; break; fi; \
        echo "composer install failed (attempt $attempt/3)"; \
        if [ "$attempt" -eq 1 ]; then \
            jitter=$(od -An -N1 -tu1 /dev/urandom | tr -d " "); \
            sleep $((60 + jitter % 30)); \
        elif [ "$attempt" -eq 2 ]; then \
            echo "dist archives are not being served; falling back to --prefer-source (git clone from github.com, not codeload)"; \
            extra="--prefer-source"; \
            sleep 10; \
        fi; \
    done; \
    if [ "$ok" -ne 1 ]; then \
        echo "composer install failed 3 times. Not a flake to retry past — check GitHub reachability and rate limits."; \
        exit 1; \
    fi

# Copy the rest of the application code
COPY backend-symfony/ ./

# --- Runtime stage: minimal image for running the app ---
FROM php:8.3.27-cli

WORKDIR /app

# OCI labels for provenance and compliance
LABEL org.opencontainers.image.source="https://github.com/laugiov/scambuster" \
        org.opencontainers.image.description="Symfony Backend" \
        org.opencontainers.image.licenses="MIT" \
        org.opencontainers.image.sbom="true"

RUN adduser --disabled-password --gecos '' --uid 10001 appuser

# Install runtime dependencies. `apt-get upgrade` pulls base-package security patches
# (e.g. libgnutls). git is KEPT here: this dev/test image runs `composer install`
# (with dev deps) inside the container in CI.
RUN apt-get update \
    && apt-get upgrade -y \
    && apt-get install -y --no-install-recommends \
        postgresql-client curl libpq-dev git unzip libzip-dev \
    && (pecl install mailparse redis pcov \
        || (sleep 15 && pecl install -f mailparse redis pcov) \
        || (sleep 30 && pecl install -f mailparse redis pcov)) \
    && docker-php-ext-enable mailparse redis pcov \
    && curl -sS https://getcomposer.org/installer | php -- \
        --install-dir=/usr/local/bin --filename=composer \
    && docker-php-ext-install pdo pdo_pgsql zip \
    && rm -rf /var/lib/apt/lists/*

# Copy built application from the build stage
COPY --from=build /app /app

# Install only production dependencies. vendor/ arrives populated from the build
# stage so this mostly prunes, but it can still reach the network — same bounded
# retry, no token: nothing declared in this stage may end up in image history.
#
# COMPOSER_DISCARD_CHANGES is what makes the build stage's --prefer-source
# fallback survive this step. A source install leaves a git working copy in each
# vendor package; COPY --from does not reproduce it byte-for-byte, so Composer
# sees "Source directory /app/vendor/... has uncommitted changes" and refuses to
# remove a dev package it was asked to prune. Run 753 died exactly there, one
# stage after the fallback had done its job. Discarding those changes is correct
# here and nowhere else: this vendor tree is a build artifact, not a checkout
# anyone edits.
RUN set -eu; \
    export COMPOSER_DISCARD_CHANGES=true; \
    ok=0; \
    for attempt in 1 2 3; do \
        if composer install --no-dev --prefer-dist --optimize-autoloader \
            --no-interaction --no-scripts --no-progress; then ok=1; break; fi; \
        echo "composer install --no-dev failed (attempt $attempt/3)"; \
        if [ "$attempt" -lt 3 ]; then sleep $((attempt * 20)); fi; \
    done; \
    [ "$ok" -eq 1 ]

# NOT stripped here, deliberately — unlike the prod and demo images.
#
# CI runs `composer install` *inside a container from this image* (see
# .github/scripts/composer-install.sh). After a --prefer-source build, vendor/
# packages are recorded in installed.json as source installs; removing their .git
# would leave Composer with a source install it cannot verify, and its likely
# response is to reinstall — a full download at the one moment that path runs,
# which is when codeload is already refusing traffic. The image carries a little
# git history instead. Cheap insurance against making a bad day worse.

# Make the backup mount point writable by the runtime user. A freshly created
# named volume inherits this directory's ownership, so the scheduled pg_dump
# (which runs as this non-root user) can write into it out of the box.
RUN mkdir -p /backups && chown 10001:10001 /backups

USER 10001

EXPOSE 8080

# Container healthcheck (liveness probe).
# Polls the public /healthz endpoint every 30s: returns 200 OK while the web
# server is responding. The deeper DB/Redis status at /api/health is
# intentionally admin-only, so it can't be used unauthenticated here; Postgres
# and Redis have their own healthchecks in compose. Compose / Swarm / Kubernetes
# use this to mark the container `(healthy)` / `(unhealthy)` and trigger restarts.
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -fsS http://localhost:8080/healthz || exit 1

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]

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
# failed download of ~200 aborts the build with exit 100. CI builds this image in
# four jobs that start in the same second, so the request burst is our own doing
# — that is what killed run 747 on `main`.
#
# Three levers, and they do different jobs. Run 748 measured which:
#
#   COMPOSER_AUTH_JSON        raises the api.github.com ceiling from 60/hour per
#                             IP to 1000. This removed the HTTP 504s.
#   COMPOSER_MAX_PARALLEL_HTTP  Composer opens 12 connections by default. The
#                             remaining failures were HTTP 429 from
#                             *codeload*.github.com, whose per-IP abuse limit a
#                             github-oauth token does NOT lift — Composer cannot
#                             carry the Authorization header across the redirect
#                             to another host. Only a smaller burst helps there.
#   retry                     three attempts, 60-89s then 120-149s. Jittered from
#                             /dev/urandom on purpose: with a fixed backoff all
#                             four jobs retry in the same second and reproduce
#                             the burst that caused the 429.
#
# COMPOSER_AUTH_JSON is declared and consumed in this build stage only. An ARG
# value is recorded in the history of the stage that declares it, and this stage
# is discarded by the `COPY --from=build` below, so the token never reaches the
# image that ships.
ARG COMPOSER_AUTH_JSON=""
COPY backend-symfony/composer.* ./
RUN set -eu; \
    if [ -n "$COMPOSER_AUTH_JSON" ]; then export COMPOSER_AUTH="$COMPOSER_AUTH_JSON"; fi; \
    export COMPOSER_MAX_PARALLEL_HTTP="${COMPOSER_MAX_PARALLEL_HTTP:-6}"; \
    ok=0; \
    for attempt in 1 2 3; do \
        if composer install --no-interaction --no-scripts --no-progress; then ok=1; break; fi; \
        echo "composer install failed (attempt $attempt/3)"; \
        if [ "$attempt" -lt 3 ]; then \
            jitter=$(od -An -N1 -tu1 /dev/urandom | tr -d " "); \
            sleep $((attempt * 60 + jitter % 30)); \
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
RUN set -eu; \
    ok=0; \
    for attempt in 1 2 3; do \
        if composer install --no-dev --prefer-dist --optimize-autoloader \
            --no-interaction --no-scripts --no-progress; then ok=1; break; fi; \
        echo "composer install --no-dev failed (attempt $attempt/3)"; \
        if [ "$attempt" -lt 3 ]; then sleep $((attempt * 20)); fi; \
    done; \
    [ "$ok" -eq 1 ]

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

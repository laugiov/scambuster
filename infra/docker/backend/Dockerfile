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
COPY backend-symfony/composer.* ./
RUN composer install --no-interaction --no-scripts --no-progress

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

# Install only production dependencies
RUN composer install --no-dev --prefer-dist --optimize-autoloader \
    --no-interaction --no-scripts --no-progress

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

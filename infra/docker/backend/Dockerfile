# --- Build stage: install PHP dependencies and copy source code ---
FROM php:8.3-cli AS build

WORKDIR /app

# Install system dependencies for Composer
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git unzip curl postgresql-client libpq-dev libzip-dev \
    && pecl install mailparse redis \
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
FROM php:8.3-cli

WORKDIR /app

# OCI labels for provenance and compliance
LABEL org.opencontainers.image.source="https://github.com/laugiov/scambuster" \
        org.opencontainers.image.description="Symfony Backend" \
        org.opencontainers.image.licenses="MIT" \
        org.opencontainers.image.sbom="true"

RUN adduser --disabled-password --gecos '' --uid 10001 appuser

# Install runtime dependencies
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        postgresql-client curl libpq-dev git unzip libzip-dev \
    && pecl install mailparse redis \
    && docker-php-ext-enable mailparse redis \
    && curl -sS https://getcomposer.org/installer | php -- \
        --install-dir=/usr/local/bin --filename=composer \
    && docker-php-ext-install pdo pdo_pgsql zip \
    && rm -rf /var/lib/apt/lists/*

# Copy built application from the build stage
COPY --from=build /app /app

# Install only production dependencies
RUN composer install --no-dev --prefer-dist --optimize-autoloader \
    --no-interaction --no-scripts --no-progress

USER 10001

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
    
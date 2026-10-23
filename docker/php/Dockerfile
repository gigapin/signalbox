# Build the shared local PHP runtime; application source is bind-mounted by Compose.
FROM composer:2@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS composer
# PHP 8.4.27 (2026-10-21/22) fixes GHSA-w8hw-w9fx-j79w and GHSA-6wmf-4rv5-p3rp; keep the patch explicit in the tag.
FROM php:8.4.27-cli-bookworm@sha256:496b5e02fe9fe44fcfb715023b931048111dc4e0bc719789d83c906be9f04b05

# Install native libraries and the extensions required by Laravel, Horizon and Pest.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libpq-dev libicu-dev libzip-dev libonig-dev \
    && docker-php-ext-install -j"$(nproc)" bcmath pcntl pdo_pgsql intl zip mbstring \
    && rm -rf /var/lib/apt/lists/*

# Reuse the official Composer executable without adding another PHP runtime.
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY docker/php/local.ini /usr/local/etc/php/conf.d/signalbox.ini
WORKDIR /var/www/html

# Keep generated files owned by a configurable development user, never root.
ARG LOCAL_UID=1000
ARG LOCAL_GID=1000
RUN groupadd --non-unique --gid "$LOCAL_GID" signalbox \
    && useradd --uid "$LOCAL_UID" --gid signalbox --create-home signalbox
USER signalbox
# Preserve Compose environment overrides when spawning the HTTP server child.
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000", "--no-reload"]

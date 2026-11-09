# Local Docker build of this fork (upstream ships images, not a Dockerfile).

# Stage 1: build the v3 frontend (the only frontend since Firefly III 6.7.1)
FROM node:24-slim AS frontend

WORKDIR /build
COPY package.json package-lock.json ./
COPY resources/assets/v3/package.json resources/assets/v3/package.json
# Exact lockfile versions, no install scripts (npm supply-chain worms spread through them)
RUN npm ci --ignore-scripts --no-fund && npm audit signatures
COPY resources/assets/v3/ resources/assets/v3/
COPY resources/lang/ resources/lang/
RUN npm run build --workspace=resources/assets/v3

# Stage 2: PHP application
FROM fireflyiii/base:latest

ENV FIREFLY_III_PATH=/var/www/html \
    COMPOSER_ALLOW_SUPERUSER=1 \
    # .env is excluded by .dockerignore — these are build-time-only placeholders.
    # Real values are injected at container startup via docker-compose env_file.
    APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
    CACHE_DRIVER=array \
    SESSION_DRIVER=array

USER root

RUN apt-get update \
    && apt-get install -y --no-install-recommends postgresql-client \
    && rm -rf /var/lib/apt/lists/*

COPY .deploy/docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

USER www-data

COPY --chown=www-data:www-data . $FIREFLY_III_PATH

# Built frontend assets from stage 1
COPY --from=frontend --chown=www-data:www-data /build/public/build/ $FIREFLY_III_PATH/public/build/

# Create storage dirs that .dockerignore excludes — needed so config/view.php
# can resolve these paths during composer post-install artisan commands
RUN mkdir -p $FIREFLY_III_PATH/storage/framework/views \
             $FIREFLY_III_PATH/storage/framework/cache \
             $FIREFLY_III_PATH/storage/framework/sessions \
             $FIREFLY_III_PATH/storage/logs \
             $FIREFLY_III_PATH/storage/backups \
             $FIREFLY_III_PATH/storage/budget-plans

RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts \
    && composer audit --no-dev --locked \
    && php artisan package:discover --ansi \
    && php artisan view:clear \
    && php artisan cache:clear

VOLUME $FIREFLY_III_PATH/storage/upload

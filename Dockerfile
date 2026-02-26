# Stage 1: Build frontend assets
FROM node:22-slim AS frontend

WORKDIR /build
COPY package.json package-lock.json ./
COPY patches/ patches/
COPY resources/assets/ resources/assets/
RUN npm ci
RUN npm run production --workspace=resources/assets/v1
RUN npm run build --workspace=resources/assets/v2

# Stage 2: PHP application
FROM fireflyiii/base:latest

ENV FIREFLY_III_PATH=/var/www/html \
    COMPOSER_ALLOW_SUPERUSER=1

USER root

RUN apt-get update \
    && apt-get install -y --no-install-recommends postgresql-client \
    && rm -rf /var/lib/apt/lists/*

COPY .deploy/docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

USER www-data

COPY --chown=www-data:www-data . $FIREFLY_III_PATH

# Copy built frontend assets from stage 1
COPY --from=frontend --chown=www-data:www-data /build/public/v1/js/ $FIREFLY_III_PATH/public/v1/js/
COPY --from=frontend --chown=www-data:www-data /build/public/build/ $FIREFLY_III_PATH/public/build/

RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && php artisan package:discover --ansi \
    && php artisan view:clear \
    && php artisan cache:clear

VOLUME $FIREFLY_III_PATH/storage/upload

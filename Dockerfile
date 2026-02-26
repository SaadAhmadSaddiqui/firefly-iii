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

RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && php artisan package:discover --ansi \
    && php artisan view:clear \
    && php artisan cache:clear

VOLUME $FIREFLY_III_PATH/storage/upload

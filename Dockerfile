# One image for every webdav process (web, queue, scheduler, migrate), see compose.yaml.
# The default command serves HTTP on :8080 (php-fpm + nginx, from public/).

# Admin frontend: writes public/apps/webdav/
FROM node:20 AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build


FROM serversideup/php:8.3-fpm-nginx-v4.5.1 AS base

USER root
# pdo_mysql, dom and zip are already part of the base image.
RUN install-php-extensions ds xsl \
    && apt-get update \
    && apt-get install -y --no-install-recommends git \
    && rm -rf /var/lib/apt/lists/* \
    # The repositories directory is a mounted volume with a different owner.
    && git config --system --add safe.directory '*'
COPY docker/nginx/webdav.conf /etc/nginx/server-opts.d/webdav.conf
USER www-data


FROM base AS vendor
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist


FROM base AS runtime
COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=vendor /var/www/html/vendor ./vendor
COPY --chown=www-data:www-data --from=frontend /app/public/apps/webdav ./public/apps/webdav
RUN mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
       storage/framework/views storage/logs bootstrap/cache
# Runs package:discover, which writes bootstrap/cache/. No config:cache: the app reads env()
# outside config/ (GIT_PATH, APP_GIT_USER_*), which returns null once the config is cached.
RUN composer dump-autoload --optimize --no-dev --no-interaction

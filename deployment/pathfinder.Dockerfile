# syntax=docker/dockerfile:1

# ==============================================================================
# Build stage: composer install
# ==============================================================================

FROM php:8.5-fpm-alpine as build

RUN apk update && apk add --no-cache libpng-dev git $PHPIZE_DEPS
RUN docker-php-ext-install gd && docker-php-ext-install pdo_mysql
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /app

# Deps layer: only rebuilt when composer.json / composer.lock change
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/composer-cache \
  COMPOSER_CACHE_DIR=/tmp/composer-cache \
  composer install --no-dev --no-interaction --no-progress --no-autoloader

# Runtime app files only — build-only sources (js/ sass/ img/ gulpfile.js, deployment/, docs/…)
# stay out of the image. app/ is copied last (most-edited) so the autoload dump caches best.
COPY data ./data
COPY favicon ./favicon
COPY public ./public
COPY index.php ./
COPY app ./app

RUN mkdir -p logs tmp
RUN composer dump-autoload --no-dev --optimize

# ==============================================================================
# Assets stage: compile front-end (JS / CSS / images) with the gulp toolchain
# ==============================================================================

FROM node:24-bullseye-slim as assets

WORKDIR /app

# Install the node toolchain first so it caches unless package*.json change
COPY package.json package-lock.json ./
RUN --mount=type=cache,target=/root/.npm npm ci --no-audit --no-fund --prefer-offline

# Build inputs: tooling + sources (app/pathfinder.ini drives the VERSION asset folder)
COPY gulpfile.js .jshintrc ./
COPY app/pathfinder.ini ./app/pathfinder.ini
COPY js ./js
COPY sass ./sass
COPY img ./img

# produces public/{js,css,img}/<VERSION>/ (uglified + gzip + brotli + webp)
RUN npm run gulp production

# ==============================================================================
# Runtime stage: nginx + php-fpm + supervisord
# ==============================================================================

FROM trafex/php-nginx:3.11.1

# trafex/php-nginx defaults to USER nobody (rootless); this app's supervisord stack
# (php-fpm + nginx + crond) needs root, as the previous base image ran.
USER root

RUN apk update \
  && apk add --no-cache busybox-suid sudo shadow gettext bash apache2-utils logrotate ca-certificates \
  && apk add --no-cache php85-redis php85-pdo php85-pdo_mysql php85-fileinfo php85-zip

# Symlink nginx logs to stdout/stderr for supervisord
RUN ln -sf /dev/stdout /var/log/nginx/access.log && ln -sf /dev/stderr /var/log/nginx/error.log

COPY deployment/logrotate/pathfinder /etc/logrotate.d/pathfinder
COPY deployment/nginx/nginx.conf /etc/nginx/templateNginx.conf
COPY deployment/nginx/site.conf /etc/nginx/templateSite.conf
# sites_enabled is created so entrypoint.sh can drop the rendered site.conf there
RUN mkdir -p /etc/nginx/sites_enabled/

# PHP-FPM pool + php.ini overrides (php.ini is rendered by entrypoint via envsubst)
# Drop the base image's default www.conf so our pool is the only [www] (listens on :9000)
RUN rm -f /etc/php85/php-fpm.d/www.conf
COPY deployment/php/fpm-pool.conf /etc/php85/php-fpm.d/zzz_custom.conf
COPY deployment/php/php.ini /etc/zzz_custom.ini

# Cron + supervisord + entrypoint
COPY deployment/crontab.txt /var/crontab.txt
COPY deployment/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY deployment/entrypoint.sh /

WORKDIR /var/www/html
COPY --chown=nobody --from=build /app pathfinder

# Front-end assets are compiled in the assets stage (not committed to the repo)
COPY --chown=nobody --from=assets /app/public/js  pathfinder/public/js
COPY --chown=nobody --from=assets /app/public/css pathfinder/public/css
COPY --chown=nobody --from=assets /app/public/img pathfinder/public/img

# Templates rendered at runtime by entrypoint.sh (envsubst)
COPY deployment/pathfinder/routes.ini /var/www/html/pathfinder/app/
COPY deployment/pathfinder/environment.ini /var/www/html/pathfinder/app/templateEnvironment.ini
RUN cp /var/www/html/pathfinder/app/config.ini /var/www/html/pathfinder/app/templateConfig.ini

RUN chmod 0766 pathfinder/logs pathfinder/tmp/
RUN rm -f index.php
RUN touch /etc/nginx/.setup_pass
RUN chmod +x /entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

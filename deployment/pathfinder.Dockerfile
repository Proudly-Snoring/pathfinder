# syntax=docker/dockerfile:1

# ==============================================================================
# Build stage: composer install
# ==============================================================================

FROM php:7.2.34-fpm-alpine3.12 as build

RUN apk update && apk add --no-cache libpng-dev zeromq-dev git $PHPIZE_DEPS
RUN docker-php-ext-install gd && docker-php-ext-install pdo_mysql
RUN pecl install redis-5.3.7 && docker-php-ext-enable redis
RUN pecl install channel://pecl.php.net/zmq-1.1.3 && docker-php-ext-enable zmq
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer --version=2.1.8

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

FROM node:12-bullseye-slim as assets

# GraphicsMagick is required by the gulp image tasks (gulp-image-resize)
RUN apt-get update -qq \
  && apt-get install -y --no-install-recommends graphicsmagick \
  && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Install the node toolchain first so it caches unless package*.json change
# npm ci needs a lockfileVersion<=1; package-lock.json here is v3 (npm 7+), which node 12's
# npm 6 cannot `ci` -> use install (tolerant), with a cache mount to reuse downloaded tarballs
COPY package.json package-lock.json ./
RUN --mount=type=cache,target=/root/.npm npm install --no-audit --no-fund --prefer-offline

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

FROM trafex/alpine-nginx-php7:ba1dd422

RUN apk update \
  && apk add --no-cache busybox-suid sudo shadow gettext bash apache2-utils logrotate ca-certificates \
  && apk add --no-cache php7-redis php7-pdo php7-pdo_mysql php7-fileinfo php7-event php7-zip

# Replace Alpine's ancient phpredis 4.0.2 with the 5.3.7 built in the build stage.
# Both stages are alpine/musl/php7.2 (Zend API 20170718, NTS) so the .so is ABI-compatible;
# php7-redis is kept only for its conf.d ini that loads extension=redis.so.
COPY --from=build /usr/local/lib/php/extensions/no-debug-non-zts-20170718/redis.so /usr/lib/php7/modules/redis.so

# Fix expired DST Root CA X3 certificate
RUN sed -i '/^mozilla\/DST_Root_CA_X3.crt$/ s/^/!/' /etc/ca-certificates.conf && update-ca-certificates

# Symlink nginx logs to stdout/stderr for supervisord
RUN ln -sf /dev/stdout /var/log/nginx/access.log && ln -sf /dev/stderr /var/log/nginx/error.log

COPY deployment/logrotate/pathfinder /etc/logrotate.d/pathfinder
COPY deployment/nginx/nginx.conf /etc/nginx/templateNginx.conf
COPY deployment/nginx/site.conf /etc/nginx/templateSite.conf
# sites_enabled is created so entrypoint.sh can drop the rendered site.conf there
RUN mkdir -p /etc/nginx/sites_enabled/

# PHP-FPM pool + php.ini overrides (php.ini is rendered by entrypoint via envsubst)
COPY deployment/php/fpm-pool.conf /etc/php7/php-fpm.d/zzz_custom.conf
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

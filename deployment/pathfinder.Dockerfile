# syntax=docker/dockerfile:1

# ==============================================================================
# Build stage: composer install
# ==============================================================================

FROM php:7.2.34-fpm-alpine3.12 as build

RUN apk update
RUN apk add --no-cache libpng-dev zeromq-dev git $PHPIZE_DEPS
RUN docker-php-ext-install gd && docker-php-ext-install pdo_mysql
RUN pecl install redis-5.3.7 && docker-php-ext-enable redis
RUN pecl install channel://pecl.php.net/zmq-1.1.3 && docker-php-ext-enable zmq
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

COPY . /app
WORKDIR /app

RUN composer self-update 2.1.8
RUN composer install --no-dev --optimize-autoloader --no-interaction

# ==============================================================================
# Runtime stage: nginx + php-fpm + supervisord
# ==============================================================================

FROM trafex/alpine-nginx-php7:ba1dd422

RUN apk update && apk add --no-cache busybox-suid sudo shadow gettext bash apache2-utils logrotate ca-certificates
RUN apk add --no-cache php7-redis php7-pdo php7-pdo_mysql php7-fileinfo php7-event php7-zip

# Replace Alpine's ancient phpredis 4.0.2 with the 5.3.7 built in the build stage.
# Both stages are alpine/musl/php7.2 (Zend API 20170718, NTS) so the .so is ABI-compatible;
# php7-redis is kept only for its conf.d ini that loads extension=redis.so.
COPY --from=build /usr/local/lib/php/extensions/no-debug-non-zts-20170718/redis.so /usr/lib/php7/modules/redis.so

# fix expired DST Root CA X3 certificate
RUN sed -i '/^mozilla\/DST_Root_CA_X3.crt$/ s/^/!/' /etc/ca-certificates.conf && update-ca-certificates

# symlink nginx logs to stdout/stderr for supervisord
RUN ln -sf /dev/stdout /var/log/nginx/access.log && ln -sf /dev/stderr /var/log/nginx/error.log

COPY deployment/logrotate/pathfinder /etc/logrotate.d/pathfinder
COPY deployment/nginx/nginx.conf /etc/nginx/templateNginx.conf
COPY deployment/nginx/site.conf /etc/nginx/templateSite.conf
# sites_enabled is created so entrypoint.sh can drop the rendered site.conf there
RUN mkdir -p /etc/nginx/sites_enabled/

# PHP-FPM pool + php.ini overrides (php.ini is rendered by entrypoint via envsubst)
COPY deployment/php/fpm-pool.conf /etc/php7/php-fpm.d/zzz_custom.conf
COPY deployment/php/php.ini /etc/zzz_custom.ini

# cron + supervisord + entrypoint
COPY deployment/crontab.txt /var/crontab.txt
COPY deployment/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY deployment/entrypoint.sh /

WORKDIR /var/www/html
COPY --chown=nobody --from=build /app pathfinder

# templates rendered at runtime by entrypoint.sh (envsubst)
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

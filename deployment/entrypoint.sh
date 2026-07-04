#!/usr/bin/env bash
set -e
crontab /var/crontab.txt
# NOTE: nginx templates are full of literal nginx vars ($uri, $host, $http_upgrade, ...).
# The single-quoted argument restricts envsubst to ONLY that variable so the rest survive.
if [ "${ENABLE_TLS:-false}" = "true" ]; then
  envsubst '$DOMAIN $ACME_EMAIL $ACME_DIRECTORY_URL' </etc/nginx/templateSiteTls.conf >/etc/nginx/sites_enabled/site.conf
else
  envsubst '$DOMAIN' </etc/nginx/templateSite.conf >/etc/nginx/sites_enabled/site.conf
fi
envsubst '$PATHFINDER_SOCKET_HOST' </etc/nginx/templateNginx.conf >/etc/nginx/nginx.conf
# .ini templates contain no nginx-style $vars, so a bare envsubst is safe here.
envsubst </var/www/html/pathfinder/app/templateEnvironment.ini >/var/www/html/pathfinder/app/environment.ini
envsubst </var/www/html/pathfinder/app/templateConfig.ini >/var/www/html/pathfinder/app/config.ini
envsubst </etc/zzz_custom.ini >/etc/php85/conf.d/zzz_custom.ini
htpasswd -c -b -B /etc/nginx/.setup_pass pf "$APP_PASSWORD"
exec "$@"

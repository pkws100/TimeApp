#!/bin/sh
set -eu

mkdir -p \
    /var/www/html/storage/app/uploads \
    /var/www/html/storage/cache/backups \
    /var/www/html/storage/cache/exports \
    /var/www/html/storage/cache/sessions \
    /var/www/html/storage/config \
    /var/www/html/storage/logs

chown -R www-data:www-data /var/www/html/storage

exec "$@"

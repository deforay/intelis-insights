#!/usr/bin/env bash
set -e

# Ensure runtime directories exist
mkdir -p /var/www/var/cache /var/www/var/logs /var/www/corpus
chown -R www-data:www-data /var/www/var /var/www/corpus

# Start php-fpm in background
php-fpm -D

# Start nginx in foreground
exec nginx -g 'daemon off;'

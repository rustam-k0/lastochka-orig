#!/bin/sh

cd /var/www

php artisan optimize
php artisan migrate --seed --force

/usr/bin/supervisord -c /etc/supervisord.conf

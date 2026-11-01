FROM php:8.1-fpm-alpine
COPY ./_docker/php.ini /usr/local/etc/php/conf.d/php.ini
COPY --from=composer:2.7.9 /usr/bin/composer /usr/local/bin/
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions gd zip pdo_mysql imagick bz2 mbstring sockets
RUN apk add --no-cache supervisor
COPY ./_docker/supervisor.conf /etc/supervisor/conf.d/supervisor.conf
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisor.conf"]
WORKDIR /var/www

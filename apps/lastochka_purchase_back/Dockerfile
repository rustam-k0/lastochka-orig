FROM nginx

WORKDIR /var/www

EXPOSE 80

COPY . /var/www

COPY ./supervisor.conf /etc/supervisord.conf
COPY ./php.ini /etc/php/8.1/cli/php.ini
COPY ./php.ini /etc/php/8.1/fpm/php.ini
COPY ./nginx.conf /etc/nginx/nginx.conf

RUN apt update
RUN apt install -y curl supervisor git lsb-release ca-certificates apt-transport-https software-properties-common gnupg2
RUN echo "deb https://packages.sury.org/php/ $(lsb_release -sc) main" | tee /etc/apt/sources.list.d/sury-php.list
RUN curl -fsSL  https://packages.sury.org/php/apt.gpg | gpg --dearmor -o /etc/apt/trusted.gpg.d/sury-keyring.gpg
RUN apt update && apt install -y php8.1 php8.1-common php8.1-cli php8.1-fpm php8.1-mysql php8.1-curl php8.1-gd php8.1-bz2 php8.1-mbstring php8.1-zip php8.1-xml php8.1-imagick  php8.1-sockets
RUN curl -sS https://getcomposer.org/installer -o composer-setup.php
RUN php composer-setup.php --install-dir=/usr/local/bin --filename=composer

RUN composer install --optimize-autoloader --no-dev
RUN php artisan key:generate --ansi

RUN chown -R www-data:www-data ./public
RUN chown -R www-data:www-data ./bootstrap
RUN chown -R www-data:www-data ./resources
RUN chown -R www-data:www-data ./storage
RUN chown www-data:www-data ./
RUN chmod -R +x /var/www/run.sh
RUN mkdir -p /run/php

ENTRYPOINT ["/var/www/run.sh"]

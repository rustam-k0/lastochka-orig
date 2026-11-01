FROM php:8.3-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    linux-headers \
    curl \
    libpng-dev \
    libxml2-dev \
    zip \
    unzip \
    supervisor \
    libzip-dev \
    postgresql-dev \
    oniguruma-dev \
    git

# Install PHP extensions
RUN docker-php-ext-install \
    pdo_mysql \
    pdo_pgsql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    zip \
    opcache \
    sockets

# Install Redis extension
RUN apk add --no-cache pcre-dev $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del pcre-dev $PHPIZE_DEPS

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy supervisor config
COPY .docker/php/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Create directory for supervisor logs
RUN mkdir -p /var/log/supervisor

# Expose port 9000
EXPOSE 9000

# Start supervisor
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

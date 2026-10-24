# Frontend - Node.js
FROM node:22-alpine AS frontend

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY . .
RUN npm run build


# Backend - PHP 8.5
FROM php:8.5.12-fpm-alpine AS app

ENV COMPOSER_ALLOW_SUPERUSER=1

# Dependencias y extensiones PHP
RUN apk add --no-cache \
        nginx \
        supervisor \
        libzip-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        icu-dev \
        oniguruma-dev \
    && docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        gd \
        intl \
        zip \
        bcmath \
        exif \
        pcntl \
        opcache

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Código de Laravel
COPY . .

# Assets compilados
COPY --from=frontend /app/public/build ./public/build

# Dependencias PHP
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader

# Configuración
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/php/php.ini "$PHP_INI_DIR/conf.d/zz-production.ini"

# Permisos
RUN mkdir -p storage bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 80

CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisord.conf"]

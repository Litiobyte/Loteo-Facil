# ==========================================
# FRONTEND - Node.js 22 + Vite
# ==========================================
FROM node:22-alpine AS frontend

WORKDIR /app

COPY package.json package-lock.json ./

RUN npm ci

COPY . .

RUN npm run build


# ==========================================
# BACKEND - PHP 8.5.12 + Laravel
# ==========================================
FROM php:8.5.12-fpm-alpine AS app

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    APP_ENV=production

WORKDIR /var/www

# Dependencias del sistema y extensiones PHP
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
        pcntl

# OPcache ya viene incluido en PHP 8.5
# No instalar mediante docker-php-ext-install

# Composer 2
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Código de Laravel
COPY . .

# Assets compilados por Vite
COPY --from=frontend /app/public/build ./public/build

# Instalar dependencias PHP de producción
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader \
    && composer check-platform-reqs --no-dev

# ==========================================
# CONFIGURACIÓN
# ==========================================

# Nginx
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf

# Supervisor
COPY docker/supervisord.conf /etc/supervisord.conf

# PHP
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-production.ini

# ==========================================
# PERMISOS LARAVEL
# ==========================================
RUN mkdir -p \
        storage/app \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data \
        storage \
        bootstrap/cache \
    && chmod -R 775 \
        storage \
        bootstrap/cache

# ==========================================
# PRODUCCIÓN
# ==========================================
EXPOSE 80

CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisord.conf"]

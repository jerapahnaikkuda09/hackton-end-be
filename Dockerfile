# ── Stage 1: Build frontend assets ──
FROM node:22-alpine AS frontend
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm ci
COPY vite.config.js ./
COPY resources/ resources/
RUN npm run build

# ── Stage 2: Install PHP dependencies ──
FROM composer:2 AS composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist
COPY . .
RUN composer dump-autoload --optimize

# ── Stage 3: Production image ──
FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
        nginx supervisor curl \
        libpng-dev libzip-dev oniguruma-dev icu-dev \
        libpq-dev \
    && docker-php-ext-install pdo_pgsql mbstring zip bcmath intl opcache \
    && rm -rf /var/cache/apk/*

# OPcache production tuning
RUN { \
      echo "opcache.enable=1"; \
      echo "opcache.memory_consumption=128"; \
      echo "opcache.interned_strings_buffer=8"; \
      echo "opcache.max_accelerated_files=10000"; \
      echo "opcache.validate_timestamps=0"; \
    } > /usr/local/etc/php/conf.d/opcache.ini

COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf

WORKDIR /var/www/html

# Copy PHP app with vendor
COPY --from=composer /app /var/www/html

# Copy built Vite assets
COPY --from=frontend /app/public/build /var/www/html/public/build

# Prepare storage & cache dirs
RUN mkdir -p storage/framework/{sessions,views,cache} \
        storage/logs bootstrap/cache \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 80

ENTRYPOINT ["sh", "/var/www/html/docker/entrypoint.sh"]

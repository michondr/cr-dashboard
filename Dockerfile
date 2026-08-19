# syntax=docker/dockerfile:1

# Stage 1 — build: resolve PHP dependencies with composer.
FROM composer:2 AS build
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative

# Stage 2 — runtime: nginx + php-fpm + cron under supervisor.
FROM php:8.5-fpm-alpine
WORKDIR /app

RUN apk add --no-cache \
        curl \
        curl-dev \
        dcron \
        nginx \
        openssl \
        supervisor \
    && docker-php-ext-install -j"$(nproc)" sqlite3 pdo_sqlite curl

COPY --from=build /app /app
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/crontab /etc/crontab
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p /var/lib/cr-dashboard /var/log/cr-dashboard

VOLUME /var/lib/cr-dashboard
EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

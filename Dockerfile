# syntax=docker/dockerfile:1

# Stage 1 — build: resolve PHP dependencies with composer.
FROM composer:2 AS build
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
# Production bakes a classmap-authoritative autoloader so a missing class fails
# fast at build time. Local dev (docker-compose.dev.yml) overrides this ARG to
# an empty value, keeping the PSR-4 fallback so classes added to the bind-mounted
# source resolve without rebuilding the image.
ARG CLASSMAP_AUTHORITATIVE=--classmap-authoritative
RUN composer dump-autoload --no-dev $CLASSMAP_AUTHORITATIVE

# Stage 2 — runtime: nginx + php-fpm + cron under supervisor.
FROM php:8.5-fpm-alpine
WORKDIR /app

# sqlite3, pdo_sqlite, curl, openssl and mbstring are already compiled into the
# official php image (--with-sqlite3=/usr --with-curl --with-openssl ...), so no
# docker-php-ext-install is needed. Only the runtime packages are installed here.
RUN apk add --no-cache \
        curl \
        dcron \
        nginx \
        openssl \
        su-exec \
        supervisor

# Mercure hub (a single static Caddy-based binary) for server->browser push.
# Runs as its own supervisord program (docker/supervisord.conf), fronted by
# nginx at /.well-known/mercure so no extra port is exposed.
ARG MERCURE_VERSION=v0.15.9
# The release asset name does not embed the version (mercure_Linux_x86_64.tar.gz),
# only the tag path does.
RUN curl -fsSL "https://github.com/dunglas/mercure/releases/download/${MERCURE_VERSION}/mercure_Linux_x86_64.tar.gz" \
        -o /tmp/mercure.tar.gz \
    && tar -xzf /tmp/mercure.tar.gz -C /usr/local/bin mercure \
    && chmod +x /usr/local/bin/mercure \
    && rm /tmp/mercure.tar.gz

COPY --from=build /app /app
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/crontab /etc/crontab
COPY docker/mercure.Caddyfile /etc/mercure/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p /var/lib/cr-dashboard /var/log/cr-dashboard

VOLUME /var/lib/cr-dashboard
EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

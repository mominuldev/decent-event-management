# Production image for Phase 9 (docs/08 §Phase 9). No hosting provider is
# named anywhere in docs/07's infrastructure section (§7.3 specs sizing and
# an autoscaling app tier, not a vendor) — this image is deliberately
# provider-agnostic: it runs identically on ECS, Cloud Run, a bare VM with
# `docker compose`, or any other container host, so picking a provider later
# doesn't require touching this file. Which provider to run it on is an
# External Dependency (CLAUDE.md) exactly like the unpicked SMS vendor —
# flagged, not guessed at.
#
# NOT BUILD-TESTED: this development environment has no Docker daemon
# (`which docker` finds nothing), so `docker build` has never actually been
# run against this file. Every extension and package choice below matches
# what this checkout's own `php -m` already reports loaded (sodium, gd,
# bcmath, zip, intl, pdo_mysql, mbstring, opcache) rather than being
# guessed, but the build itself needs a first real run — matching how
# SslCommerzClient shipped unverified against a live sandbox call, and for
# the same reason: no way to provision the missing piece from here.

# ---- Stage 1: PHP dependencies -------------------------------------------
FROM composer:2 AS composer-deps
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --no-interaction \
        --prefer-dist
COPY . .
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative

# ---- Stage 2: frontend assets ---------------------------------------------
FROM node:22-alpine AS frontend-build
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
# The SPA build reads asset paths but needs no backend/DB access.
RUN npm run build

# ---- Stage 3: runtime -------------------------------------------------
# One image serves three roles depending on the CMD an orchestrator
# supplies (see docker-compose.prod.yml): the default CMD below runs
# nginx + php-fpm together via supervisord for the web-facing `app` role;
# `horizon`/`scheduler` services override CMD to a plain `php artisan ...`
# and never touch nginx/supervisord at all. Bundling nginx into the same
# image (rather than a second container sharing a volume) sidesteps a real
# Docker/Laravel footgun: nginx's fastcgi_pass hands PHP-FPM a bare
# filesystem path, so the two processes must see identical files at
# identical paths — trivial in one container, easy to get subtly wrong
# across two containers synchronized only by a volume that something has
# to remember to populate.
FROM php:8.3-fpm-bookworm AS runtime

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libwebp-dev \
        libzip-dev \
        libicu-dev \
        libsodium-dev \
        libonig-dev \
        nginx \
        supervisor \
        unzip \
    && docker-php-ext-configure gd --with-jpeg --with-freetype --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        gd \
        bcmath \
        zip \
        intl \
        sodium \
        opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

# Cheap, standard opcache defaults for a request-response PHP-FPM app — not
# tuned against real production traffic, since nothing here can generate any.
COPY docker/php/opcache-production.ini /usr/local/etc/php/conf.d/opcache-production.ini
COPY docker/nginx.conf /etc/nginx/sites-enabled/default
COPY docker/supervisord.conf /etc/supervisor/supervisord.conf

WORKDIR /var/www/html

COPY --from=composer-deps /app /var/www/html
COPY --from=frontend-build /app/public/build /var/www/html/public/build

# Laravel needs to write here at runtime; nginx/php-fpm's own runtime dirs
# also need www-data. Everything else in the image is read-only from the
# app's own perspective.
RUN mkdir -p storage/framework/{cache,sessions,testing,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && mkdir -p /var/lib/nginx /var/log/nginx /var/log/supervisor \
    && chown -R www-data:www-data /var/lib/nginx /var/log/nginx

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/supervisord.conf"]

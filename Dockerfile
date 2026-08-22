# Production image for Phase 9 (docs/08 §Phase 9). No hosting provider is
# named anywhere in docs/07's infrastructure section (§7.3 specs sizing and
# an autoscaling app tier, not a vendor) — this image is deliberately
# provider-agnostic: it runs identically on ECS, Cloud Run, a bare VM with
# `docker compose`, or any other container host, so picking a provider later
# doesn't require touching this file. Which provider to run it on is an
# External Dependency (CLAUDE.md) exactly like the unpicked SMS vendor —
# flagged, not guessed at.
#
# STILL NOT BUILD-TESTED LOCALLY: this development environment has no Docker
# daemon (`which docker` finds nothing), so every change here is verified by
# pushing and reading the CI build, not by running one. The first real build
# happened in GitHub Actions and failed at the dependency stage; the fix is
# recorded on stage 1 below. Read a green `build-and-push` run as the only
# evidence this file works — matching how SslCommerzClient stayed unverified
# until a live sandbox call, and for the same reason: no way to provision
# the missing piece from here.

# ---- Stage 1: the PHP platform, shared ------------------------------------
# Both the dependency stage and the runtime stage build FROM this, and that
# sharing is the point rather than a tidiness win: `composer install` checks
# every package's `ext-*` requirement against the extensions actually loaded
# in the image running it, so resolving dependencies on a *different* PHP
# than production runs is resolving against the wrong platform.
#
# That is not hypothetical — it is what broke the first real build of this
# file. The dependency stage used the `composer:2` image, whose Alpine PHP
# ships neither `gd` (phpoffice/phpspreadsheet requires it, for the attendee
# export's embedded photos) nor `pcntl` (laravel/horizon requires it), so
# composer exited 2 on unsatisfiable platform requirements before a single
# package was fetched.
FROM php:8.3-fpm-bookworm AS php-base

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
        # Ticket and directory PDFs are rendered by headless Chrome rather
        # than a PHP library — see config/pdf.php. This is a correctness
        # dependency, not a convenience: mpdf dropped Bengali conjuncts from
        # the extractable text layer entirely, and roughly half of the names
        # on this roster contain one.
        chromium \
        # Chromium ships no fonts. The faces the documents actually use are
        # bundled in resources/fonts and loaded via @font-face, so this set
        # is only a sane fallback for anything a template does not pin.
        fonts-liberation \
        fonts-noto-core \
    && docker-php-ext-configure gd --with-jpeg --with-freetype --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        gd \
        bcmath \
        zip \
        intl \
        sodium \
        opcache \
        # laravel/horizon requires ext-pcntl (and ext-posix, which the
        # official image already enables). It was missing here, so the image
        # would have shipped a Horizon that cannot run — the `horizon` and
        # `scheduler` services in docker-compose.prod.yml are this same
        # image with a different CMD, and neither is exercised by the
        # build's boot check. Composer now refuses the build outright if
        # this regresses, which is the reason to resolve on this platform.
        pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

# ---- Stage 2: PHP dependencies -------------------------------------------
FROM php-base AS composer-deps
# The binary only; the Alpine PHP underneath it is exactly what we are
# avoiding. Composer runs as root here, which is correct in a container and
# only ever produces a warning.
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
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

# ---- Stage 3: frontend assets ---------------------------------------------
FROM node:22-alpine AS frontend-build
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
# The SPA build reads asset paths but needs no backend/DB access.
RUN npm run build

# ---- Stage 4: runtime -----------------------------------------------------
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
FROM php-base AS runtime

# Cheap, standard opcache defaults for a request-response PHP-FPM app — not
# tuned against real production traffic, since nothing here can generate any.
# Pinned rather than left to auto-detection: config/pdf.php probes a list of
# well-known paths, and naming it here means a base-image change that moves
# or renames the binary fails the build's own boot check instead of surfacing
# as a failed ticket render in production.
ENV CHROME_BINARY=/usr/bin/chromium

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

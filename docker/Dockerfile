# ecommerce-core runtime image.
#
# Targets:
#   dev  — developer container; the source tree is bind-mounted by compose.yaml (INF-002),
#          nothing is copied in, so edits are picked up immediately.
#   prod — self-contained immutable image: code + --no-dev vendor, non-root, port 8080,
#          TLS terminated by the edge proxy (see compose.prod.yaml).
#
# Build: docker build --target prod -t ghcr.io/<owner>/e-commerce:local .
# FrankenPHP v1.13.2 + PHP 8.5.12 (bookworm), pinned by digest so builds resolve the
# already-pulled image without a Docker Hub metadata lookup. Equivalent tags:
#   dunglas/frankenphp:1.13.2-php8.5-bookworm
#   dunglas/frankenphp:1-php8.5-bookworm
# Mirror fallback when Docker Hub is unreachable:
#   mirror.gcr.io/dunglas/frankenphp:1.13.2-php8.5-bookworm
FROM dunglas/frankenphp@sha256:308b7dbf7e263a7200f17c1957c0ed1dfdcd01fd914e1b7e99eef7f92b06d4ae AS base

ARG INSTALL_XDEBUG=false

RUN install-php-extensions \
        intl \
        opcache \
        pdo_pgsql \
        zip \
    && if [ "$INSTALL_XDEBUG" = "true" ]; then install-php-extensions xdebug; fi

COPY --from=composer/composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY docker/Caddyfile /etc/caddy/Caddyfile

ENV SERVER_NAME=:80

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]

FROM base AS prod

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    SERVER_NAME=:8080 \
    COMPOSER_ALLOW_SUPERUSER=1

COPY docker/php.ini /usr/local/etc/php/conf.d/99-app.ini

# Dependency layer first so source edits do not invalidate the composer cache.
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --no-scripts \
        --no-autoloader

COPY . .

RUN composer dump-autoload --no-dev --classmap-authoritative \
    && composer run-script auto-scripts \
    && mkdir -p var/cache var/log var/share public/uploads \
    && chown -R www-data:www-data var public/uploads /config /data

USER www-data

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8080/healthz || exit 1

FROM base AS dev

# bin/harness-check is bash + python3/PyYAML; running it in this container keeps INF-002 intact
# (the CI runner installs the same package explicitly before calling it).
RUN apt-get update \
    && apt-get install -y --no-install-recommends python3 python3-yaml \
    && rm -rf /var/lib/apt/lists/*

FROM dunglas/frankenphp:1-php8.4-bookworm

RUN install-php-extensions \
    intl \
    opcache \
    pdo_pgsql \
    zip

COPY --from=composer/composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY docker/Caddyfile /etc/caddy/Caddyfile

ENV SERVER_NAME=:80

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]

# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# base: FrankenPHP (Caddy + PHP 8.3 embedded, worker mode capable) + extensions
# ---------------------------------------------------------------------------
FROM dunglas/frankenphp:1-php8.3 AS base

WORKDIR /app

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

RUN install-php-extensions @composer apcu intl opcache pdo_pgsql redis zip

COPY docker/frankenphp/Caddyfile /etc/caddy/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint

ENTRYPOINT ["app-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]

# ---------------------------------------------------------------------------
# dev: sources are bind-mounted by docker-compose, pcov for coverage
# ---------------------------------------------------------------------------
FROM base AS dev

RUN install-php-extensions pcov \
    && cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

COPY docker/php/app.ini "$PHP_INI_DIR/conf.d/zz-app.ini"

ENV APP_ENV=dev

# ---------------------------------------------------------------------------
# prod-deps: resolve production dependencies in an isolated layer
# ---------------------------------------------------------------------------
FROM base AS prod-deps

COPY composer.json composer.lock symfony.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress --no-interaction

# ---------------------------------------------------------------------------
# prod: immutable image, worker mode, optimised autoloader, warmed cache
# ---------------------------------------------------------------------------
FROM base AS prod

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/app.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
COPY docker/php/app.prod.ini "$PHP_INI_DIR/conf.d/zz-app-prod.ini"

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    APP_RUNTIME="Runtime\\FrankenPhpSymfony\\Runtime" \
    FRANKENPHP_CONFIG="worker ./public/index.php"

COPY --from=prod-deps /app/vendor /app/vendor
COPY . /app

RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
    && mkdir -p var/cache var/log \
    && APP_SECRET=build JWT_SECRET=build API_KEY_PEPPER=build \
       DATABASE_URL="postgresql://build:build@localhost:5432/build?serverVersion=16&charset=utf8" \
       REDIS_URL="redis://localhost:6379" \
       php bin/console cache:warmup --env=prod --no-debug \
    && chown -R www-data:www-data var 2>/dev/null || true

HEALTHCHECK --interval=15s --timeout=3s --start-period=20s --retries=3 \
    CMD curl -fsS http://localhost/api/v1/health || exit 1

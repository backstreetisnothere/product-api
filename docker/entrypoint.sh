#!/bin/sh
set -e

# Only the web process bootstraps the application. One-off commands
# (php bin/console ..., composer ..., phpunit ...) are executed as is.
if [ "$1" = 'frankenphp' ]; then
    if [ ! -f vendor/autoload_runtime.php ]; then
        composer install --prefer-dist --no-progress --no-interaction
    fi

    if [ "${RUN_MIGRATIONS:-0}" = '1' ]; then
        echo 'Waiting for the database...'
        until php bin/console dbal:run-sql 'SELECT 1' >/dev/null 2>&1; do
            sleep 1
        done
        php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
    fi
fi

exec docker-php-entrypoint "$@"

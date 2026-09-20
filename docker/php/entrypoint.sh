#!/bin/sh

set -eu

if [ ! -f composer.json ]; then
    echo "Application composer.json is missing from /var/www/application." >&2
    exit 1
fi

composer install --no-interaction --prefer-dist

if [ ! -f .env ]; then
    cp .env.example .env
fi

if ! grep -Eq '^APP_KEY=base64:.+' .env; then
    php artisan key:generate --force --no-interaction
fi

chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache

attempt=1
while ! php -r '
    new PDO(
        sprintf("mysql:host=%s;port=%s;dbname=%s", getenv("DB_HOST"), getenv("DB_PORT"), getenv("DB_DATABASE")),
        getenv("DB_USERNAME"),
        getenv("DB_PASSWORD"),
    );
' >/dev/null 2>&1; do
    if [ "$attempt" -ge 30 ]; then
        echo "MySQL did not become reachable after 30 attempts." >&2
        exit 1
    fi

    echo "Waiting for MySQL ($attempt/30)..."
    attempt=$((attempt + 1))
    sleep 2
done

exec "$@"

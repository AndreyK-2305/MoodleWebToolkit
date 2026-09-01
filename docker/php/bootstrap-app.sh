#!/bin/sh

set -eu

cd /var/www/html

if [ ! -f .env ]; then
    cp .env.example .env
fi

if ! grep -Eq '^APP_KEY=.+$' .env; then
    php artisan key:generate --force --no-interaction
fi

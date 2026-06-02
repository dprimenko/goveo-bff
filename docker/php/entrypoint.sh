#!/bin/sh
set -e

# Install/update dependencies if vendor is missing
if [ ! -f vendor/autoload.php ]; then
    echo "Installing Composer dependencies..."
    composer install --no-interaction --no-scripts --optimize-autoloader
    php bin/console cache:clear --no-warmup || true
fi

# Create var directories with correct permissions
mkdir -p var/cache var/log
chown -R www-data:www-data var

exec docker-php-entrypoint "$@" -F

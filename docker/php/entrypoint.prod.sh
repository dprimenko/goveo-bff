#!/bin/sh
# Arranque del BFF en producción.
#
# Las migraciones se aplican aquí y no a mano: un despliegue que cambia el
# esquema y no lo migra deja la API sirviendo 500 hasta que alguien se acuerde.
# `--allow-no-migration` para que un despliegue sin cambios de esquema no falle.
set -e

mkdir -p var/cache var/log
chown -R www-data:www-data var

echo "→ Aplicando migraciones…"
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

echo "→ Calentando caché…"
php bin/console cache:clear --no-debug
php bin/console cache:warmup --no-debug
chown -R www-data:www-data var

echo "→ PHP-FPM listo"
exec docker-php-entrypoint "$@" -F

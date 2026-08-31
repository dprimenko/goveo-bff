#!/bin/sh
# Arranque del BFF en producción.
#
# Las migraciones se aplican aquí y no a mano: un despliegue que cambia el
# esquema y no lo migra deja la API sirviendo 500 hasta que alguien se acuerde.
# `--allow-no-migration` para que un despliegue sin cambios de esquema no falle.
set -e

mkdir -p var/cache var/log
chown -R www-data:www-data var

# El `depends_on` del compose espera a que Postgres esté «sano», pero en el
# primer arranque de un volumen nuevo eso no garantiza que ya acepte conexiones.
# Sin esta espera, el `set -e` mata el contenedor y `restart: unless-stopped` lo
# convierte en un bucle de reinicios difícil de leer en los logs.
echo "→ Esperando a la base de datos…"
tries=0
until php bin/console dbal:run-sql 'select 1' >/dev/null 2>&1; do
    tries=$((tries + 1))
    if [ "$tries" -ge 30 ]; then
        echo "✗ La base no responde tras 60s. Revisa DATABASE_URL." >&2
        exit 1
    fi
    sleep 2
done
echo "→ Base disponible"

echo "→ Aplicando migraciones…"
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

echo "→ Calentando caché…"
php bin/console cache:clear --no-debug
php bin/console cache:warmup --no-debug
chown -R www-data:www-data var

echo "→ PHP-FPM listo"
exec docker-php-entrypoint "$@" -F

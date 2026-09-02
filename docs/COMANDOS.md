# Comandos del BFF

`php` no está en el host: todo se ejecuta dentro del contenedor.

```bash
docker exec goveo-bff-php-1 sh -lc 'php bin/console <comando>'
```

Casi todos aceptan `--dry-run`. **Úsalo siempre la primera vez**: varios de estos
comandos escriben en Stripe o borran datos, y ninguno pregunta.

## Credenciales de Firebase en producción

Los comandos `goveo:migrate:firestore:*` leen Firestore, y esa credencial **no vive en
el servidor**: el despliegue no monta nada de `config/` y es una clave con acceso a todo
el proyecto. Se sube justo antes de importar y se borra después.

```bash
# 1 · qué contenedor es (Dokploy le cambia el sufijo en cada redespliegue)
ssh root@76.13.63.176 'docker ps --format "{{.Names}}\t{{.Image}}" | grep -i php'

# 2 · subir al servidor y meterla en el contenedor, en /tmp
scp config/firebase-credentials.json root@76.13.63.176:/tmp/fb.json
ssh root@76.13.63.176 'docker cp /tmp/fb.json <contenedor-php>:/tmp/fb.json'

# 3 · importar, apuntando FIREBASE_CREDENTIALS a esa ruta
ssh root@76.13.63.176 "docker exec -e FIREBASE_CREDENTIALS=/tmp/fb.json <contenedor-php> \
    sh -lc 'php bin/console goveo:migrate:firestore:business-managers --dry-run'"

# 4 · BORRARLA de los dos sitios. No es opcional.
ssh root@76.13.63.176 'docker exec <contenedor-php> rm -f /tmp/fb.json; rm -f /tmp/fb.json'
ssh root@76.13.63.176 'docker exec <contenedor-php> ls /tmp/fb.json; ls /tmp/fb.json'   # ambos: No such file
```

`/tmp` dentro del contenedor y no `config/`: lo que hay en `/tmp` desaparece al
redesplegar aunque alguien olvide el paso 4, y no acaba en ninguna copia de la imagen.

⚠️ **El paso 4 se hace aunque la importación falle.** Es cuando más fácil es olvidarlo, y
la clave se queda en un servidor al que entra más gente.

---

> Para levantar un entorno nuevo de cero, la secuencia completa y comprobada
> está en [MIGRACION.md](MIGRACION.md).

## Facturación y tarifas

| Comando | Qué hace |
|---|---|
| `goveo:billing:seed-plans-2026` | Crea las tarifas TOP 3 · PLATINUM · PREMIUM · FREE (mensual, semestral y anual). Con `--retire-legacy` desactiva las 58 heredadas. Idempotente. |
| `goveo:billing:assign-free-plan` | Pone en FREE a los negocios que no tienen suscripción. Idempotente. |
| `goveo:stripe:sync` | Crea en Stripe los Products, Prices y Coupons que falten para lo que esté activo. Idempotente. |
| `goveo:migrate:billing-plan-stripe-ids` | Recupera `billing_plans.stripe_price_id` desde los ids de Firestore. |

### ⚠️ Orden obligatorio

```bash
goveo:billing:seed-plans-2026 --retire-legacy   # 1 · crea las tarifas
goveo:stripe:sync                               # 2 · las lleva a Stripe
goveo:billing:assign-free-plan                  # 3 · pone a todos en FREE
```

`seed` antes que `sync` porque el sync sólo sube lo que ya existe en la base. Y
dentro del sync, los Products van antes que los Prices: un Price necesita su
producto, así que una familia nueva sin producto en Stripe deja sus planes sin
crear (y el aviso es poco evidente).

### ⚠️ Los ids de Stripe no son portables entre entornos

`stripe_price_id` y `stripe_product_id` pertenecen **a la cuenta donde se
crearon**. Con la clave de test apuntando a una base con datos de producción,
crear lo que falta sustituiría los ids buenos por otros de test.

`goveo:stripe:sync` lo detecta y **omite** esos casos; forzarlo requiere
`--overwrite-ids`. Si algún día hace falta trabajar los dos entornos sobre la
misma base, la solución es una columna por entorno, no el flag.

---

## Negocios y usuarios

| Comando | Qué hace |
|---|---|
| `goveo:business:verify` | Sin argumentos lista los pendientes; con un slug o id, valida. `--revoke` retira la validación, `--all` valida todos. |
| `goveo:business:purge-abandoned` | Borra altas web que nunca se pagaron y sus usuarios huérfanos. **No borra sin `--force`**; `--days` fija la antigüedad mínima (7 por defecto). |
| `goveo:account:resend-welcome` | Sin argumentos lista a quién no puede entrar; con un slug o id, reenvía el correo de bienvenida. `--all` a todos los pendientes. |

El listado de `resend-welcome` distingue dos motivos, que son problemas distintos:
**sin entregar** (el correo nunca salió) y **sin contraseña** (salió, pero el dueño
nunca la creó). El segundo es el agujero real del embudo: el negocio está pagado
y su dueño no puede entrar.

Cada reenvío emite un token nuevo y **borra los anteriores**, así que siempre vale
el último correo. Los 448 negocios importados quedan fuera del listado: llegaron
de Firebase con su contraseña y nunca pasaron por este flujo.

`business.verified_at` es lo único que decide si un negocio se publica: con fecha
sale en feed, mapa y búsqueda; sin ella sólo lo ve su dueño, como pendiente.

Un alta abandonada se reconoce sin ambigüedad: suscripción en `pending_payment`,
sin `stripe_subscription_id` y con antigüedad. El usuario sólo se elimina si ese
negocio era el único que gestionaba, y en Keycloak se **deshabilita**, no se
borra, para no perder el rastro.

---

## Migraciones de datos heredados

Traen datos del sistema antiguo. **Ya se ejecutaron**; están aquí para poder
repetirlas sobre una base limpia. Todas aceptan `--dry-run`.

Los ids son **UUID v5 derivados del id de origen**, no aleatorios: por eso
repetir un import no duplica nada y se puede reanudar a medias.

### Todo de una vez

```bash
goveo:migrate:all               # Supabase en orden y luego Firebase
goveo:migrate:all --dry-run
goveo:migrate:all --skip-firebase
goveo:migrate:all --skip-supabase
```

### Supabase, en orden de dependencias

El orden **importa**: una tabla que referencia a otra falla si la otra no está.
`goveo:migrate:all` lo respeta; lanzándolas sueltas hay que seguir este orden.

| # | Comando | Trae |
|---|---|---|
| 1 | `goveo:migrate:supabase:category-types` | Tipos de categoría |
| 2 | `goveo:migrate:supabase:categories` | Categorías |
| 3 | `goveo:migrate:supabase:category-types-mapping` | Relación entre ambas |
| 4 | `goveo:migrate:supabase:default-subcategories` | Subcategorías plantilla del sistema |
| 5 | `goveo:migrate:supabase:partners` | Partners |
| 6 | `goveo:migrate:supabase:partner-zipcodes` | Códigos postales de cada partner |
| 7 | `goveo:migrate:supabase:users` | Usuarios |
| 8 | `goveo:migrate:supabase:influencers` | Creadores |
| 9 | `goveo:migrate:supabase:business` | Negocios (con geometría PostGIS) |
| 10 | `goveo:migrate:supabase:business-managers` | Quién gestiona cada negocio |
| 11 | `goveo:migrate:supabase:geostories` | Vídeos, con su ubicación |
| 12 | `goveo:migrate:supabase:notification-devices` | Dispositivos para notificaciones |

### Firestore

| Comando | Trae |
|---|---|
| `goveo:migrate:billing-products` | Colección `rates` → `billing_products` |
| `goveo:migrate:billing-plans` | Colección `plans` → `billing_plans`. Después de la anterior |
| `goveo:migrate:products` | `geoproducts` → `products` |
| `goveo:migrate:subcategories` | Subcategorías propias de cada tienda (dentro de `stores`) |
| `goveo:migrate:coupons` | `coupons` → `promo_codes` + `billing_discounts`. Después de `billing-plans` |
| `goveo:migrate:billing-plan-stripe-ids` | Recupera `stripe_price_id` desde los ids de `plans` |
| `goveo:migrate:firebase-auth-to-keycloak` | Usuarios **con sus hashes**, así que nadie pierde la contraseña |

⚠️ Existen también `goveo:migrate:supabase:billing-products` y
`goveo:migrate:supabase:billing-plans`, pero **no entran en `goveo:migrate:all`**:
para facturación manda Firestore, que es donde vivía el dato bueno. Están por si
algún día hace falta comparar las dos fuentes.

**Los productos sólo están en Firestore** (`goveo:migrate:products`, colección
`geoproducts`). Hubo un `goveo:migrate:supabase:products` que consultaba una tabla
`geoproducts` inexistente en Supabase: fallaba siempre y se ha retirado.

### Inspección

`goveo:supabase:inspect` lista las tablas de Supabase, o enseña columnas y filas
de ejemplo de una concreta. Útil antes de escribir un import nuevo.

## Migraciones de esquema

```bash
docker exec goveo-bff-php-1 sh -lc 'php bin/console doctrine:migrations:migrate --no-interaction'
```

`doctrine:schema:validate` avisa de que el esquema no está sincronizado: es
**normal**. Hay índices y restricciones creados a mano en las migraciones que no
se declaran en el mapping (`idx_geostories_*`, los CHECK de facturación…).

---

## 🪤 `.env.local` y Docker

`docker-compose.yml` carga `.env` con `env_file`, así que **todo lo de `.env`
entra como variable de entorno real del contenedor**, y Symfony no sobrescribe
variables reales con `.env.local`. Una clave declarada vacía en `.env` tapa la de
`.env.local`, y el error no apunta a la causa.

Está resuelto añadiendo `.env.local` al `env_file` con `required: false`, pero al
añadir una variable nueva: **o va en `.env` con su valor, o va sólo en
`.env.local`**. Declararla vacía en `.env` la rompe.

## Copia de seguridad de la base

```bash
make backup            # producción → ./backups/goveo-prod-<fecha>.dump
make backup ENV=demo   # demo
```

Antes de una migración, siempre. El volcado **viaja por la tubería** desde el
servidor hasta aquí: no se escribe nada en producción, así que no hay que
acordarse de borrarlo después ni depende de que le quede sitio en disco.

Va en formato `custom` (`pg_dump -Fc`): ocupa bastante menos que SQL plano y al
restaurar deja elegir tablas sueltas en vez de tragarse el fichero entero.

**Se verifica antes de darlo por bueno.** Primero la cabecera —un volcado
empieza por `PGDMP`, y así se descarta que lo que ha llegado sea un mensaje de
error de `ssh` o de `docker`— y luego se cuentan las tablas con datos. Si algo
no cuadra, el fichero se borra: un volcado a medias con buen aspecto es peor que
ninguno, porque el fallo se descubre el día que hace falta restaurarlo.

El nombre del contenedor lo pone Dokploy y cambia en cada redespliegue, así que
se busca por imagen. Si hubiera varios candidatos el script para y los enseña,
en vez de volcar la base equivocada:

```bash
GOVEO_DB_CONTAINER=<nombre> ./bin/goveo-backup prod
```

Restaurar en local, encima de lo que haya:

```bash
docker compose exec -T db pg_restore -U goveo -d goveo --clean --if-exists < backups/goveo-prod-<fecha>.dump
```

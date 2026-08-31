# Despliegue en Dokploy

Dos entornos. **Desarrollo local tira de los servicios de demo** (Stripe y Bunny),
así que no hay un tercer juego de credenciales que mantener.

| | Demo | Producción |
|---|---|---|
| Web | `demo.goveo.app` | `goveo.app` |
| BFF | `api-demo.goveo.app` | `api.goveo.app` |
| Keycloak | `auth-demo.goveo.app` | `auth.goveo.app` |
| Stripe | modo **test** | modo **live** |
| Bunny Storage | `test-goveo-storage` → `test-goveo.b-cdn.net` | `goveo-storage` → `goveo.b-cdn.net` |
| Bunny Stream | librería `740458` → `vz-68dffda0-947.b-cdn.net` | librería `497837` → `vz-57b55505-951.b-cdn.net` |

El **desarrollo local usa los servicios de demo** (Bunny y Stripe), así que basta
con no tener las credenciales de producción en la máquina para no poder romperla.

Cada entorno tiene **su propia base de datos y su propio Keycloak**. Eso resuelve
solo el problema de que los `stripe_price_id` y los `stripe_product_id` no son
portables entre entornos: cada base guarda los de su cuenta.

---

## Servicios por entorno

| Servicio | Imagen | Notas |
|---|---|---|
| `web` | `goveo-astro/Dockerfile` | Puerto 4321 |
| `bff` | `goveo-bff/Dockerfile.prod` | php-fpm, puerto 9000 — necesita un nginx delante |
| `nginx` | `nginx:1.25-alpine` | Config en `docker/nginx/default.conf` |
| `db` | `postgis/postgis` | **PostGIS, no Postgres a secas**: el feed y el mapa usan `ST_DWithin` |
| `keycloak` | `quay.io/keycloak/keycloak` | Con su propia base |
| `keycloak-db` | `postgres` | Separada de la de la aplicación |

Mailpit **no** va a ningún entorno desplegado: es sólo para desarrollo. En demo y
producción, `MAILER_DSN` apunta a un SMTP de verdad.

---

## ⚠️ La web necesita las variables en el BUILD, no al arrancar

Las variables de `astro:env` con `context: client` **se incrustan en el bundle al
construir**. Si en Dokploy se ponen sólo como variables de entorno del
contenedor, la web arranca pero apunta a los valores por defecto — que son los de
producción. Es exactamente lo que haría que **demo hablase con la API buena**.

En Dokploy hay que declararlas como **build args**:

```
SUPABASE_URL, SUPABASE_API_KEY, GOOGLE_MAPS_API_KEY
BFF_URL             https://api-demo.goveo.app   |  https://api.goveo.app
KEYCLOAK_URL        https://auth-demo.goveo.app  |  https://auth.goveo.app
KEYCLOAK_REALM      goveo
KEYCLOAK_CLIENT_ID  goveo-app
```

Si falta alguna obligatoria, **el build falla** en vez de desplegar algo roto.
Para comprobar que un despliegue quedó bien apuntado:

```bash
docker run --rm --entrypoint sh <imagen> -lc 'grep -rho "https://api[^\"]*" dist/client/_astro/*.js | sort -u'
```

---

## Variables del BFF (runtime)

```
APP_ENV=prod
DATABASE_URL=postgresql://...             # PostGIS del entorno
KEYCLOAK_URL=http://keycloak:8080         # interna, entre contenedores
KEYCLOAK_PUBLIC_URL=https://auth-demo.goveo.app
KEYCLOAK_REALM=goveo
KEYCLOAK_CLIENT_ID=goveo-app
KEYCLOAK_CLIENT_SECRET=...
KEYCLOAK_ADMIN_USER / KEYCLOAK_ADMIN_PASSWORD

STRIPE_SECRET_KEY=sk_test_... | sk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...           # uno por entorno, del panel de Stripe
BILLING_TAX_PERCENT=21

BUNNY_STORAGE_ZONE / BUNNY_STORAGE_PASSWORD
BUNNY_STORAGE_HOST=storage.bunnycdn.com
BUNNY_STORAGE_CDN_HOSTNAME=...
BUNNY_API_KEY / BUNNY_LIBRARY_ID / BUNNY_CDN_HOSTNAME_VIDEOS   # Stream, vídeo

MAILER_DSN=smtp://...
EMAIL_FROM="Goveo <noreply@goveo.app>"
WEB_URL=https://demo.goveo.app | https://goveo.app
```

⚠️ `MAILER_DSN` aparece **dos veces** en `.env` si alguien reinstala
`symfony/mailer`: la receta de Flex añade `null://null` al final, que gana y tira
los correos en silencio. Si dejan de salir correos, mirar eso primero.

---

## Qué hay que crear fuera de Dokploy

1. **DNS**: los tres subdominios de demo apuntando al servidor.
2. **Bunny**: una zona de almacenamiento para demo con su pull zone.
3. **Stripe**: un endpoint de webhook por entorno →
   `https://api-demo.goveo.app/api/v1/webhooks/stripe`, con los eventos
   `checkout.session.completed`, `customer.subscription.updated` y
   `customer.subscription.deleted`. Cada uno da su propio `whsec_`.
4. **Google y Apple**: en el realm están declarados pero **deshabilitados y con
   credenciales de relleno**. Las URIs de redirección que hay que dar de alta en
   sus consolas son
   `https://auth-demo.goveo.app/realms/goveo/broker/{google|apple}/endpoint`
   y su equivalente de producción.

---

## Primer arranque de un entorno

```bash
doctrine:migrations:migrate --no-interaction
goveo:billing:seed-plans-2026 --retire-legacy
goveo:stripe:sync
goveo:billing:assign-free-plan     # sólo si se importan negocios heredados
```

El orden importa: ver [COMANDOS.md](COMANDOS.md).

---

## Un compose por entorno

| Fichero | Entorno |
|---|---|
| [`docker-compose.yml`](../docker-compose.yml) | local |
| [`docker-compose.demo.yml`](../docker-compose.demo.yml) | demo |
| [`docker-compose.prod.yml`](../docker-compose.prod.yml) | producción |

Demo y producción son **casi idénticos a propósito**: demo existe para ensayar lo que va
a pasar en producción, y sólo predice algo mientras se le parezca. Todo cambio
estructural —un servicio, una variable, una dependencia— va en los dos.

`make compose-diff` enseña en qué se separan hoy. Debería caber en una pantalla; si
crece, demo ha dejado de servir para lo que sirve.

**Adminer** se queda sólo en local. **Mailpit** está en local y demo, no en producción.

| | Local | Demo | Producción |
|---|---|---|---|
| Compose | `docker-compose.yml` | `docker-compose.dokploy.yml` | el mismo |
| Correo | mailpit | mailpit (perfil `demo`) | SMTP real |
| Adminer | sí | no | no |
| Keycloak | `start-dev` | `start --optimized` | `start --optimized` |
| Bunny / Stripe | los de demo | los de demo | los suyos |

### Mailpit en demo

El correo de demo se queda dentro: `MAILER_DSN` apunta a `smtp://mailpit:1025` y
Keycloak manda los suyos al mismo buzón. Así se dan altas de prueba sin que salga nada
a direcciones reales, y se ve el correo de bienvenida tal como queda.

**`MAILPIT_UI_AUTH` es obligatoria** (formato `usuario:contraseña`). Es la única
autenticación que tiene Mailpit —no habla OIDC, sólo ficheros de contraseñas—, y para
una herramienta interna basta. Dejarla vacía no deja el buzón medio abierto: lo deja
abierto del todo, y los correos de bienvenida contienen el enlace de crear contraseña,
así que leer el buzón es poder entrar en cualquier cuenta de demo.

**Demo no prueba la entrega.** SPF, DKIM y reputación del dominio no se ejercitan contra
un buzón local, así que eso sólo se comprobará ya en producción. Para validarlo antes,
basta con apuntar `MAILER_DSN` de demo al SMTP real.

## Orden de despliegue

1. **Bases** — las levanta el propio compose, con volumen. La de la aplicación es
   PostGIS.
2. **Keycloak** — primer arranque: importa el realm y crea el admin del master.
   Comprobar en los logs `✅ [configure-idp] Secreto de goveo-bff aplicado`; si dice que
   `KEYCLOAK_CLIENT_SECRET` está vacío, el realm se queda con el marcador y el BFF no
   podrá validar ningún token.
3. **BFF** — el entrypoint aplica migraciones y calienta caché antes de servir.
4. **Comandos de datos** — `goveo:plans:seed` y `goveo:stripe:sync` (ver
   [COMANDOS.md](COMANDOS.md)). El `sync` **sin** `--overwrite-ids` en producción.
5. **Webhook de Stripe** — dar de alta el endpoint del entorno y copiar su `whsec_`.
6. **Google y Apple** — con Keycloak ya en su dominio, registrar los retornos
   `https://auth-{demo}.goveo.app/realms/goveo/broker/{google|apple}/endpoint`
   y rellenar las variables. `configure-idp.sh` los activa en el siguiente arranque.

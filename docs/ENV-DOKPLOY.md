# Variables de entorno por servicio (Dokploy)

Sólo los **nombres**. Los valores se ponen en el panel, uno por entorno.

Cada entorno (demo y producción) es un juego completo: base propia, Keycloak propio,
Stripe propio y zona de Bunny propia. **El desarrollo local usa los servicios de demo**,
así que no hay un tercer juego de credenciales que mantener.

---

## 1 · BFF (`goveo-bff`) — Dockerfile.prod

### Aplicación
```
APP_ENV                 # prod
APP_SECRET              # 32 bytes aleatorios, distinto por entorno
DEFAULT_URI             # https://api-demo.goveo.app — lo usan las URLs generadas en CLI
CORS_ALLOW_ORIGIN       # regex de orígenes permitidos (web + landing)
WEB_URL                 # https://demo.goveo.app — base de los enlaces de los correos
```

### Base de datos
```
DATABASE_URL            # postgresql://usuario:clave@host:5432/goveo?serverVersion=16&charset=utf8
```

### Keycloak
```
KEYCLOAK_URL            # interno, el que ve el contenedor
KEYCLOAK_PUBLIC_URL     # el que ve el navegador; va en los tokens
KEYCLOAK_REALM
KEYCLOAK_CLIENT_ID      # goveo-bff (confidencial)
KEYCLOAK_CLIENT_SECRET
KEYCLOAK_ADMIN_USER     # para crear cuentas en el alta
KEYCLOAK_ADMIN_PASSWORD
```

### Stripe
```
STRIPE_SECRET_KEY       # sk_test_… en demo, sk_live_… en producción
STRIPE_WEBHOOK_SECRET   # whsec_… propio de cada endpoint, no se comparte
```

### Bunny
```
BUNNY_STORAGE_ZONE            # imágenes
BUNNY_STORAGE_PASSWORD
BUNNY_STORAGE_HOST            # storage.bunnycdn.com
BUNNY_STORAGE_CDN_HOSTNAME
BUNNY_API_KEY                 # vídeos (Stream)
BUNNY_LIBRARY_ID
BUNNY_CDN_HOSTNAME_VIDEOS
BUNNY_WEBHOOK_SECRET          # firma del aviso de vídeo procesado
```

### Correo y facturación
```
MAILER_DSN              # smtp://… — ojo: no dejarlo vacío, descarta el correo en silencio
EMAIL_FROM
BILLING_TAX_PERCENT     # 21
```

### Sólo migración (se pueden omitir)
```
FIREBASE_PROJECT_ID
FIREBASE_CREDENTIALS
SUPABASE_DATABASE_URL
```
Los usan los comandos de importación del histórico. En producción no hacen falta salvo
que se vaya a reimportar.

---

## 2 · Keycloak — imagen propia (`docker/keycloak`)

```
KC_DB                       # postgres
KC_DB_URL                   # jdbc:postgresql://host:5432/keycloak
KC_DB_USERNAME
KC_DB_PASSWORD
KC_BOOTSTRAP_ADMIN_USERNAME # sólo el primer arranque
KC_BOOTSTRAP_ADMIN_PASSWORD
KC_HOSTNAME                 # https://auth-demo.goveo.app
KC_HOSTNAME_STRICT          # false
KC_PROXY_HEADERS            # xforwarded — sin esto Keycloak genera enlaces http://
KC_HTTP_ENABLED             # true: TLS lo termina el proxy de Dokploy
KEYCLOAK_PUBLIC_URL
```

### Identidades sociales — las sustituye el realm al importarse
```
GOOGLE_SOCIAL_CLIENT_ID
GOOGLE_SOCIAL_CLIENT_SECRET
APPLE_CLIENT_ID
APPLE_TEAM_ID
APPLE_KEY_ID
APPLE_PRIVATE_KEY           # multilínea (.p8)
```
URI de retorno a registrar en Google y Apple:
`https://auth-demo.goveo.app/realms/goveo/broker/{google|apple}/endpoint`

---

## 3 · Web (`goveo-astro`) — **como argumentos de build, no de runtime**

Todas son `context: client` en `astro:env`: se **incrustan en el bundle al compilar**.
Puestas sólo como variables de runtime el contenedor arranca igual, pero la web queda
apuntando a los valores por defecto, que son los de **producción**. Es lo que haría que
demo hablase con la API buena.

En Dokploy: *Build args*, no *Environment*.
```
SUPABASE_URL
SUPABASE_API_KEY
GOOGLE_MAPS_API_KEY
BFF_URL                 # https://api-demo.goveo.app
KEYCLOAK_URL            # https://auth-demo.goveo.app
KEYCLOAK_REALM          # goveo
KEYCLOAK_CLIENT_ID      # goveo-app (público, con PKCE)
```
El runtime sólo necesita lo que ya fija el Dockerfile (`HOST`, `PORT`, `NODE_ENV`).

---

## 4 · Bases de datos

Dos por entorno, la de la aplicación y la de Keycloak. La de la aplicación necesita
**PostGIS** (`postgis/postgis:16-3.4`), no vale el `postgres` a secas: las consultas de
cercanía del feed y del mapa son geográficas.
```
POSTGRES_USER
POSTGRES_PASSWORD
POSTGRES_DB
```

---

## 5 · App móvil (`goveo-expo-approuter`)

No va en Dokploy — se compila con EAS. Aquí por completitud:
```
EXPO_PUBLIC_BFF_URL
EXPO_PUBLIC_WEB_URL
EXPO_PUBLIC_MANAGER_URL
EXPO_PUBLIC_SUPABASE_URL
EXPO_PUBLIC_SUPABASE_API_KEY
EXPO_PUBLIC_GOOGLE_MAPS_KEY
```
`EXPO_PUBLIC_*` viaja dentro del binario: nada que no pueda leer cualquiera.

---

## Lo que no debe compartirse entre entornos

| Variable | Por qué |
|---|---|
| `STRIPE_WEBHOOK_SECRET` | Cada endpoint de Stripe genera el suyo |
| `APP_SECRET` | Firma cookies y tokens de un solo entorno |
| `BUNNY_STORAGE_*`, `BUNNY_LIBRARY_ID` | Demo escribiría en los ficheros de producción |
| `DATABASE_URL` | — |
| `KEYCLOAK_CLIENT_SECRET` | El cliente confidencial es por realm |

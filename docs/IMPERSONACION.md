# Impersonación de usuarios

> **Estado: no implementado.** Investigado el 01-09-2026, replanteado y escrito
> aquí el 15-09-2026. Nada de lo que sigue está en el código; este fichero
> existe para que retomarlo no empiece de cero.

## Para qué, exactamente

Para **soporte desde la app**, no para el panel. El caso es éste: un negocio
dice que algo no le funciona, se coge el Samsung de pruebas, se arranca la app
contra producción (`npm run android:prod`) y se entra **como esa cuenta** para
ver lo mismo que ve quien llama.

Lo que hoy se puede hacer en su lugar es cambiarle la contraseña desde Keycloak,
que es peor de lo que parece: deja fuera al usuario de verdad justo cuando está
esperando que le arreglen algo.

El botón *Impersonate* de la consola de Keycloak **no vale** para esto: abre una
sesión de navegador por cookie, y la app entra por *direct grant* pidiendo
tokens al BFF. No comparten nada.

## Qué hay ya puesto

| Pieza | Dónde | Estado |
|---|---|---|
| Feature `token-exchange` en Keycloak | `KC_FEATURES` en los tres `docker-compose*.yml` | ✅ activa en local, demo y **producción** |
| Feature `admin-fine-grained-authz` | ídem | ✅ activa en los tres |
| Service account de `goveo-bff` | `docker/keycloak/goveo-realm.json` (`serviceAccountsEnabled: true`) | ✅ |
| Una llamada de `token-exchange` ya escrita | `KeycloakService::loginWithSocialToken()` | ✅ sirve de patrón |
| Punto único de entrada de sesión en la app | `handleTokens()` en `context/AuthContext.tsx` | ✅ |

Las dos *features* de Keycloak están ahí por el **acceso social** (canjear el
token nativo de Google o Apple por uno del realm), no por esto. Pero son el
mismo prerrequisito, así que ese trozo ya está pagado.

## Qué falta

Tres cosas, y ninguna es grande.

### 1. Dos permisos en Keycloak

Se conceden en `docker/keycloak/configure-idp.sh`, siguiendo la función
`allow_token_exchange()` que ya está escrita ahí para Google y Apple:

- Rol de cliente `realm-management:impersonation` sobre la **service account de
  `goveo-bff`**.
- Permiso de `token-exchange` sobre el **propio cliente `goveo-bff`**, que es el
  que emite los tokens de la app y de la web. `goveo-app` es público y el BFF no
  lo usa.

Va en el script y no en `goveo-realm.json` por la misma razón que el resto:
**estos permisos viven en la base de datos, no en el JSON del realm**. Un
entorno nuevo importa el realm sin ellos y la impersonación falla en silencio
con «Client not allowed to exchange», que es el mismo error que da el acceso
social mal configurado.

### 2. El endpoint del BFF

Cuelga de `/api/admin/…`, que ya exige `ROLE_BACKOFFICE_ACCESS`
(`config/packages/security.yaml`). Pero **acceso al panel no debería bastar**:
el panel tiene un rol por capacidad (`business.verify`, `geostory.delete`…), y
esto merece el suyo — `user.impersonate`, creado con `ensure_role` en
`configure-backoffice.sh` y metido en el grupo `backoffice-admin`. El
`KeycloakAuthenticator` lo traduce solo a `ROLE_USER_IMPERSONATE`.

La llamada es *direct naked impersonation*: el mismo `grant_type` que
`loginWithSocialToken()`, cambiando `subject_token` por `requested_subject` y
sin `subject_issuer`.

```
POST /realms/goveo/protocol/openid-connect/token
  grant_type=urn:ietf:params:oauth:grant-type:token-exchange
  client_id=goveo-bff
  client_secret=…
  requested_subject=negocio@ejemplo.com
```

`requested_subject` **acepta el correo** porque el realm tiene
`registrationEmailAsUsername=true`. No hace falta buscar antes el id.

Ojo con un detalle que induce a error: el cliente se autentica aquí con su
`client_secret`, o sea que **`getAdminToken()` no pinta nada** en este camino.
Ese método coge un token del admin del realm `master` por `admin-cli`, que es
otra cosa y no es la service account a la que hay que dar el rol.

Y registrar **quién impersona a quién** con `LoggerInterface`. Si alguna vez se
quiere consultable desde el panel, haría falta tabla; por ahora el log basta.

### 3. La entrada en la app

Aquí está el atajo que abarata todo el asunto: **`npm run android:prod` es una
build de depuración apuntando a producción** — es `expo run:android` con
`.env.production`, y los `EXPO_PUBLIC_*` se incrustan al empaquetar, no al
compilar. O sea que `__DEV__` es `true` ahí y `false` en el APK de release.

Así que la entrada puede ser un campo detrás de `if (__DEV__)` en la pantalla de
acceso, y no hay ni que plantearse esconderla: **no existe en lo que se
reparte**. Eso es bastante mejor que un gesto secreto o un flag de build, que sí
viajarían a la tienda.

Del lado del estado, es una línea: todo lo que sabe entrar (contraseña,
registro, social) pasa por `handleTokens(tokens)` en `context/AuthContext.tsx`.
Unos tokens dados entran por ahí igual que los demás.

## Cautelas

- **No hay «sólo lectura».** Lo que sale del intercambio es un token completo del
  usuario: lo que se toque, se toca en su cuenta de verdad. Por eso el log no es
  decorativo.
- **No guardar el refresh token** en este camino. Sin él la sesión impersonada se
  muere en minutos, en vez de quedarse viva en el Samsung indefinidamente.
- **Keycloak está en 26.0** (`quay.io/keycloak/keycloak:26.0`), donde
  `token-exchange` es *preview* y de la v1. Al subir a 26.2+ pasa a la v2
  estándar y esta llamada cambia de forma.

## Ficheros que tocar

| Fichero | Qué |
|---|---|
| `docker/keycloak/configure-idp.sh` | Rol `impersonation` + permiso de exchange sobre `goveo-bff` |
| `docker/keycloak/configure-backoffice.sh` | `ensure_role "user.impersonate"` + al grupo `backoffice-admin` |
| `src/Auth/Infrastructure/Service/KeycloakService.php` | Método de intercambio con `requested_subject` |
| `src/Backoffice/…/Controller/` | Endpoint `/api/admin/…` con el rol y el log |
| App: `context/AuthContext.tsx` y la pantalla de acceso | Campo tras `__DEV__` que llama al endpoint y pasa los tokens a `handleTokens()` |

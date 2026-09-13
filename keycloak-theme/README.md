# Tema de Keycloak de Goveo

Las pantallas que enseña Keycloak (entrar, recuperar la contraseña, elegir una
nueva) y los correos que manda él mismo. Hecho con
[Keycloakify](https://docs.keycloakify.dev/v/v11): las páginas son React y los
correos se escriben también en React y se convierten a FreeMarker al construir.

## Para qué

- **El correo de «he olvidado mi contraseña»**, que es lo que lo estrenó. Sale
  con la misma caja que el resto del correo a clientes (`GoveoEmailLayout` en el
  BFF): dos remitentes que no se parecen son dos empresas distintas para quien
  los recibe, y menos aún puede desentonar justo el correo que cambia una clave.
- **El login de la web**, que hará falta en cuanto goveo.app tenga sesión: ahí
  la pantalla la pinta Keycloak y no nosotros.

## Cómo se trabaja

```bash
npm install
npm run build-keycloak-theme     # necesita Maven y Java (el jar lo empaqueta mvn)
npx keycloakify start-keycloak   # levanta un Keycloak con el tema puesto, en :8083
```

Para ver un correo sin montar nada: construir y mirar el `.ftl` generado en
`dist_keycloak/…/theme/goveo/email/html/es/`. Para verlo **entregado**, pedir una
recuperación contra el BFF local y abrir Mailpit:

```bash
curl -X POST localhost:8080/public/account/password-reset \
     -H 'Content-Type: application/json' -d '{"email":"backoffice@goveo.app"}'
open http://localhost:8025
```

## Qué hay dentro

| | |
|---|---|
| `src/login/pages/Login.tsx` | Entrar: accesos sociales arriba, correo y contraseña debajo |
| `src/login/pages/ResetPassword.tsx` | Pedir el enlace («he olvidado mi contraseña») |
| `src/login/pages/LoginUpdatePassword.tsx` | Elegir la contraseña nueva — a donde lleva el correo |
| `src/login/pages/Base.tsx` | Qué página pinta cada `pageId`; el resto son las de Keycloakify |
| `src/login/i18n.ts` | Los textos, en español y en inglés |
| `src/email/templates/` | Los correos (recuperación, acciones, verificación, prueba de SMTP) |
| `src/assets/styles/index.css` | La paleta, la misma de la app |

**Sólo están escritas las tres pantallas que se usan.** Las demás salen de
Keycloakify y heredan igualmente la caja, porque todas pasan por el mismo
`Template`. Escribir treinta pantallas que nadie va a ver para que se parezcan a
la marca es trabajo que envejece solo.

**Las dos lenguas tienen que declarar las mismas claves**: si una falta en `en`,
la compilación de tipos falla. Es a propósito — así no hay pantallas medio
traducidas.

## Cómo llega a Keycloak

Lo construye el `Dockerfile` de Keycloak (`docker/keycloak/Dockerfile`, primera
etapa) y copia el jar a `/opt/keycloak/providers/`. El realm se apunta al tema en
`configure-idp.sh` (`loginTheme` y `emailTheme`), que corre en cada arranque: el
`goveo-realm.json` sólo se lee la primera vez que nace un realm, así que ponerlo
ahí no llegaría ni a local ni a producción.

El jar **no se commitea**: es un generado, y tenerlo dentro significaría que
cambiar una coma obliga a acordarse de recompilarlo.

# ── El tema (login + correos) ────────────────────────────────────────────────
# Se construye aquí y no a mano porque el resultado es un .jar generado: tenerlo
# commiteado significaría que cambiar un texto obliga a acordarse de recompilarlo
# y a subir un binario. `keycloakify build` necesita Maven además de Node — es lo
# que empaqueta el jar—, y por eso esta etapa no es un `node` a secas.
FROM node:20-bookworm-slim AS theme

RUN apt-get update \
    && apt-get install -y --no-install-recommends maven \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /theme

# Primero el manifiesto: mientras no cambien las dependencias, Docker se salta
# la instalación entera al reconstruir.
COPY keycloak-theme/package.json keycloak-theme/package-lock.json ./
RUN npm ci

COPY keycloak-theme/ ./
RUN npm run build-keycloak-theme


# ── Keycloak ─────────────────────────────────────────────────────────────────
FROM quay.io/keycloak/keycloak:26.0

USER root

# Copy Social Login providers
COPY docker/keycloak/providers/apple-identity-provider-1.14.0.jar /opt/keycloak/providers/
COPY docker/keycloak/providers/keycloak-firebase-scrypt-0.0.1.jar /opt/keycloak/providers/

# El tema de Goveo. Va en `providers/` y no en `themes/`: un tema empaquetado en
# jar se carga desde ahí, y así el `kc.sh build` de abajo lo ve y lo deja ya
# resuelto en la imagen.
COPY --from=theme /theme/dist_keycloak/keycloak-theme-for-kc-all-other-versions.jar /opt/keycloak/providers/goveo-theme.jar

# Copy init scripts
COPY docker/keycloak/init.sh /opt/keycloak/bin/init.sh
COPY docker/keycloak/configure-idp.sh /opt/keycloak/bin/configure-idp.sh
COPY docker/keycloak/configure-backoffice.sh /opt/keycloak/bin/configure-backoffice.sh
RUN chmod +x /opt/keycloak/bin/init.sh /opt/keycloak/bin/configure-idp.sh /opt/keycloak/bin/configure-backoffice.sh

USER keycloak

# Se compila ya contra Postgres para poder arrancar con `--optimized`: si no,
# Keycloak reconstruye en cada arranque y tarda casi un minuto en levantar,
# que en un despliegue con health check es la diferencia entre verde y rojo.
# `token-exchange` es lo que permite canjear un token de Google o Apple por uno
# del realm: la app obtiene el token con el SDK nativo y el BFF lo intercambia
# aquí (ver KeycloakService::loginWithSocialToken). Sin esta bandera, Keycloak
# responde `unsupported_grant_type` y el acceso social no funciona.
#
# `admin-fine-grained-authz` hace falta para conceder al cliente `goveo-bff`
# permiso de intercambio sobre cada proveedor: sin permisos explícitos, el
# intercambio se deniega aunque el grant esté habilitado.
#
# Ambas son *preview* en Keycloak 26. Se activan al construir, no al arrancar.
RUN /opt/keycloak/bin/kc.sh build \
        --db=postgres \
        --health-enabled=true \
        --features=token-exchange,admin-fine-grained-authz

ENTRYPOINT ["/opt/keycloak/bin/init.sh"]

# Producción por defecto. El compose de desarrollo lo sobrescribe con
# `start-dev`, que es lo que permite hostname laxo y HTTP a pelo.
CMD ["start", "--optimized", "--import-realm"]

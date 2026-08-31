FROM quay.io/keycloak/keycloak:26.0

USER root

# Copy Social Login providers
COPY providers/apple-identity-provider-1.14.0.jar /opt/keycloak/providers/
COPY providers/keycloak-firebase-scrypt-0.0.1.jar /opt/keycloak/providers/

# Copy init scripts
COPY init.sh /opt/keycloak/bin/init.sh
COPY configure-idp.sh /opt/keycloak/bin/configure-idp.sh
RUN chmod +x /opt/keycloak/bin/init.sh /opt/keycloak/bin/configure-idp.sh

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

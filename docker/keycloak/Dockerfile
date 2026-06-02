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

# Rebuild Keycloak with the new providers
RUN /opt/keycloak/bin/kc.sh build

ENTRYPOINT ["/opt/keycloak/bin/init.sh"]
CMD ["start-dev", "--import-realm"]

#!/bin/bash
# Goveo Keycloak initialization script.
# Launches configure-idp.sh in background (waits for KC readiness),
# then hands off to the Keycloak process.

set -e

echo "🔧 Verificando configuración de Goveo Keycloak..."

# Verify providers are present
for JAR in \
    /opt/keycloak/providers/apple-identity-provider-1.14.0.jar \
    /opt/keycloak/providers/keycloak-firebase-scrypt-0.0.1.jar; do
    if [ -f "${JAR}" ]; then
        echo "✅ Provider found: $(basename ${JAR})"
    else
        echo "❌ Provider missing: ${JAR}"
        exit 1
    fi
done

# Verify import directory and realm file
if [ -f "/opt/keycloak/data/import/goveo-realm.json" ]; then
    echo "✅ Realm config found: goveo-realm.json"
else
    echo "❌ Realm config not found at /opt/keycloak/data/import/goveo-realm.json"
    exit 1
fi

echo "🚀 Starting Keycloak..."

# Launch IDP configurator in background (activates Google/Apple with real credentials)
/opt/keycloak/bin/configure-idp.sh &

exec /opt/keycloak/bin/kc.sh "$@"

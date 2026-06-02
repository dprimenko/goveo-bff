#!/bin/bash
# Configura los Identity Providers de Google y Apple en Keycloak.
# Se ejecuta en background tras el arranque (realm ya importado en DB).
# Usa kcadm.sh (CLI integrada en Keycloak) para evitar dependencia de curl/python3.

KCADM="/opt/keycloak/bin/kcadm.sh"
KC_URL="http://localhost:8080"
KC_ADMIN_USER="${KC_BOOTSTRAP_ADMIN_USERNAME:-admin}"
KC_ADMIN_PASS="${KC_BOOTSTRAP_ADMIN_PASSWORD:-admin123}"
REALM="goveo"
# kcadm config file en /tmp para evitar problemas de permisos en home
KCADM_CONFIG="/tmp/kcadm.config"

echo "🔧 [configure-idp] Iniciando configuración de Identity Providers..."

# --- Esperar a que Keycloak esté listo usando kcadm ---
MAX_RETRIES=60
RETRY=0
until "$KCADM" config credentials \
    --config "$KCADM_CONFIG" \
    --server "$KC_URL" \
    --realm master \
    --user "$KC_ADMIN_USER" \
    --password "$KC_ADMIN_PASS" > /dev/null 2>&1; do
    RETRY=$((RETRY + 1))
    if [ "$RETRY" -ge "$MAX_RETRIES" ]; then
        echo "❌ [configure-idp] Timeout esperando a Keycloak"
        exit 1
    fi
    echo "⏳ [configure-idp] Esperando Keycloak... ($RETRY/$MAX_RETRIES)"
    sleep 5
done

echo "✅ [configure-idp] Keycloak listo"

# ============================================================
# GOOGLE IDENTITY PROVIDER
# ============================================================
if [ -n "${GOOGLE_SOCIAL_CLIENT_ID}" ]; then
    echo "🔧 [configure-idp] Configurando Google Identity Provider..."
    if "$KCADM" update identity-provider/instances/google \
        --config "$KCADM_CONFIG" \
        -r "$REALM" \
        -s enabled=true \
        -s trustEmail=true \
        -s "config.clientId=${GOOGLE_SOCIAL_CLIENT_ID}" \
        -s "config.clientSecret=${GOOGLE_SOCIAL_CLIENT_SECRET}"; then
        echo "✅ [configure-idp] Google Identity Provider activado"
    else
        echo "❌ [configure-idp] Error actualizando Google IDP"
    fi
else
    echo "ℹ️  [configure-idp] GOOGLE_SOCIAL_CLIENT_ID no configurado — Google IDP permanece desactivado"
fi

# ============================================================
# APPLE IDENTITY PROVIDER
# ============================================================
if [ -n "${APPLE_CLIENT_ID}" ]; then
    echo "🔧 [configure-idp] Configurando Apple Identity Provider..."
    # Convertir \n literales del env var a saltos de línea reales
    APPLE_KEY_CLEAN=$(printf '%s' "${APPLE_PRIVATE_KEY}" | sed 's/\\n/\n/g')
    if "$KCADM" update identity-provider/instances/apple \
        --config "$KCADM_CONFIG" \
        -r "$REALM" \
        -s enabled=true \
        -s trustEmail=true \
        -s "config.clientId=${APPLE_CLIENT_ID}" \
        -s "config.teamId=${APPLE_TEAM_ID}" \
        -s "config.keyId=${APPLE_KEY_ID}" \
        -s "config.privateKey=${APPLE_KEY_CLEAN}"; then
        echo "✅ [configure-idp] Apple Identity Provider activado (client_id=${APPLE_CLIENT_ID})"
    else
        echo "❌ [configure-idp] Error actualizando Apple IDP"
    fi
else
    echo "ℹ️  [configure-idp] APPLE_CLIENT_ID no configurado — Apple IDP permanece desactivado"
fi

echo "✅ [configure-idp] Configuración de Identity Providers finalizada"

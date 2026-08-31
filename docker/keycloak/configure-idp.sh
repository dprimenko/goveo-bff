#!/bin/bash
# Ajusta el realm de Goveo con los valores propios de cada entorno.
#
# El realm se importa **una sola vez**, en el primer arranque: a partir de ahí
# el fichero JSON deja de mirarse. Por eso todo lo que cambia entre demo y
# producción —secreto del cliente, SMTP, proveedores sociales— se aplica aquí
# en cada arranque, y no dentro del JSON, que además está en git.
#
# Se ejecuta en background tras el arranque. Usa kcadm.sh (la CLI que trae
# Keycloak) para no depender de curl ni de python3 en la imagen.

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
# SECRETO DEL CLIENTE CONFIDENCIAL
# ============================================================
# En el JSON versionado es un marcador, no un secreto. El de verdad entra por
# entorno y se aplica en cada arranque: así demo y producción no comparten
# credencial aunque partan del mismo realm.
if [ -n "${KEYCLOAK_CLIENT_SECRET}" ]; then
    CLIENT_UUID=$("$KCADM" get clients --config "$KCADM_CONFIG" -r "$REALM" \
        -q clientId=goveo-bff --fields id --format csv --noquotes 2>/dev/null | head -1)

    if [ -n "$CLIENT_UUID" ]; then
        if "$KCADM" update "clients/${CLIENT_UUID}" --config "$KCADM_CONFIG" \
            -r "$REALM" -s "secret=${KEYCLOAK_CLIENT_SECRET}"; then
            echo "✅ [configure-idp] Secreto de goveo-bff aplicado"
        else
            echo "❌ [configure-idp] Error aplicando el secreto de goveo-bff"
        fi
    else
        echo "❌ [configure-idp] No se encuentra el cliente goveo-bff"
    fi
else
    echo "⚠️  [configure-idp] KEYCLOAK_CLIENT_SECRET vacío — el realm se queda con el marcador"
fi

# ============================================================
# SMTP DEL REALM
# ============================================================
# Son los correos que manda Keycloak por su cuenta (recuperar contraseña,
# verificar email), no los nuestros. Sin esto apuntan a `mailpit`, que en
# producción no existe: el usuario pide restablecer y no le llega nada.
if [ -n "${KC_SMTP_HOST}" ]; then
    if "$KCADM" update realms/"$REALM" --config "$KCADM_CONFIG" \
        -s "smtpServer.host=${KC_SMTP_HOST}" \
        -s "smtpServer.port=${KC_SMTP_PORT:-587}" \
        -s "smtpServer.from=${KC_SMTP_FROM:-noreply@goveo.app}" \
        -s "smtpServer.fromDisplayName=Goveo" \
        -s "smtpServer.ssl=${KC_SMTP_SSL:-false}" \
        -s "smtpServer.starttls=${KC_SMTP_STARTTLS:-true}" \
        -s "smtpServer.auth=${KC_SMTP_USER:+true}" \
        -s "smtpServer.user=${KC_SMTP_USER}" \
        -s "smtpServer.password=${KC_SMTP_PASSWORD}"; then
        echo "✅ [configure-idp] SMTP del realm configurado (${KC_SMTP_HOST})"
    else
        echo "❌ [configure-idp] Error configurando el SMTP del realm"
    fi
else
    echo "ℹ️  [configure-idp] KC_SMTP_HOST no configurado — se mantiene el del realm"
fi

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

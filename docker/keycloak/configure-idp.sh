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
# VINCULACIÓN AUTOMÁTICA POR CORREO
# ============================================================
# Los usuarios importados de Firebase ya están en el realm con su correo. Con el
# flujo que trae Keycloak de fábrica, el primero que entre con Google se
# encuentra una pantalla pidiéndole que confirme la vinculación —o un error de
# correo duplicado, porque el realm no los permite—, en vez de entrar y ya está.
#
# Como los proveedores están marcados `trustEmail`, la dirección ya viene
# verificada por Google o Apple: no hay nada que confirmar. Este flujo crea el
# usuario si no existe y, si existe, lo vincula sin preguntar.
FLOW_ALIAS="goveo auto link"
FLOW_PATH="authentication/flows/goveo%20auto%20link"

ensure_auto_link_flow() {
    if "$KCADM" get "$FLOW_PATH/executions" --config "$KCADM_CONFIG" -r "$REALM" >/dev/null 2>&1; then
        echo "ℹ️  [configure-idp] El flujo «$FLOW_ALIAS» ya existe"
        return 0
    fi

    "$KCADM" create authentication/flows --config "$KCADM_CONFIG" -r "$REALM" \
        -s "alias=$FLOW_ALIAS" -s providerId=basic-flow -s topLevel=true -s builtIn=false >/dev/null 2>&1

    for provider in idp-create-user-if-unique idp-auto-link; do
        "$KCADM" create "$FLOW_PATH/executions/execution" --config "$KCADM_CONFIG" -r "$REALM" \
            -s "provider=$provider" >/dev/null 2>&1
    done

    # Nacen DISABLED; hay que marcarlas como alternativas para que se evalúen en
    # orden: crear si es nuevo, vincular si ya existía.
    #
    # `-n` es imprescindible: sin él kcadm relee el recurso antes de escribir, y
    # este endpoint devuelve una lista, así que la actualización falla con un
    # error de deserialización que no dice nada.
    local ids
    ids=$("$KCADM" get "$FLOW_PATH/executions" --config "$KCADM_CONFIG" -r "$REALM" 2>/dev/null \
        | grep -o '"id"[[:space:]]*:[[:space:]]*"[^"]*"' | sed 's/.*"\([^"]*\)"$/\1/')

    for id in $ids; do
        "$KCADM" update "$FLOW_PATH/executions" --config "$KCADM_CONFIG" -r "$REALM" \
            -n -s "id=$id" -s requirement=ALTERNATIVE >/dev/null 2>&1
    done

    echo "✅ [configure-idp] Flujo «$FLOW_ALIAS» creado"
}

use_auto_link() {
    local alias="$1"
    if "$KCADM" update "identity-provider/instances/${alias}" --config "$KCADM_CONFIG" \
        -r "$REALM" -s "firstBrokerLoginFlowAlias=$FLOW_ALIAS" 2>/dev/null; then
        echo "✅ [configure-idp] ${alias} vincula por correo sin preguntar"
    else
        echo "❌ [configure-idp] No se pudo asignar el flujo a ${alias}"
    fi
}

# ============================================================
# PERMISO DE INTERCAMBIO DE TOKENS
# ============================================================
# La app obtiene el token con el SDK nativo de Google o Apple y el BFF lo canjea
# por uno del realm. Habilitar el grant no basta: Keycloak exige además un
# permiso explícito por proveedor, o responde «Client not allowed to exchange».
#
# Se aplica en cada arranque porque el permiso vive en la base, no en el JSON del
# realm: un entorno nuevo lo importa sin permisos y el acceso social falla.
allow_token_exchange() {
    local alias="$1"

    local bff_uuid
    bff_uuid=$("$KCADM" get clients --config "$KCADM_CONFIG" -r "$REALM" \
        -q clientId=goveo-bff --fields id --format csv --noquotes 2>/dev/null | head -1)
    if [ -z "$bff_uuid" ]; then
        echo "❌ [configure-idp] No se encuentra goveo-bff; sin permiso de intercambio para $alias"
        return 1
    fi

    # Activa la gestión de permisos del proveedor y devuelve el id del permiso
    # de intercambio, que es al que hay que enganchar la política.
    local permission_id
    permission_id=$("$KCADM" update "identity-provider/instances/${alias}/management/permissions" \
        --config "$KCADM_CONFIG" -r "$REALM" -s enabled=true -o 2>/dev/null \
        | grep -o '"token-exchange"[[:space:]]*:[[:space:]]*"[^"]*"' | sed 's/.*"\([^"]*\)"$/\1/')
    if [ -z "$permission_id" ]; then
        echo "❌ [configure-idp] No se pudo habilitar permisos en el proveedor $alias"
        return 1
    fi

    local mgmt_uuid
    mgmt_uuid=$("$KCADM" get clients --config "$KCADM_CONFIG" -r "$REALM" \
        -q clientId=realm-management --fields id --format csv --noquotes 2>/dev/null | head -1)

    # La política dice «este cliente puede»; el permiso la enlaza con el
    # proveedor. Si ya existe se reutiliza: el script corre en cada arranque.
    local policy_name="allow-goveo-bff-exchange"
    local policy_id
    policy_id=$("$KCADM" get "clients/${mgmt_uuid}/authz/resource-server/policy/client" \
        --config "$KCADM_CONFIG" -r "$REALM" -q "name=${policy_name}" \
        --fields id --format csv --noquotes 2>/dev/null | head -1)

    if [ -z "$policy_id" ]; then
        "$KCADM" create "clients/${mgmt_uuid}/authz/resource-server/policy/client" \
            --config "$KCADM_CONFIG" -r "$REALM" \
            -s "name=${policy_name}" \
            -s "clients=[\"${bff_uuid}\"]" >/dev/null 2>&1

        # Se relee en vez de fiarse del id que devuelve `create`: no siempre lo
        # imprime, y con la política ya creada el script se quedaría a medias.
        policy_id=$("$KCADM" get "clients/${mgmt_uuid}/authz/resource-server/policy/client" \
            --config "$KCADM_CONFIG" -r "$REALM" -q "name=${policy_name}" \
            --fields id --format csv --noquotes 2>/dev/null | head -1)
    fi

    if [ -z "$policy_id" ]; then
        echo "❌ [configure-idp] No se pudo crear la política de intercambio"
        return 1
    fi

    if "$KCADM" update "clients/${mgmt_uuid}/authz/resource-server/permission/scope/${permission_id}" \
        --config "$KCADM_CONFIG" -r "$REALM" \
        -s "policies=[\"${policy_id}\"]" 2>/dev/null; then
        echo "✅ [configure-idp] goveo-bff puede intercambiar tokens de ${alias}"
    else
        echo "❌ [configure-idp] Error concediendo el intercambio para ${alias}"
    fi
}


ensure_auto_link_flow

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
        allow_token_exchange google
        use_auto_link google
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
        allow_token_exchange apple
        use_auto_link apple
    else
        echo "❌ [configure-idp] Error actualizando Apple IDP"
    fi
else
    echo "ℹ️  [configure-idp] APPLE_CLIENT_ID no configurado — Apple IDP permanece desactivado"
fi

echo "✅ [configure-idp] Configuración de Identity Providers finalizada"

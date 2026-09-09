#!/bin/bash
# Cliente y permisos del backoffice de Goveo.
#
# No va en goveo-realm.json a propósito: ese fichero sólo se lee en el primer
# arranque de un realm vacío, así que un cliente añadido ahí no llegaría nunca
# ni a local (ya arrancado), ni a demo, ni a producción. Este script corre en
# cada arranque y es idempotente, igual que configure-idp.sh.
#
# Usa kcadm.sh porque la imagen de Keycloak no trae curl ni python3.

KCADM="/opt/keycloak/bin/kcadm.sh"
KC_URL="http://localhost:8080"
KC_ADMIN_USER="${KC_BOOTSTRAP_ADMIN_USERNAME:-admin}"
KC_ADMIN_PASS="${KC_BOOTSTRAP_ADMIN_PASSWORD:-admin123}"
REALM="goveo"
KCADM_CONFIG="/tmp/kcadm-backoffice.config"

CLIENT_ID="goveo-backoffice"

# Orígenes del panel. Se pueden sustituir por entorno con GOVEO_BACKOFFICE_ORIGINS
# (separados por espacios); el de :5173 es el `vite dev` de la máquina de quien
# desarrolla.
ORIGINS="${GOVEO_BACKOFFICE_ORIGINS:-http://localhost:5173 https://admin.goveo.app https://admin-demo.goveo.app}"

echo "🔧 [backoffice] Configurando el cliente del panel..."

# --- Esperar a que Keycloak esté listo ---
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
        echo "❌ [backoffice] Timeout esperando a Keycloak"
        exit 1
    fi
    sleep 5
done

echo "✅ [backoffice] Keycloak listo"

# ============================================================
# NADIE SE REGISTRA
# ============================================================
# El registro es del realm, no del cliente: Keycloak no tiene interruptor por
# cliente. Se puede apagar entero porque la app no usa esa página —las altas van
# por la Admin API, en KeycloakService::registerUser—, y así el login del panel
# no enseña un enlace de «Registrarse» que nadie debe usar.
"$KCADM" update "realms/$REALM" --config "$KCADM_CONFIG" \
    -s registrationAllowed=false >/dev/null 2>&1 \
    && echo "✅ [backoffice] Registro público desactivado en el realm" \
    || echo "⚠️  [backoffice] No se pudo desactivar el registro del realm"

# ============================================================
# EL CLIENTE
# ============================================================
# Público con PKCE S256 y **sólo** el flujo estándar: sin Direct Access Grants,
# es decir, sin usuario y contraseña viajando por nuestro código. El panel manda
# a la pantalla de Keycloak y vuelve con el código, así que la fuerza bruta, la
# política de contraseñas, el reseteo y el segundo factor los pone Keycloak.
#
# fullScopeAllowed=false: en el token del panel sólo entran los roles que este
# cliente declara, no todos los del usuario. Sin esto, el token del backoffice
# arrastraría los roles de la app y al revés.
client_payload() {
    local redirects="" origins="" origin
    for origin in $ORIGINS; do
        redirects="${redirects}\"${origin}/*\","
        origins="${origins}\"${origin}\","
    done

    cat <<EOF
{
  "clientId": "$CLIENT_ID",
  "name": "Goveo Backoffice",
  "description": "Panel interno de Goveo. Acceso restringido al rol backoffice.access.",
  "enabled": true,
  "protocol": "openid-connect",
  "publicClient": true,
  "standardFlowEnabled": true,
  "implicitFlowEnabled": false,
  "directAccessGrantsEnabled": false,
  "serviceAccountsEnabled": false,
  "fullScopeAllowed": false,
  "redirectUris": [${redirects%,}],
  "webOrigins": [${origins%,}],
  "attributes": {
    "pkce.code.challenge.method": "S256",
    "post.logout.redirect.uris": "${ORIGINS// /\/*##}/*",
    "oauth2.device.authorization.grant.enabled": "false",
    "oidc.ciba.grant.enabled": "false",
    "use.refresh.tokens": "true"
  }
}
EOF
}

CLIENT_UUID=$("$KCADM" get clients --config "$KCADM_CONFIG" -r "$REALM" \
    -q "clientId=$CLIENT_ID" --fields id --format csv --noquotes 2>/dev/null | head -1)

if [ -z "$CLIENT_UUID" ]; then
    client_payload | "$KCADM" create clients --config "$KCADM_CONFIG" -r "$REALM" -f - >/dev/null 2>&1
    CLIENT_UUID=$("$KCADM" get clients --config "$KCADM_CONFIG" -r "$REALM" \
        -q "clientId=$CLIENT_ID" --fields id --format csv --noquotes 2>/dev/null | head -1)
    if [ -z "$CLIENT_UUID" ]; then
        echo "❌ [backoffice] No se pudo crear el cliente $CLIENT_ID"
        exit 1
    fi
    echo "✅ [backoffice] Cliente $CLIENT_ID creado"
else
    # Se reescribe en cada arranque: así un origen nuevo se aplica sin tocar el
    # panel de Keycloak a mano.
    client_payload | "$KCADM" update "clients/$CLIENT_UUID" --config "$KCADM_CONFIG" -r "$REALM" -f - >/dev/null 2>&1
    echo "ℹ️  [backoffice] Cliente $CLIENT_ID ya existía; configuración reaplicada"
fi

# ============================================================
# LOS PERMISOS
# ============================================================
# Roles **de cliente**, no de realm: los de realm viajan en el token de la app y
# acabarían contando la estructura interna del panel a cualquiera que mire su
# propio token.
#
# `backoffice.access` es la puerta: sin él no se entra a nada. El resto son
# permisos finos, uno por funcionalidad, con nombre `recurso.acción`. Se añaden
# a esta lista según se construyen; nunca un `admin` a secas, que es lo que
# luego no se puede desatar.
ensure_role() {
    local name="$1" description="$2"

    if "$KCADM" get "clients/$CLIENT_UUID/roles/$name" --config "$KCADM_CONFIG" -r "$REALM" >/dev/null 2>&1; then
        return 0
    fi

    "$KCADM" create "clients/$CLIENT_UUID/roles" --config "$KCADM_CONFIG" -r "$REALM" \
        -s "name=$name" -s "description=$description" >/dev/null 2>&1 \
        && echo "✅ [backoffice] Rol $name creado"
}

ensure_role "backoffice.access" "Entrar al panel. Sin este rol, ninguna pantalla."
ensure_role "business.verify"   "Validar y retirar la validación de negocios."

# ============================================================
# LOS PUESTOS
# ============================================================
# Un grupo por puesto, con sus roles dentro: se da de alta a alguien metiéndolo
# en el grupo, no marcando una lista de casillas. Un permiso suelto para una
# persona concreta se le asigna encima del grupo — Keycloak aplana grupo y
# directos en el mismo `resource_access`, así que la app no distingue ni falta.
ensure_group() {
    local name="$1"; shift

    if ! "$KCADM" get groups --config "$KCADM_CONFIG" -r "$REALM" \
        -q "search=$name" --fields name --format csv --noquotes 2>/dev/null | grep -qx "$name"; then
        "$KCADM" create groups --config "$KCADM_CONFIG" -r "$REALM" -s "name=$name" >/dev/null 2>&1 \
            && echo "✅ [backoffice] Grupo $name creado"
    fi

    local group_uuid
    group_uuid=$("$KCADM" get groups --config "$KCADM_CONFIG" -r "$REALM" \
        -q "search=$name" --fields id,name --format csv --noquotes 2>/dev/null \
        | grep ",$name$" | head -1 | cut -d, -f1)
    [ -z "$group_uuid" ] && return 1

    # add-roles es idempotente: repetir un rol ya asignado no da error.
    "$KCADM" add-roles --config "$KCADM_CONFIG" -r "$REALM" \
        --gid "$group_uuid" --cclientid "$CLIENT_ID" "$@" >/dev/null 2>&1
}

ensure_group "backoffice-admin" --rolename "backoffice.access" --rolename "business.verify"

# ============================================================
# USUARIO DE PRUEBA (sólo donde se pida)
# ============================================================
# Detrás de una variable a propósito: este script corre igual en demo y en
# producción, y una cuenta con contraseña conocida ahí sería justo el agujero
# que el resto del fichero intenta cerrar. El compose local la define; los de
# demo y producción, no.
if [ -n "$GOVEO_BACKOFFICE_DEV_USER" ]; then
    DEV_PASS="${GOVEO_BACKOFFICE_DEV_PASSWORD:-backoffice123}"

    USER_UUID=$("$KCADM" get users --config "$KCADM_CONFIG" -r "$REALM" \
        -q "email=$GOVEO_BACKOFFICE_DEV_USER" --fields id --format csv --noquotes 2>/dev/null | head -1)

    if [ -z "$USER_UUID" ]; then
        "$KCADM" create users --config "$KCADM_CONFIG" -r "$REALM" \
            -s "username=$GOVEO_BACKOFFICE_DEV_USER" \
            -s "email=$GOVEO_BACKOFFICE_DEV_USER" \
            -s "firstName=Goveo" -s "lastName=Backoffice" \
            -s enabled=true -s emailVerified=true >/dev/null 2>&1
        USER_UUID=$("$KCADM" get users --config "$KCADM_CONFIG" -r "$REALM" \
            -q "email=$GOVEO_BACKOFFICE_DEV_USER" --fields id --format csv --noquotes 2>/dev/null | head -1)
        echo "✅ [backoffice] Usuario de prueba $GOVEO_BACKOFFICE_DEV_USER creado"
    fi

    if [ -n "$USER_UUID" ]; then
        "$KCADM" set-password --config "$KCADM_CONFIG" -r "$REALM" \
            --userid "$USER_UUID" --new-password "$DEV_PASS" >/dev/null 2>&1

        GROUP_UUID=$("$KCADM" get groups --config "$KCADM_CONFIG" -r "$REALM" \
            -q "search=backoffice-admin" --fields id,name --format csv --noquotes 2>/dev/null \
            | grep ",backoffice-admin$" | head -1 | cut -d, -f1)
        # `-n` y los tres campos: el endpoint de unir a un grupo no devuelve
        # cuerpo, y sin ellos kcadm intenta releer el recurso antes de escribir
        # y falla.
        [ -n "$GROUP_UUID" ] && "$KCADM" update "users/$USER_UUID/groups/$GROUP_UUID" \
            --config "$KCADM_CONFIG" -r "$REALM" \
            -s "realm=$REALM" -s "userId=$USER_UUID" -s "groupId=$GROUP_UUID" \
            -n >/dev/null 2>&1

        echo "✅ [backoffice] $GOVEO_BACKOFFICE_DEV_USER listo en backoffice-admin (contraseña: $DEV_PASS)"
    else
        echo "❌ [backoffice] No se pudo preparar el usuario de prueba"
    fi
fi

echo "🎉 [backoffice] Configuración del panel terminada"

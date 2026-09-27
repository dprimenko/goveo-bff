/**
 * Cambiar de cuenta sin pasar por «Vuelva a autenticar».
 *
 * Las aplicaciones piden `prompt=login` para no entrar solas con la sesión de
 * otra (la web y el panel comparten realm). Pero con una sesión de Keycloak
 * abierta, eso no da un formulario limpio: da «Vuelva a autenticar» con la
 * cuenta anterior fija, y escribir otra acaba en «ya has iniciado sesión como
 * otro usuario». Para cambiar de cuenta había que cerrar sesión a mano.
 *
 * Así que la pantalla de entrar, al ver esa sesión, la cierra ella misma:
 *
 * 1. Guarda la petición de login en curso (su URL) y va al cierre de sesión.
 * 2. La confirmación de cierre se envía sola, **sólo** si la ha pedido esto:
 *    un enlace de fuera no puede cerrar la sesión de nadie sin preguntar.
 * 3. Tras cerrar, vuelve a la petición guardada, que ya sale limpia.
 *
 * Sin `post_logout_redirect_uri`: la vuelta la hace el tema, así que no hay que
 * dar de alta direcciones en cada cliente. Todo va en `sessionStorage` del
 * propio Keycloak, que es el mismo origen en los tres pasos.
 */

const KEY = 'goveo.switchAccount'
/** Más que esto entre el paso 1 y el 3 ya no es un cambio de cuenta en curso. */
const WINDOW_MS = 60_000

interface Pending {
    /** La petición de login a la que volver. */
    returnTo: string
    at: number
}

function read(): Pending | null {
    try {
        const raw = sessionStorage.getItem(KEY)
        const pending = raw ? (JSON.parse(raw) as Pending) : null
        return pending && Date.now() - pending.at < WINDOW_MS ? pending : null
    } catch {
        return null
    }
}

/**
 * Paso 1. Devuelve `false` si no procede (ya se intentó hace nada y la sesión
 * sigue ahí: mejor enseñar la pantalla que dar vueltas).
 */
export function startSwitch(clientId: string): boolean {
    // Sólo desde la petición de login de verdad (`…/openid-connect/auth`): es la
    // única URL que se puede volver a abrir tal cual.
    if (!/\/protocol\/openid-connect\/auth$/.test(window.location.pathname)) return false
    if (read()) return false
    try {
        sessionStorage.setItem(KEY, JSON.stringify({ returnTo: window.location.href, at: Date.now() }))
    } catch {
        return false
    }
    const logout = window.location.pathname.replace(/\/auth$/, '/logout')
    window.location.replace(`${logout}?client_id=${encodeURIComponent(clientId)}`)
    return true
}

/** Paso 2: si el cierre lo ha pedido `startSwitch`. */
export function isSwitching(): boolean {
    return read() !== null
}

/** Paso 3: vuelve a la petición guardada. `false` si no había ninguna. */
export function finishSwitch(): boolean {
    const pending = read()
    if (!pending) return false
    try {
        sessionStorage.removeItem(KEY)
    } catch {
        // Se vuelve igual.
    }
    window.location.replace(pending.returnTo)
    return true
}

import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { KcPage } from './kc.gen'

/**
 * Sólo para el `vite dev` del tema. En Keycloak, el HTML lo sirve él y este
 * fichero no llega a ejecutarse tal cual: la página recibe su `kcContext`
 * inyectado en el `<head>`.
 */
createRoot(document.getElementById('root')!).render(
    <StrictMode>
        {!window.kcContext ? (
            <h1>Sin contexto de Keycloak</h1>
        ) : (
            <KcPage kcContext={window.kcContext} />
        )}
    </StrictMode>
)

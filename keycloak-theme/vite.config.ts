import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import { keycloakify } from 'keycloakify/vite-plugin'
import { buildEmailTheme } from 'keycloakify-emails'
import path from 'node:path'

/**
 * El tema de Keycloak de Goveo: las pantallas de login y los correos que manda
 * Keycloak por su cuenta.
 *
 * Los correos se generan en el `postBuild`: se escriben en React
 * (`src/email/templates`) y `keycloakify-emails` los convierte en las plantillas
 * FreeMarker que Keycloak espera, en los dos idiomas y en HTML y texto plano.
 * Escribirlos a mano en `.ftl` era la alternativa, y significaba mantener cuatro
 * ficheros por correo —html y texto, por idioma— que nadie puede previsualizar.
 */
export default defineConfig({
    plugins: [
        react(),
        keycloakify({
            accountThemeImplementation: 'none',
            themeName: ['goveo'],
            startKeycloakOptions: {
                dockerImage: 'quay.io/keycloak/keycloak:26.0',
                realmJsonFilePath: '../docker/keycloak/goveo-realm.json',
                port: 8083,
            },
            postBuild: async (buildContext) => {
                await buildEmailTheme({
                    templatesSrcDirPath: path.join(
                        buildContext.themeSrcDirPath,
                        'email',
                        'templates'
                    ),
                    i18nSourceFile: path.join(
                        buildContext.themeSrcDirPath,
                        'email',
                        'i18n.ts'
                    ),
                    themeNames: buildContext.themeNames,
                    keycloakifyBuildDirPath: buildContext.keycloakifyBuildDirPath,
                    locales: ['es', 'en'],
                    cwd: import.meta.dirname,
                    environmentVariables: buildContext.environmentVariables,
                    esbuild: {},
                })
            },
        }),
    ],
})

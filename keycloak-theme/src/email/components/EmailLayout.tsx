// eslint-disable-next-line react-refresh/only-export-components
import { Body, Container, Head, Html, Preview, Section, Text } from 'jsx-email'
import type { Translations } from '../i18n.ts'

/**
 * La caja de todos los correos de Keycloak.
 *
 * Es el mismo diseño que el correo que manda el BFF (`GoveoEmailLayout` en PHP):
 * cabecera negra con la marca, filete naranja, cuerpo blanco y pie con el
 * contacto. Dos remitentes —nuestra API y Keycloak— no pueden verse como dos
 * empresas distintas; y menos en el correo que cambia una contraseña, que es
 * justo donde alguien que sospecha un fraude hace bien en desconfiar.
 *
 * **Sin imágenes, la marca es texto.** Un logotipo remoto se queda en un hueco
 * gris en cuanto el cliente bloquea las imágenes, que es lo que hace Gmail con
 * un remitente nuevo — y estos correos los recibe gente que aún no nos conoce.
 */
const INK = '#111319'
const ACCENT = '#FF6744'
const CANVAS = '#F2F3F5'
const TEXT = '#374151'
const MUTED = '#6B7280'

interface EmailLayoutProps {
    preview: string
    lang: string
    children: React.ReactNode
    footer: Translations['footer']
}

export function EmailLayout({ preview, lang, children, footer }: EmailLayoutProps) {
    return (
        <Html lang={lang}>
            <Head />
            <Preview>{preview}</Preview>
            <Body style={styles.body}>
                <Container style={styles.container}>
                    <Section style={styles.header}>
                        <Text style={styles.wordmark}>GOVEO</Text>
                        <Section style={styles.rule} />
                        <Text style={styles.tagline}>La Vídeo Smart City de Madrid</Text>
                    </Section>

                    <Section style={styles.panel}>{children}</Section>
                </Container>

                <Container style={styles.footer}>
                    <Text style={styles.footerText}>{footer.receivingBecause}</Text>
                    <Text style={styles.footerText}>
                        hola@goveo.app &nbsp;·&nbsp; 605 820 948 &nbsp;·&nbsp; goveo.app
                    </Text>
                    <Text style={styles.copyright}>
                        {footer.copyright(new Date().getFullYear())}
                    </Text>
                </Container>
            </Body>
        </Html>
    )
}

export const styles = {
    body: {
        backgroundColor: CANVAS,
        fontFamily: 'Arial, Helvetica, sans-serif',
        margin: '0',
        padding: '24px 12px',
    },
    container: {
        margin: '0 auto',
        maxWidth: '600px',
        borderRadius: '12px',
        overflow: 'hidden' as const,
    },
    header: {
        backgroundColor: INK,
        padding: '26px 24px',
        textAlign: 'center' as const,
    },
    wordmark: {
        color: '#FFFFFF',
        fontSize: '34px',
        fontWeight: 'bold',
        letterSpacing: '3px',
        lineHeight: '1',
        margin: '0',
    },
    rule: {
        backgroundColor: ACCENT,
        height: '3px',
        width: '56px',
        margin: '12px auto 0',
    },
    tagline: {
        color: '#9CA3AF',
        fontSize: '13px',
        letterSpacing: '0.4px',
        margin: '12px 0 0',
    },
    panel: {
        backgroundColor: '#FFFFFF',
        padding: '32px 40px',
    },
    footer: {
        margin: '0 auto',
        maxWidth: '600px',
        padding: '20px 24px',
        textAlign: 'center' as const,
    },
    footerText: {
        color: MUTED,
        fontSize: '13px',
        lineHeight: '20px',
        margin: '0 0 6px',
    },
    copyright: {
        color: '#9CA3AF',
        fontSize: '12px',
        margin: '0',
    },
    /** Lo que comparten los cuerpos de las plantillas. */
    heading: {
        color: INK,
        fontSize: '22px',
        fontWeight: 'bold',
        lineHeight: '1.35',
        margin: '0 0 16px',
    },
    text: {
        color: TEXT,
        fontSize: '15px',
        lineHeight: '1.6',
        margin: '0 0 16px',
    },
    fineprint: {
        color: MUTED,
        fontSize: '13px',
        lineHeight: '1.5',
        margin: '0 0 16px',
    },
    buttonSection: {
        margin: '8px 0 24px',
        textAlign: 'center' as const,
    },
} as const

/* eslint-disable react-refresh/only-export-components */
/**
 * «He olvidado mi contraseña» pedido desde la pantalla de Keycloak.
 *
 * Es la que saldrá cuando el login de la web pase por aquí. Hoy la app usa su
 * propia pantalla y el BFF dispara `executeActions`, que dice lo mismo.
 */
import { Heading, Section, Text } from 'jsx-email'
import { render, renderPlainText } from 'jsx-email'
import { type GetSubject, type GetTemplate } from 'keycloakify-emails'
import { createVariablesHelper } from 'keycloakify-emails/variables'
import { EmailLayout, styles } from '../components/EmailLayout.tsx'
import { Button } from '../components/Button.tsx'
import { getTranslations } from '../i18n.ts'

const { exp } = createVariablesHelper('password-reset.ftl')

function PasswordReset({ locale }: { locale: string }) {
    const t = getTranslations(locale)
    const tv = t.passwordReset

    return (
        <EmailLayout preview={tv.preview} lang={locale} footer={t.footer}>
            <Heading style={styles.heading}>{tv.heading}</Heading>
            <Text style={styles.text}>{tv.greeting}</Text>
            <Text style={styles.text}>{tv.body}</Text>
            <Section style={styles.buttonSection}>
                <Button href={exp('link')}>{tv.cta}</Button>
            </Section>
            <Text style={styles.fineprint}>
                {tv.expiry} {exp('linkExpirationFormatter(linkExpiration)')}.
            </Text>
            <Text style={styles.text}>{tv.ignore}</Text>
        </EmailLayout>
    )
}

export const getTemplate: GetTemplate = async ({ locale, plainText }) => {
    const component = <PasswordReset locale={locale} />

    return plainText ? renderPlainText(component) : render(component)
}

export const getSubject: GetSubject = async ({ locale }) => getTranslations(locale).passwordReset.subject

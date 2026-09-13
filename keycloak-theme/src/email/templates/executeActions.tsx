/* eslint-disable react-refresh/only-export-components */
/**
 * El correo que dispara el BFF cuando alguien pide recuperar su contraseña desde
 * la app: la app no usa la pantalla de login de Keycloak, así que pide el correo
 * por su cuenta y el BFF lanza la acción `UPDATE_PASSWORD` por la Admin API.
 *
 * Dice lo mismo que `password-reset` a propósito: para quien lo recibe es el
 * mismo suceso, y que el texto dependa de por dónde se pidió sería ruido.
 */
import { Heading, Section, Text } from 'jsx-email'
import { render, renderPlainText } from 'jsx-email'
import { type GetSubject, type GetTemplate } from 'keycloakify-emails'
import { createVariablesHelper } from 'keycloakify-emails/variables'
import { EmailLayout, styles } from '../components/EmailLayout.tsx'
import { Button } from '../components/Button.tsx'
import { getTranslations } from '../i18n.ts'

const { exp } = createVariablesHelper('executeActions.ftl')

function ExecuteActions({ locale }: { locale: string }) {
    const t = getTranslations(locale)
    const tv = t.executeActions

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
    const component = <ExecuteActions locale={locale} />

    return plainText ? renderPlainText(component) : render(component)
}

export const getSubject: GetSubject = async ({ locale }) => getTranslations(locale).executeActions.subject

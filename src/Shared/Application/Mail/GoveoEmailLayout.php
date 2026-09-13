<?php

declare(strict_types=1);

namespace App\Shared\Application\Mail;

/**
 * La plantilla de todos los correos que recibe un cliente.
 *
 * Vive aquí y no repartida por cada mailer porque lo que cambia de un correo a
 * otro es el texto, no la caja: cabecera negra con la marca, cuerpo blanco,
 * un botón naranja y el pie con el contacto. Cuando eso estaba escrito dentro
 * de cada correo, el de bienvenida quedó en oscuro y el resto en claro, y dos
 * correos seguidos del mismo remitente parecían de sitios distintos.
 *
 * **Tablas y estilos en línea**, que es lo único que respetan los clientes de
 * correo: ni hojas de estilo, ni flex, ni grid. Los dos únicos selectores del
 * `<style>` son la caída a móvil, y si un cliente los ignora el correo se ve
 * igual —sólo con más margen—.
 *
 * No hay imágenes: la marca es texto. Un logotipo remoto se queda en un hueco
 * gris en cuanto el cliente bloquea las imágenes, que es lo que hace Gmail por
 * defecto con un remitente nuevo.
 */
final class GoveoEmailLayout
{
    /** Naranja de marca (design system: `colors.primary`). */
    public const ACCENT = '#FF6744';

    private const INK = '#111319';
    private const TEXT = '#374151';
    private const MUTED = '#6B7280';
    private const CANVAS = '#F2F3F5';
    private const PANEL = '#F5F7FA';

    public const TAGLINE = 'La Vídeo Smart City de Madrid';
    public const SUPPORT_PHONE = '605 820 948';
    public const SUPPORT_EMAIL = 'hola@goveo.app';

    /**
     * @param string $preheader Lo que se lee junto al asunto en la bandeja. Va
     *                          oculto en el cuerpo: sin él, el cliente de
     *                          correo rellena ese hueco con el texto de la
     *                          cabecera, que no dice nada.
     * @param string $bodyHtml  Bloques ya montados con los ayudantes de abajo.
     */
    public static function render(string $preheader, string $title, string $heading, string $bodyHtml): string
    {
        $preheader = self::esc($preheader);
        $title     = self::esc($title);
        $year      = date('Y');
        $accent    = self::ACCENT;
        $ink       = self::INK;
        $muted     = self::MUTED;
        $canvas    = self::CANVAS;
        $tagline   = self::TAGLINE;
        $phone     = self::SUPPORT_PHONE;
        $mail      = self::SUPPORT_EMAIL;

        return <<<HTML
        <!DOCTYPE html>
        <html lang="es">
        <head>
          <meta charset="utf-8">
          <meta name="viewport" content="width=device-width, initial-scale=1.0">
          <meta http-equiv="X-UA-Compatible" content="IE=edge">
          <title>{$title}</title>
          <style>
            body, table, td { font-family: Arial, Helvetica, sans-serif; }
            img { border: 0; outline: none; text-decoration: none; }
            a { color: {$accent}; }
            @media only screen and (max-width: 620px) {
              .container { width: 100% !important; }
              .px { padding-left: 20px !important; padding-right: 20px !important; }
            }
          </style>
        </head>
        <body style="margin:0; padding:0; background-color:{$canvas};">

          <div style="display:none; max-height:0; overflow:hidden; mso-hide:all;">{$preheader}</div>

          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:{$canvas};">
            <tr>
              <td align="center" style="padding:24px 12px;">

                <table role="presentation" class="container" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px; max-width:600px;">

                  <tr>
                    <td align="center" style="background-color:{$ink}; border-radius:12px 12px 0 0; padding:26px 24px;">
                      <p style="margin:0; font-size:34px; line-height:1; font-weight:bold; letter-spacing:3px; color:#FFFFFF;">GOVEO</p>
                      <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:12px auto 0;">
                        <tr><td style="width:56px; height:3px; background-color:{$accent}; font-size:0; line-height:0;">&nbsp;</td></tr>
                      </table>
                      <p style="margin:12px 0 0; color:#9CA3AF; font-size:13px; letter-spacing:0.4px;">{$tagline}</p>
                    </td>
                  </tr>

                  <tr>
                    <td class="px" style="background-color:#FFFFFF; border-radius:0 0 12px 12px; padding:32px 40px;">
                      <h1 style="margin:0 0 16px; font-size:22px; line-height:1.35; color:{$ink};">{$heading}</h1>
                      {$bodyHtml}
                    </td>
                  </tr>

                  <tr>
                    <td align="center" style="padding:20px 24px;">
                      <p style="margin:0 0 6px; font-size:13px; line-height:1.5; color:{$muted};">
                        <a href="mailto:{$mail}" style="color:{$muted}; text-decoration:underline;">{$mail}</a>
                        &nbsp;·&nbsp; {$phone}
                        &nbsp;·&nbsp; <a href="https://goveo.app" style="color:{$muted}; text-decoration:underline;">goveo.app</a>
                      </p>
                      <p style="margin:0; font-size:12px; color:#9CA3AF;">
                        © {$year} Goveo · Globaly Digital Emotion, S.L. · Madrid
                      </p>
                    </td>
                  </tr>

                </table>

              </td>
            </tr>
          </table>

        </body>
        </html>
        HTML;
    }

    /** Párrafo del cuerpo. El HTML que se le pasa ya viene escapado. */
    public static function paragraph(string $html, string $margin = '0 0 16px'): string
    {
        $text = self::TEXT;

        return <<<HTML
        <p style="margin:{$margin}; font-size:15px; line-height:1.6; color:{$text};">
          {$html}
        </p>
        HTML;
    }

    /**
     * Caja gris con la lista de cosas que hacer. Es una tabla y no un `<ul>`:
     * Outlook le pone al `<ul>` sus propios márgenes y el emoji de cada línea
     * se sumaría a la viñeta.
     *
     * @param string[] $itemsHtml Cada línea, ya escapada.
     */
    public static function card(array $itemsHtml): string
    {
        $panel = self::PANEL;
        $text  = self::TEXT;
        $last  = count($itemsHtml) - 1;

        $rows = '';
        foreach (array_values($itemsHtml) as $i => $item) {
            $margin = $i === $last ? '0' : '0 0 12px';
            $rows .= <<<HTML
            <p style="margin:{$margin}; font-size:15px; line-height:1.6; color:{$text};">
              {$item}
            </p>
            HTML;
        }

        return <<<HTML
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:{$panel}; border-radius:10px; margin:0 0 16px;">
          <tr><td style="padding:20px 24px;">{$rows}</td></tr>
        </table>
        HTML;
    }

    /** Caja destacada con filete naranja, para lo que no es una acción. */
    public static function highlight(string $html): string
    {
        $accent = self::ACCENT;
        $text   = self::TEXT;

        return <<<HTML
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-left:3px solid {$accent}; background-color:#FFF4F0; border-radius:0 10px 10px 0; margin:0 0 16px;">
          <tr><td style="padding:16px 20px;">
            <p style="margin:0; font-size:15px; line-height:1.6; color:{$text};">{$html}</p>
          </td></tr>
        </table>
        HTML;
    }

    /**
     * El botón. **Uno por correo**: competir con otro enlace sólo baja la
     * probabilidad de que se pulse el que importa.
     */
    public static function button(string $label, string $url): string
    {
        $label  = self::esc($label);
        $href   = self::esc($url);
        $accent = self::ACCENT;

        return <<<HTML
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:8px auto 24px;">
          <tr>
            <td align="center" style="border-radius:8px; background-color:{$accent};">
              <a href="{$href}" target="_blank" style="display:inline-block; padding:14px 36px; font-size:15px; font-weight:bold; color:#FFFFFF; text-decoration:none; border-radius:8px;">{$label}</a>
            </td>
          </tr>
        </table>
        HTML;
    }

    /** Letra pequeña bajo el botón (caducidad de un enlace, avisos). */
    public static function fineprint(string $html): string
    {
        $muted = self::MUTED;

        return <<<HTML
        <p style="margin:0 0 16px; font-size:13px; line-height:1.5; color:{$muted};">{$html}</p>
        HTML;
    }

    /** Despedida. Va siempre, y siempre igual: el correo no acaba en un botón. */
    public static function signoff(?string $lead = null): string
    {
        $lead = $lead === null ? '' : self::esc($lead) . '<br><br>';

        return self::paragraph($lead . 'Un saludo,<br><br><strong>Equipo GOVEO</strong>', '24px 0 0');
    }

    public static function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    /** `<strong>` sobre texto que viene de la base de datos. */
    public static function strong(string $text): string
    {
        return '<strong>' . self::esc($text) . '</strong>';
    }
}

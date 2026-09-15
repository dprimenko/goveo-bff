<?php

declare(strict_types=1);

namespace App\Tests\Backoffice;

use App\Backoffice\Application\ReviewDecisionMessages;
use PHPUnit\Framework\TestCase;

/**
 * Los seis correos de la cola de revisión.
 *
 * Lo que se comprueba aquí no es la maquetación —eso se mira en Mailpit con
 * `goveo:mail:preview`— sino lo que no puede cambiar sin que alguien lo decida:
 *
 *  - **El asunto**, que viene dado y es lo único que se ve antes de abrir.
 *  - **Que un negativo no lleva botón.** No hay nada que pulsar, y poner uno
 *    ahí lo convierte en un formulario de reclamación.
 *  - **Que un positivo lleva uno y sólo uno**, al sitio que toca.
 *  - **Que el nombre del negocio va escapado**: sale de un formulario público, y
 *    hay negocios que se llaman «Casa Pepe & Hijos».
 */
final class ReviewDecisionMessagesTest extends TestCase
{
    private const APP   = 'https://links.goveo.app/abc';
    private const VIDEO = 'https://goveo.app/ol/1234';

    public function testTheSubjectsAreTheOnesAgreedWith(): void
    {
        self::assertSame(
            '✅ Tu alta en GOVEO está aprobada',
            ReviewDecisionMessages::businessApproved('Bar Manolo', self::APP)->subject,
        );
        self::assertSame(
            'Sobre tu solicitud de alta en GOVEO',
            ReviewDecisionMessages::businessRejected('Bar Manolo')->subject,
        );
        self::assertSame(
            '🎬 Tu vídeo ha sido aprobado en GOVEO',
            ReviewDecisionMessages::videoApproved('Un vídeo', self::VIDEO)->subject,
        );
        self::assertSame(
            'Tu vídeo en GOVEO necesita un ajuste',
            ReviewDecisionMessages::videoRejected('Un vídeo')->subject,
        );
        self::assertSame(
            '✅ Tu cuenta de publisher en GOVEO está aprobada',
            ReviewDecisionMessages::publisherApproved('Ana', self::APP)->subject,
        );
        self::assertSame(
            'Sobre tu solicitud de publisher en GOVEO',
            ReviewDecisionMessages::publisherRejected('Ana')->subject,
        );
    }

    public function testAnApprovalCarriesOneButtonToWhereItSays(): void
    {
        $business = ReviewDecisionMessages::businessApproved('Bar Manolo', self::APP);

        self::assertSame(1, substr_count($business->html, 'Ir a mi cuenta'));
        self::assertStringContainsString('href="' . self::APP . '"', $business->html);
        // El enlace abre la app: quien lo lea en el ordenador tiene que saberlo
        // antes de pulsar, y por eso el aviso va dentro del propio botón.
        self::assertStringContainsString('(Abrir en el móvil)', $business->html);

        // El del vídeo lleva al vídeo publicado, no al perfil: es lo que se
        // quiere comprobar y lo que se va a compartir después.
        $video = ReviewDecisionMessages::videoApproved('Un vídeo', self::VIDEO);

        self::assertStringContainsString('href="' . self::VIDEO . '"', $video->html);
        self::assertStringContainsString(self::VIDEO, $video->text);
    }

    public function testARejectionHasNoButtonAndSaysHowToTryAgain(): void
    {
        foreach ([
            ReviewDecisionMessages::businessRejected('Bar Manolo'),
            ReviewDecisionMessages::videoRejected('Un vídeo'),
            ReviewDecisionMessages::publisherRejected('Ana'),
        ] as $mail) {
            // Ni botón (el enlace del pie a goveo.app lo llevan todos).
            self::assertStringNotContainsString('padding:14px 36px', $mail->html);
            // El teléfono de soporte hace de salida: es lo que evita que el «no»
            // acabe en una respuesta al correo sin leer.
            self::assertStringContainsString('605 820 948', $mail->html);
            self::assertStringContainsString('605 820 948', $mail->text);
        }
    }

    public function testTheNameIsEscapedBecauseItComesFromAForm(): void
    {
        $mail = ReviewDecisionMessages::businessApproved('Casa Pepe & <b>Hijos</b>', self::APP);

        self::assertStringContainsString('Casa Pepe &amp; &lt;b&gt;Hijos&lt;/b&gt;', $mail->html);
        self::assertStringNotContainsString('<b>Hijos</b>', $mail->html);
    }

    public function testAVideoWithoutATitleIsStillNamedSomehow(): void
    {
        // Los vídeos importados no tienen título, y «Tu vídeo «»» no es correo.
        $mail = ReviewDecisionMessages::videoRejected(null);

        self::assertStringContainsString('el vídeo que subiste', $mail->html);
        self::assertStringNotContainsString('«»', $mail->text);
    }

    public function testAPublisherWithoutANameIsGreetedAnyway(): void
    {
        $mail = ReviewDecisionMessages::publisherApproved(null, self::APP);

        self::assertStringContainsString('Hola,', $mail->html);
        self::assertStringNotContainsString('Hola ,', $mail->html);
    }

    public function testEveryMailGoesOutInBothParts(): void
    {
        // Sin la versión en texto, los clientes que no pintan HTML ven un
        // mensaje vacío y los filtros puntúan peor el correo.
        foreach ([
            ReviewDecisionMessages::businessApproved('Bar Manolo', self::APP),
            ReviewDecisionMessages::businessRejected('Bar Manolo'),
            ReviewDecisionMessages::videoApproved('Un vídeo', self::VIDEO),
            ReviewDecisionMessages::videoRejected('Un vídeo'),
            ReviewDecisionMessages::publisherApproved('Ana', self::APP),
            ReviewDecisionMessages::publisherRejected('Ana'),
        ] as $mail) {
            self::assertNotSame('', trim($mail->text));
            self::assertStringContainsString('Equipo GOVEO', $mail->text);
            self::assertStringContainsString('<!DOCTYPE html>', $mail->html);
            self::assertStringContainsString('GOVEO', $mail->html);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Backoffice;

use App\Backoffice\Application\ReviewDecisionMailer;
use App\Backoffice\Infrastructure\Controller\ReviewBusinessController;
use App\Backoffice\Infrastructure\Controller\ReviewGeoStoryController;
use App\Business\Application\BusinessArchiver;
use App\Business\Domain\Business;
use App\Business\Domain\BusinessRepository;
use App\GeoStories\Domain\GeoStory;
use App\GeoStories\Domain\GeoStoryRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * **Una decisión, un correo.**
 *
 * Las llamadas de la cola son `PUT` y por tanto idempotentes a propósito:
 * aprobar lo ya aprobado deja el negocio aprobado, y eso está bien. Lo que no
 * puede repetirse es el correo — decirle a alguien dos veces que su vídeo
 * «necesita un ajuste» es decirle que ha fallado dos veces.
 *
 * El caso difícil es el vídeo rechazado: **se queda donde estaba** («sin
 * validar» es el estado del que venía), así que la cola lo sigue enseñando y el
 * botón se puede volver a pulsar sin que nada haya cambiado. Por eso el aviso se
 * apunta en su `meta`, y aprobar lo borra para que un rechazo posterior —quien
 * revisa se desdice— vuelva a avisar.
 */
final class ReviewDecisionOnlyOnceTest extends TestCase
{
    public function testApprovingABusinessTwiceWritesOnce(): void
    {
        $mails      = new RecordingMailer();
        $business   = $this->business();
        $controller = new ReviewBusinessController(
            $this->businesses($business),
            // El archivador no participa en aprobar ni rechazar; se construye
            // de verdad porque es `final` y no se puede doblar.
            new BusinessArchiver($this->businesses($business), $this->createStub(Connection::class)),
            $this->mailer($mails),
        );

        $controller->approve('negocio-1');
        $controller->approve('negocio-1');

        self::assertSame(['✅ Tu alta en GOVEO está aprobada'], $mails->subjects());
    }

    public function testChangingOnesMindWritesAgain(): void
    {
        // Aprobar y luego rechazar son dos decisiones distintas, y las dos hay
        // que contarlas: el negocio estuvo publicado y deja de estarlo.
        $mails      = new RecordingMailer();
        $business   = $this->business();
        $controller = new ReviewBusinessController(
            $this->businesses($business),
            // El archivador no participa en aprobar ni rechazar; se construye
            // de verdad porque es `final` y no se puede doblar.
            new BusinessArchiver($this->businesses($business), $this->createStub(Connection::class)),
            $this->mailer($mails),
        );

        $controller->approve('negocio-1');
        $controller->reject('negocio-1');
        $controller->reject('negocio-1');

        self::assertSame(
            ['✅ Tu alta en GOVEO está aprobada', 'Sobre tu solicitud de alta en GOVEO'],
            $mails->subjects(),
        );
    }

    public function testRejectingAVideoTwiceWritesOnce(): void
    {
        $mails      = new RecordingMailer();
        $story      = $this->story();
        $controller = new ReviewGeoStoryController($this->stories($story), $this->mailer($mails));

        $controller->reject('video-1');
        $controller->reject('video-1');

        self::assertSame(['Tu vídeo en GOVEO necesita un ajuste'], $mails->subjects());
        self::assertTrue($story->rejectionNoticeSent());
    }

    /**
     * Retirar lo ya publicado **no avisa de nada**.
     *
     * Es la misma llamada que rechazar —quita la validación y aparta el vídeo—
     * pero no es la misma decisión: el vídeo se aprobó, se publicó y se quita
     * después, muchas veces a petición de su propio dueño. Mandarle entonces
     * «tu vídeo no ha pasado la selección» le cuenta algo que no ha ocurrido.
     */
    public function testRetiringAPublishedVideoSaysNothing(): void
    {
        $mails      = new RecordingMailer();
        $story      = $this->story();
        $controller = new ReviewGeoStoryController($this->stories($story), $this->mailer($mails));

        $controller->reject('video-1');
        $controller->approve('video-1');
        $controller->reject('video-1');

        self::assertSame(
            [
                'Tu vídeo en GOVEO necesita un ajuste',
                '🎬 Tu vídeo ha sido aprobado en GOVEO',
            ],
            $mails->subjects(),
        );
    }

    /**
     * Y la retirada tampoco gasta el aviso: si el vídeo vuelve y **esa** vez se
     * rechaza de verdad —sin haber llegado a publicarse—, su dueño se entera.
     */
    public function testAfterARetirementARealRejectionStillSpeaksUp(): void
    {
        $mails      = new RecordingMailer();
        $story      = $this->story();
        $controller = new ReviewGeoStoryController($this->stories($story), $this->mailer($mails));

        $controller->approve('video-1');
        // Retirada: estaba publicado, así que calla.
        $controller->reject('video-1');
        // Y ahora sí es una decisión sobre si entra.
        $controller->reject('video-1');

        self::assertSame(
            [
                '🎬 Tu vídeo ha sido aprobado en GOVEO',
                'Tu vídeo en GOVEO necesita un ajuste',
            ],
            $mails->subjects(),
        );
    }

    public function testApprovingAVideoTwiceWritesOnce(): void
    {
        $mails      = new RecordingMailer();
        $story      = $this->story();
        $controller = new ReviewGeoStoryController($this->stories($story), $this->mailer($mails));

        $controller->approve('video-1');
        $controller->approve('video-1');

        self::assertSame(['🎬 Tu vídeo ha sido aprobado en GOVEO'], $mails->subjects());
    }

    public function testRejectingAlsoTakesItOutOfTheQueue(): void
    {
        // Rechazar y archivar son una sola cosa para quien revisa. Separadas,
        // lo rechazado se quedaba en «pendientes» —«sin validar» es de donde
        // venía— y volvía a aparecer en cada repaso.
        $mails      = new RecordingMailer();
        $business   = $this->business();
        $controller = new ReviewBusinessController(
            $this->businesses($business),
            new BusinessArchiver($this->businesses($business), $this->createStub(Connection::class)),
            $this->mailer($mails),
        );

        $controller->reject('negocio-1');

        self::assertNotNull($business->getRejectedAt());
        self::assertTrue($business->isDeleted());
        self::assertSame(['Sobre tu solicitud de alta en GOVEO'], $mails->subjects());
    }

    public function testRejectingAVideoAlsoTakesItOutOfTheQueueAndApprovingBringsItBack(): void
    {
        $mails      = new RecordingMailer();
        $story      = $this->story();
        $controller = new ReviewGeoStoryController($this->stories($story), $this->mailer($mails));

        $controller->reject('video-1');

        self::assertTrue($story->isDeleted());
        self::assertFalse($story->isVerified());

        // Y aprobarlo después lo devuelve: validado y apartado a la vez sería
        // aprobarlo a medias — se vería en ninguna parte.
        $controller->approve('video-1');

        self::assertFalse($story->isDeleted());
        self::assertTrue($story->isVerified());
    }

    private function mailer(RecordingMailer $mails): ReviewDecisionMailer
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn('manolo@ejemplo.com');

        return new ReviewDecisionMailer(
            $mails,
            $connection,
            new NullLogger(),
            'hola@goveo.app',
            'https://goveo.app',
            'https://links.goveo.app/abc',
        );
    }

    private function businesses(Business $business): BusinessRepository
    {
        $repository = $this->createStub(BusinessRepository::class);
        $repository->method('findById')->willReturn($business);

        return $repository;
    }

    private function stories(GeoStory $story): GeoStoryRepository
    {
        $repository = $this->createStub(GeoStoryRepository::class);
        $repository->method('findById')->willReturn($story);

        return $repository;
    }

    private function business(): Business
    {
        return new Business(
            id: 'negocio-1',
            slug: 'bar-manolo',
            categoryId: 'cat-1',
            creatorId: 'usuario-1',
            name: 'Bar Manolo',
        );
    }

    private function story(): GeoStory
    {
        return new GeoStory(
            id: 'video-1',
            thumbnail: 'https://ejemplo/t.jpg',
            url: 'https://ejemplo/v.mp4',
            title: 'Un vídeo',
            businessId: 'negocio-1',
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Backoffice;

use App\Backoffice\Application\ReviewQueueNotifier;
use App\Business\Domain\Business;
use App\GeoStories\Domain\GeoStory;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Cuándo se avisa de que hay un vídeo esperando.
 *
 * La regla vive en el notificador y no en quien llama porque **a «listo» se
 * llega por dos caminos**: el webhook de Bunny y la reconciliación que hace el
 * BFF cuando el dueño abre su perfil. Estando en quien llama, el segundo se
 * quedó sin avisar y nadie se enteró de los vídeos que llegaban por ahí.
 */
final class ReviewQueueNotifierTest extends TestCase
{
    public function testNotifiesWhenTheVideoIsReadyAndUnverified(): void
    {
        $mailer = new SpyMailer();

        $this->notifier($mailer)->geoStoryPendingReview($this->story(GeoStory::STATUS_READY));

        self::assertSame(1, $mailer->sent);
    }

    public function testStaysQuietWhileItIsStillEncoding(): void
    {
        // Avisar antes de tiempo manda a alguien a una pantalla que dice
        // «procesando»: no hay vídeo que mirar todavía.
        $mailer = new SpyMailer();

        $this->notifier($mailer)->geoStoryPendingReview($this->story(GeoStory::STATUS_PROCESSING));

        self::assertSame(0, $mailer->sent);
    }

    public function testStaysQuietWhenItIsAlreadyVerified(): void
    {
        // Es el caso de los que sube el propio panel: nacen validados, así que
        // no están en ninguna cola de la que avisar.
        $mailer = new SpyMailer();
        $story  = $this->story(GeoStory::STATUS_READY);
        $story->verify();

        $this->notifier($mailer)->geoStoryPendingReview($story);

        self::assertSame(0, $mailer->sent);
    }

    public function testStaysQuietWithoutAnAddressToNotify(): void
    {
        // `REVIEW_EMAIL` vacío es lo normal en local: no se manda nada.
        $mailer = new SpyMailer();

        $this->notifier($mailer, address: '')->geoStoryPendingReview($this->story(GeoStory::STATUS_READY));

        self::assertSame(0, $mailer->sent);
    }

    public function testANewBusinessSaysWhichPlanItChose(): void
    {
        // Para validar un alta hay que saber qué ha contratado: no se mira
        // igual una ficha gratuita que una que ha pagado un año.
        $mailer = new SpyMailer();
        $db     = $this->createStub(Connection::class);
        $db->method('fetchAssociative')->willReturn(
            ['name' => 'PREMIUM · Semestral', 'amount_cents' => 18900, 'promo' => 'FERIA26'],
        );

        $this->notifier($mailer, db: $db)->businessPendingReview($this->business());

        self::assertStringContainsString('Tarifa:    PREMIUM · Semestral, 189,00 € (código FERIA26)', $mailer->text);
    }

    public function testAFreePlanSaysSo(): void
    {
        $mailer = new SpyMailer();
        $db     = $this->createStub(Connection::class);
        $db->method('fetchAssociative')->willReturn(['name' => 'FREE · Mensual', 'amount_cents' => 0, 'promo' => null]);

        $this->notifier($mailer, db: $db)->businessPendingReview($this->business());

        self::assertStringContainsString('Tarifa:    FREE · Mensual, gratis', $mailer->text);
    }

    public function testTheNoticeGoesOutEvenIfThePlanCannotBeRead(): void
    {
        // Un alta cobrada no puede quedarse sin aviso por no saber la tarifa.
        $mailer = new SpyMailer();
        $db     = $this->createStub(Connection::class);
        $db->method('fetchAssociative')->willThrowException(new \RuntimeException('sin base'));

        $this->notifier($mailer, db: $db)->businessPendingReview($this->business());

        self::assertSame(1, $mailer->sent);
        self::assertStringContainsString('Tarifa:    —', $mailer->text);
    }

    private function notifier(
        MailerInterface $mailer,
        string $address = 'hola@goveo.app',
        ?Connection $db = null,
    ): ReviewQueueNotifier {
        return new ReviewQueueNotifier(
            $mailer,
            $db ?? $this->createStub(Connection::class),
            new NullLogger(),
            'noreply@goveo.app',
            $address,
            'https://admin.goveo.app',
        );
    }

    private function business(): Business
    {
        return new Business(
            id: 'negocio-1',
            slug: 'la-tienda',
            categoryId: 'categoria-1',
            creatorId: 'usuario-1',
            name: 'La Tienda',
        );
    }

    private function story(string $status): GeoStory
    {
        return new GeoStory(
            id: 'video-1',
            thumbnail: 'https://ejemplo/t.jpg',
            url: 'https://ejemplo/v.mp4',
            title: 'Un vídeo',
            status: $status,
        );
    }
}

/** @internal cuenta los correos en vez de mandarlos */
final class SpyMailer implements MailerInterface
{
    public int $sent = 0;
    public string $text = '';

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        ++$this->sent;
        $this->text = $message instanceof Email ? (string) $message->getTextBody() : '';
    }
}

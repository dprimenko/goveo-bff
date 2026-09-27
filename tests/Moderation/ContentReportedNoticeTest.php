<?php

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Backoffice\Application\ReviewQueueNotifier;
use App\Moderation\Domain\ContentReport;
use App\Moderation\Domain\ReportReason;
use App\Moderation\Domain\ReportTarget;
use App\Tests\Backoffice\RecordingMailer;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * El aviso de una denuncia es lo que hace que se atienda en menos de 24 h, y
 * el de un bloqueo es lo que pide Apple («blocking should also notify the
 * developer»). Los dos tienen que salir, y distinguirse en la bandeja.
 */
final class ContentReportedNoticeTest extends TestCase
{
    public function testAReportReachesTheReviewInbox(): void
    {
        $mailer = new RecordingMailer();

        $this->notifier($mailer)->contentReported($this->report(ReportReason::Sexual, 'se ve un desnudo'), 'Un vídeo', 'Bar Pepe', 1);

        self::assertSame(['hola@goveo.app'], $mailer->recipients());
        self::assertSame('Nueva denuncia: Un vídeo', $mailer->subjects()[0]);
        self::assertStringContainsString('Contenido sexual', (string) $mailer->last()?->getTextBody());
        self::assertStringContainsString('se ve un desnudo', (string) $mailer->last()?->getTextBody());
        self::assertStringContainsString('https://admin.goveo.app/denuncias', (string) $mailer->last()?->getTextBody());
    }

    public function testABlockIsToldApartFromAReport(): void
    {
        $mailer = new RecordingMailer();

        $this->notifier($mailer)->contentReported($this->report(ReportReason::Blocked), 'Bar Pepe', 'Bar Pepe', 3);

        // Con cuántas lleva abiertas: la tercera no se lee como la primera.
        self::assertSame('Cuenta bloqueada por un usuario: Bar Pepe (3 abiertas)', $mailer->subjects()[0]);
    }

    public function testNothingIsSentWithoutAnAddress(): void
    {
        $mailer = new RecordingMailer();

        $this->notifier($mailer, address: '')->contentReported($this->report(ReportReason::Spam), 'Un vídeo', null, 1);

        self::assertSame(0, $mailer->count());
    }

    public function testUsersCannotPickTheBlockedReason(): void
    {
        // Es la que deja el bloqueo: si se pudiera mandar a mano, una denuncia
        // cualquiera se haría pasar por un bloqueo en la cola.
        self::assertNull(ReportReason::tryFromUser('blocked'));
        self::assertSame(ReportReason::Spam, ReportReason::tryFromUser(' SPAM '));
        self::assertNull(ReportReason::tryFromUser('lo-que-sea'));
    }

    private function notifier(RecordingMailer $mailer, string $address = 'hola@goveo.app'): ReviewQueueNotifier
    {
        return new ReviewQueueNotifier(
            $mailer,
            $this->createStub(Connection::class),
            new NullLogger(),
            'noreply@goveo.app',
            $address,
            'https://admin.goveo.app',
        );
    }

    private function report(ReportReason $reason, ?string $comment = null): ContentReport
    {
        return new ContentReport(
            'report-1',
            'user-1',
            ReportTarget::GeoStory,
            'video-1',
            'business',
            'business-1',
            $reason,
            $comment,
        );
    }
}

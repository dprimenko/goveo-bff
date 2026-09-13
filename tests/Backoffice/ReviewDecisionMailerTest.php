<?php

declare(strict_types=1);

namespace App\Tests\Backoffice;

use App\Backoffice\Application\ReviewDecisionMailer;
use App\Business\Domain\Business;
use App\GeoStories\Domain\GeoStory;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\MailerInterface;

/**
 * A quién le llega la decisión, que es la parte que se puede equivocar.
 *
 * El correo del dueño **no está en el negocio ni en el vídeo**: está en la
 * cuenta desde la que se dio de alta, y hay fichas importadas cuya cuenta no
 * tiene correo. De ahí la cadena de recursos —creador, gestores, facturación— y
 * de ahí que no tener ninguno no sea un error que haga estallar la revisión: la
 * decisión ya está tomada y guardada cuando se intenta avisar.
 */
final class ReviewDecisionMailerTest extends TestCase
{
    public function testTheOwnerOfTheAccountGetsTheBusinessDecision(): void
    {
        $mailer = new RecordingMailer();

        $this->mailer($mailer, ['users' => 'manolo@ejemplo.com'])
            ->businessApproved($this->business());

        self::assertSame(['manolo@ejemplo.com'], $mailer->recipients());
        self::assertSame('✅ Tu alta en GOVEO está aprobada', $mailer->last()?->getSubject());
    }

    public function testItFallsBackToTheBillingAddressOfTheForm(): void
    {
        // Ficha importada: la cuenta existe pero no tiene correo, y el único
        // seguro es el que se escribió en el formulario del alta.
        $mailer = new RecordingMailer();

        $this->mailer($mailer, [])->businessRejected($this->business([
            'billing' => ['email' => 'gestoria@ejemplo.com'],
        ]));

        self::assertSame(['gestoria@ejemplo.com'], $mailer->recipients());
    }

    public function testWithoutAnyAddressNothingIsSentAndNothingBlowsUp(): void
    {
        $mailer = new RecordingMailer();

        $this->mailer($mailer, [])->businessApproved($this->business());

        self::assertSame([], $mailer->recipients());
    }

    public function testAVideoOfABusinessGoesToWhoeverManagesIt(): void
    {
        $mailer = new RecordingMailer();

        $this->mailer($mailer, ['users' => 'manolo@ejemplo.com'])
            ->videoApproved($this->story(businessId: 'negocio-1'));

        self::assertSame(['manolo@ejemplo.com'], $mailer->recipients());
    }

    public function testAVideoOfACreatorGoesToTheCreatorAndUsesTheirName(): void
    {
        $mailer = new RecordingMailer();

        $this->mailer($mailer, ['influencers' => ['name' => 'Ana', 'email' => 'ana@ejemplo.com']])
            ->publisherApproved('creador-1');

        self::assertSame(['ana@ejemplo.com'], $mailer->recipients());
        self::assertStringContainsString('¡Enhorabuena, Ana!', (string) $mailer->last()?->getHtmlBody());
    }

    public function testTheVideoLinkUsesTheFeedOfItsCategory(): void
    {
        // Mismo reparto que `feedSegmentForCategory` en la app y que
        // `shareGeoStory` en la web: un evento no vive en el feed de ofertas.
        $mailer = new RecordingMailer();

        $this->mailer($mailer, ['users' => 'manolo@ejemplo.com', 'categories' => 'events'])
            ->videoApproved($this->story(businessId: 'negocio-1', categoryId: 'cat-1'));

        self::assertStringContainsString('https://goveo.app/e/video-1', (string) $mailer->last()?->getHtmlBody());
    }

    public function testTheVideoLinkFallsBackToTheOfferFeed(): void
    {
        $mailer = new RecordingMailer();

        $this->mailer($mailer, ['users' => 'manolo@ejemplo.com'])
            ->videoApproved($this->story(businessId: 'negocio-1'));

        self::assertStringContainsString('https://goveo.app/ol/video-1', (string) $mailer->last()?->getHtmlBody());
    }

    /**
     * @param array{users?: string, categories?: string, influencers?: array{name: string, email: string}} $db
     *        Lo que contesta la base: el correo de la cuenta, el slug de la
     *        categoría y la fila del creador. Lo que no esté, no está.
     */
    private function mailer(MailerInterface $mailer, array $db): ReviewDecisionMailer
    {
        $connection = $this->createStub(Connection::class);

        $connection->method('fetchOne')->willReturnCallback(
            static function (string $sql) use ($db): string|false {
                if (str_contains($sql, 'FROM categories')) {
                    return $db['categories'] ?? false;
                }

                // Cuenta del creador del negocio y, si no, sus gestores: las dos
                // consultas acaban en el correo de un usuario.
                return $db['users'] ?? false;
            },
        );

        $connection->method('fetchAssociative')->willReturnCallback(
            static fn (): array|false => $db['influencers'] ?? false,
        );

        return new ReviewDecisionMailer(
            $mailer,
            $connection,
            new NullLogger(),
            'hola@goveo.app',
            'https://goveo.app',
            'https://links.goveo.app/abc',
        );
    }

    private function business(?array $meta = null): Business
    {
        return new Business(
            id: 'negocio-1',
            slug: 'bar-manolo',
            categoryId: 'cat-1',
            creatorId: 'usuario-1',
            name: 'Bar Manolo',
            meta: $meta,
        );
    }

    private function story(?string $businessId = null, ?string $categoryId = null): GeoStory
    {
        return new GeoStory(
            id: 'video-1',
            thumbnail: 'https://ejemplo/t.jpg',
            url: 'https://ejemplo/v.mp4',
            title: 'Un vídeo',
            categoryId: $categoryId,
            businessId: $businessId,
        );
    }
}

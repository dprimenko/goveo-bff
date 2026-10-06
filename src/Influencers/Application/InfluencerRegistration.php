<?php

declare(strict_types=1);

namespace App\Influencers\Application;

use App\Account\Application\AccountProvisioner;
use App\Account\Application\CreatorWelcomeMailer;
use App\Backoffice\Application\ReviewQueueNotifier;
use App\Influencers\Domain\Influencer;
use App\Influencers\Domain\InfluencerRepository;
use App\Users\Domain\User;
use Symfony\Component\Uid\Uuid;

/**
 * Alta de creador desde la web o la app (06-10-2026), con el mismo camino que
 * la de negocio pero sin tarifa ni pago:
 *
 *  - Sin sesión, la cuenta sale del correo: si no existe se crea sin
 *    contraseña y la bienvenida le manda el enlace para ponerla. Si ya existe,
 *    quien llama le pide entrar antes (ver el controlador).
 *  - Con sesión, se cuelga de esa cuenta.
 *
 * Nace **sin validar**: no sale al público hasta que el equipo lo aprueba en el
 * panel, que es quien recibe el aviso. Puede ir subiendo vídeos mientras, que
 * pasan por la revisión de siempre.
 */
final class InfluencerRegistration
{
    public function __construct(
        private readonly AccountProvisioner $accounts,
        private readonly InfluencerRepository $influencers,
        private readonly CreatorWelcomeMailer $welcome,
        private readonly ReviewQueueNotifier $reviewQueue,
    ) {}

    /**
     * @param array{email: string, name: string, username: string, bio: ?string, socials: array<string,string>} $data
     *
     * @return array{influencer: Influencer, account_created: bool}
     */
    public function register(array $data, ?User $owner = null): array
    {
        $created = false;
        if ($owner === null) {
            $account = $this->accounts->forEmail($data['email'], $data['name']);
            $owner   = $account['user'];
            $created = $account['created'];
        }

        $influencer = new Influencer(
            id:       Uuid::v4()->toRfc4122(),
            userId:   $owner->getId(),
            username: $data['username'],
            name:     $data['name'],
            bio:      $data['bio'],
            meta:     $data['socials'] === [] ? null : $data['socials'],
        );
        $this->influencers->save($influencer);

        $email = (string) $owner->getEmail();
        $this->reviewQueue->influencerPendingReview($influencer, $email);
        $this->welcome->send($influencer, $owner->getId(), $email);

        return ['influencer' => $influencer, 'account_created' => $created];
    }
}

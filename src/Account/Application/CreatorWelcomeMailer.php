<?php

declare(strict_types=1);

namespace App\Account\Application;

use App\Account\Domain\PasswordSetupToken;
use App\Account\Domain\PasswordSetupTokenRepository;
use App\Auth\Infrastructure\Service\KeycloakService;
use App\Influencers\Domain\Influencer;
use App\Shared\Application\Mail\GoveoMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Bienvenida tras el alta de un creador. El mismo criterio que la del negocio
 * ([`WelcomeMailer`]): enlace para crear la contraseña sólo si la cuenta nació
 * con el alta; si no, dónde está su perfil. Sale al momento: aquí no hay pago
 * que esperar. La redacción, en [`CreatorWelcomeMessages`].
 */
final class CreatorWelcomeMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly PasswordSetupTokenRepository $tokens,
        private readonly KeycloakService $keycloak,
        private readonly LoggerInterface $logger,
        private readonly string $fromAddress,
        private readonly string $webUrl,
        private readonly string $appUrl,
    ) {}

    /** **No lanza**: un correo que falla no deshace un alta ya hecha. */
    public function send(Influencer $influencer, string $userId, string $email): void
    {
        try {
            $link = null;
            if ($this->keycloak->hasPendingPasswordSetup($email)) {
                ['token' => $token, 'plain' => $plain] = PasswordSetupToken::issue(Uuid::v4()->toRfc4122(), $userId);
                $this->tokens->save($token);
                $link = sprintf('%s/bienvenida?token=%s', rtrim($this->webUrl, '/'), $plain);
            }

            $mail = CreatorWelcomeMessages::create($influencer->getName(), $link, $this->appUrl);
            $this->mailer->send(GoveoMessage::from($this->fromAddress, $email, $mail));
        } catch (\Throwable $e) {
            $this->logger->error('No se pudo enviar la bienvenida del creador: {message}', [
                'message'    => $e->getMessage(),
                'influencer' => $influencer->getId(),
                'email'      => $email,
            ]);
        }
    }
}

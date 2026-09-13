<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Command;

use App\Account\Application\WelcomeMessages;
use App\Backoffice\Application\ReviewDecisionMessages;
use App\Shared\Application\Mail\GoveoMessage;
use App\Shared\Application\Mail\MailContent;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Manda todos los correos a clientes con datos de muestra, para verlos.
 *
 * Existe porque la otra forma de revisar la redacción o la maquetación es
 * provocar cada caso de verdad: dar de alta un negocio, pagarlo, subir un vídeo,
 * esperar a que Bunny lo codifique y aprobarlo en el panel. Para una coma es
 * demasiado, y lo que pasaba es que no se revisaba.
 *
 * En desarrollo todo el correo lo captura Mailpit (http://localhost:8025), así
 * que `--to` puede ser cualquier dirección inventada.
 *
 *   php bin/console goveo:mail:preview --to=yo@ejemplo.com
 *   php bin/console goveo:mail:preview --to=yo@ejemplo.com --only=video.rejected
 *
 * **No toca la base de datos ni Keycloak**: los nombres y los enlaces son de
 * muestra. Lo que prueba es el texto y la caja, no a quién le llega —eso es de
 * `ReviewDecisionMailer`, y va con sus tests—.
 */
#[AsCommand(
    name: 'goveo:mail:preview',
    description: 'Manda los correos a clientes con datos de muestra (Mailpit en local).',
)]
final class PreviewEmailsCommand extends Command
{
    private const BUSINESS  = 'Jamonería López Pascual';
    private const PUBLISHER = 'Ana Herrero';
    private const VIDEO     = 'Cortando jamón de bellota en Chamberí';

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly string $fromAddress,
        private readonly string $webUrl,
        private readonly string $appUrl,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'A dónde mandarlos.')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Mandar sólo uno (su clave).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $to = (string) $input->getOption('to');

        if ($to === '') {
            $io->error('Hace falta --to con una dirección.');

            return Command::INVALID;
        }

        $all  = $this->messages();
        $only = $input->getOption('only');

        if (is_string($only) && $only !== '') {
            if (!isset($all[$only])) {
                $io->error(sprintf('No conozco «%s». Hay: %s', $only, implode(', ', array_keys($all))));

                return Command::INVALID;
            }

            $all = [$only => $all[$only]];
        }

        $io->title(sprintf('Correos de muestra → %s', $to));

        foreach ($all as $kind => $mail) {
            $this->mailer->send(GoveoMessage::from($this->fromAddress, $to, $mail));

            $io->writeln(sprintf('  <info>✓</info> %-20s %s', $kind, $mail->subject));
        }

        $io->success(sprintf('%d correos enviados.', count($all)));

        return Command::SUCCESS;
    }

    /** @return array<string, MailContent> */
    private function messages(): array
    {
        $videoUrl = sprintf('%s/ol/8f0c4e2a-1111-2222-3333-444455556666', rtrim($this->webUrl, '/'));

        return [
            // Las dos versiones de la bienvenida: la de quien aún no tiene
            // contraseña y la de quien ya entró con su cuenta desde la app.
            'welcome.password'   => WelcomeMessages::create(self::BUSINESS, sprintf('%s/bienvenida?token=de-muestra', rtrim($this->webUrl, '/')), 'Ana', $this->appUrl),
            'welcome.account'    => WelcomeMessages::create(self::BUSINESS, null, 'Ana', $this->appUrl),
            'business.approved'  => ReviewDecisionMessages::businessApproved(self::BUSINESS, $this->appUrl),
            'business.rejected'  => ReviewDecisionMessages::businessRejected(self::BUSINESS),
            'video.approved'     => ReviewDecisionMessages::videoApproved(self::VIDEO, $videoUrl),
            'video.rejected'     => ReviewDecisionMessages::videoRejected(self::VIDEO),
            'publisher.approved' => ReviewDecisionMessages::publisherApproved(self::PUBLISHER, $this->appUrl),
            'publisher.rejected' => ReviewDecisionMessages::publisherRejected(self::PUBLISHER),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Command;

use App\Billing\Application\PlanOffer;
use App\Billing\Domain\BillingPlanRepository;
use App\Business\Domain\BusinessRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Cambia la tarifa de un negocio que ya existe y se lo pasa a su dueño: lo mismo
 * que el panel en la ficha del negocio («Cambiar tarifa»). Ver `PlanOffer`.
 *
 *   goveo:billing:offer-plan NEGOCIO TARIFA CORREO [--first-name=] [--last-name=]
 *
 * NEGOCIO y TARIFA, por id o por slug/código (`bar-manolo`, `platinum-anual`).
 * Saca el enlace de pago de Stripe para pasárselo al cliente y le manda los
 * correos del alta.
 */
#[AsCommand(
    name: 'goveo:billing:offer-plan',
    description: 'Cambia la tarifa de un negocio, vincula a su dueño por correo y saca el enlace de pago.',
)]
final class OfferPlanCommand extends Command
{
    public function __construct(
        private readonly BusinessRepository $businesses,
        private readonly BillingPlanRepository $plans,
        private readonly PlanOffer $offer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('business', InputArgument::REQUIRED, 'Id o slug del negocio');
        $this->addArgument('plan', InputArgument::REQUIRED, 'Id o código de la tarifa');
        $this->addArgument('email', InputArgument::REQUIRED, 'Correo de quien va a gestionarlo');
        $this->addOption('first-name', null, InputOption::VALUE_REQUIRED, 'Nombre, si la cuenta es nueva', '');
        $this->addOption('last-name', null, InputOption::VALUE_REQUIRED, 'Apellidos, si la cuenta es nueva', '');
        $this->addOption('invited', null, InputOption::VALUE_NONE, 'De invitación: activa ya, sin cobro ni correo de pago (pagó por fuera)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io  = new SymfonyStyle($input, $output);
        $ref = (string) $input->getArgument('business');

        $business = $this->businesses->findById($ref) ?? $this->businesses->findBySlug($ref);
        if ($business === null || $business->isDeleted()) {
            $io->error(sprintf('No hay ningún negocio «%s».', $ref));

            return Command::FAILURE;
        }

        $planRef = (string) $input->getArgument('plan');
        $plan    = $this->plans->findByCode($planRef) ?? $this->plans->findById($planRef);
        if ($plan === null || !$plan->isActive()) {
            $io->error(sprintf('No hay ninguna tarifa activa «%s».', $planRef));

            return Command::FAILURE;
        }

        $email = strtolower(trim((string) $input->getArgument('email')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error(sprintf('«%s» no es un correo válido.', $email));

            return Command::FAILURE;
        }

        $result = $this->offer->offer(
            $business,
            $plan,
            $email,
            (string) $input->getOption('first-name'),
            (string) $input->getOption('last-name'),
            (bool) $input->getOption('invited'),
        );

        $io->success(sprintf('%s → %s, gestionado por %s.', $business->getName(), $plan->getName(), $email));
        $io->listing([
            $result['needs_password'] ? 'Sin contraseña todavía: le llega el correo para crearla.' : 'Ya tenía cuenta con contraseña: le llega la bienvenida.',
            $result['manager_added'] ? 'Ahora gestiona el negocio.' : 'Ya lo gestionaba.',
            match (true) {
                $result['subscription']->isInvitation() => 'De invitación: activa ya, sin pago ni correo de pago.',
                $result['payment_url'] !== null         => 'También el correo con el enlace de pago.',
                default                                 => 'Tarifa gratuita: activa ya, sin pago.',
            },
            ...($result['removed_user'] !== null ? ['Al correo del ofrecimiento anterior se le ha quitado el acceso.'] : []),
        ]);
        if ($result['payment_url'] !== null) {
            $io->writeln('Enlace de pago:');
            $io->writeln('  ' . $result['payment_url']);
        }

        return Command::SUCCESS;
    }
}

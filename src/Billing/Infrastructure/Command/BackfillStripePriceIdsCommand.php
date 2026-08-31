<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Command;

use App\Billing\Domain\BillingPlanRepository;
use App\Shared\Infrastructure\Firebase\FirestoreClientFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Recovers `billing_plans.stripe_price_id` from the Firestore `plans` collection.
 *
 * The old backend created every Stripe Price with the Firestore document id, but
 * the plan import only kept the derived UUID v5, so the link was lost. Without
 * this the Stripe sync would create a second Price for every plan that already
 * has one — harmless in test, expensive to undo in live.
 *
 * Only fills rows where `stripe_price_id` is NULL; never overwrites.
 *
 * Usage:
 *   docker exec goveo-bff-php-1 sh -lc 'php bin/console goveo:migrate:billing-plan-stripe-ids --dry-run'
 */
#[AsCommand(
    name: 'goveo:migrate:billing-plan-stripe-ids',
    description: 'Recupera billing_plans.stripe_price_id desde los ids de la colección `plans` de Firestore.',
)]
final class BackfillStripePriceIdsCommand extends Command
{
    private const NS = '7e4d3c2a-1b5f-4e8d-9a6c-0f2e1d3b5a7c';

    public function __construct(
        private readonly FirestoreClientFactory $firestoreFactory,
        private readonly BillingPlanRepository $plans,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print without writing to DB');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $io->title('Firestore `plans` → billing_plans.stripe_price_id');
        if ($dryRun) {
            $io->note('DRY RUN');
        }

        $ns = Uuid::fromString(self::NS);
        $filled = $already = $unknown = 0;

        foreach ($this->firestoreFactory->create()->collection('plans')->documents() as $doc) {
            if (!$doc->exists()) {
                continue;
            }

            $legacyId = $doc->id();
            $plan     = $this->plans->findById(Uuid::v5($ns, $legacyId)->toRfc4122());

            if ($plan === null) {
                ++$unknown;
                continue;
            }
            if ($plan->getStripePriceId() !== null) {
                ++$already;
                continue;
            }

            if (!$dryRun) {
                $plan->syncStripe($legacyId);
                $this->plans->save($plan);
            }
            $io->writeln(sprintf('  %-28s → %s', $plan->getName(), $legacyId));
            ++$filled;
        }

        $io->newLine();
        $io->success(sprintf('%d enlazados, %d ya tenían id, %d planes de Firestore sin fila local.',
            $filled, $already, $unknown));

        return Command::SUCCESS;
    }
}

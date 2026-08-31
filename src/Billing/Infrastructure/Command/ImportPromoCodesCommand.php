<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Command;

use App\Billing\Domain\Discount;
use App\Billing\Domain\DiscountDuration;
use App\Billing\Domain\DiscountRepository;
use App\Billing\Domain\BillingPlanRepository;
use App\Billing\Domain\PromoCode;
use App\Billing\Domain\PromoCodeRepository;
use App\Partners\Domain\Partner;
use App\Partners\Domain\PartnerRepository;
use App\Shared\Infrastructure\Firebase\FirestoreClientFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Migrates the Firestore `coupons` collection → promo_codes (+ billing_discounts).
 *
 * Must run AFTER goveo:migrate:billing-plans — codes reference plans by id.
 * The full field-by-field mapping and what is deliberately dropped is documented
 * in migrations/Version20260824150000.php.
 *
 * Usage:
 *   docker exec goveo-bff-php-1 sh -lc 'php bin/console goveo:migrate:coupons --dry-run'
 *   docker exec goveo-bff-php-1 sh -lc 'php bin/console goveo:migrate:coupons'
 */
#[AsCommand(
    name: 'goveo:migrate:coupons',
    description: 'Migrates Firestore `coupons` to promo_codes and billing_discounts. Run after goveo:migrate:billing-plans.',
)]
final class ImportPromoCodesCommand extends Command
{
    /** Same namespace the plan/product importers use, so v5 ids line up. */
    private const NS = '7e4d3c2a-1b5f-4e8d-9a6c-0f2e1d3b5a7c';

    public function __construct(
        private readonly FirestoreClientFactory $firestoreFactory,
        private readonly PromoCodeRepository $promoCodes,
        private readonly DiscountRepository $discounts,
        private readonly BillingPlanRepository $plans,
        private readonly PartnerRepository $partners,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print what would be imported without writing to DB');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $io->title('Migrating Firestore `coupons` → promo_codes');
        if ($dryRun) {
            $io->note('DRY RUN — no data will be written.');
        }

        $ns   = Uuid::fromString(self::NS);
        $docs = $this->firestoreFactory->create()->collection('coupons')->documents();

        $created = $skipped = $commercial = $empty = 0;
        $missingPlans = [];
        $partnerCache = $this->indexPartnersByName();

        foreach ($docs as $doc) {
            if (!$doc->exists()) {
                continue;
            }

            $legacyId = $doc->id();
            $c        = $doc->data();
            $codeId   = Uuid::v5($ns, 'coupon:'.$legacyId)->toRfc4122();

            if ($this->promoCodes->findByCode($legacyId) !== null) {
                ++$skipped;
                continue;
            }

            // --- plan -------------------------------------------------------
            $planIds = [];
            if (!empty($c['plan'])) {
                $planUuid = Uuid::v5($ns, (string) $c['plan'])->toRfc4122();
                if ($this->plans->findById($planUuid) !== null) {
                    $planIds[] = $planUuid;
                } else {
                    $missingPlans[$legacyId] = (string) $c['plan'];
                }
            }

            // --- discount ---------------------------------------------------
            $discountId = null;
            $percentOff = isset($c['percentOff']) ? (int) $c['percentOff'] : null;
            // amountOff was stored in euros, not cents.
            $amountOff  = isset($c['amountOff']) ? (int) round(((float) $c['amountOff']) * 100) : null;

            if ($percentOff || $amountOff) {
                $discountId = Uuid::v5($ns, 'discount:'.$legacyId)->toRfc4122();
                if (!$dryRun && $this->discounts->findById($discountId) === null) {
                    $duration = DiscountDuration::tryFrom((string) ($c['duration'] ?? 'once'))
                        ?? DiscountDuration::Once;
                    $months   = isset($c['durationInMonths']) ? (int) $c['durationInMonths'] : null;

                    // A repeating discount with no month count is meaningless; the
                    // legacy data has a handful, treat them as one-off.
                    if ($duration === DiscountDuration::Repeating && ($months === null || $months < 1)) {
                        $duration = DiscountDuration::Once;
                        $months   = null;
                    }

                    $this->discounts->save(new Discount(
                        id:             $discountId,
                        name:           (string) ($c['name'] ?? $legacyId),
                        duration:       $duration,
                        percentOff:     $percentOff ?: null,
                        amountOffCents: $percentOff ? null : $amountOff,
                        durationInMonths: $months,
                        // The old backend created the Stripe coupon with the doc id.
                        stripeCouponId: $legacyId,
                    ));
                }
            }

            // --- partner ----------------------------------------------------
            $partnerId = null;
            if (!empty($c['partner'])) {
                $slug      = strtolower(trim((string) $c['partner']));
                $partnerId = $partnerCache[$slug] ?? null;
                if ($partnerId === null) {
                    $partnerId = Uuid::v5($ns, 'partner:'.$slug)->toRfc4122();
                    if (!$dryRun) {
                        $this->partners->save(new Partner(
                            id:   $partnerId,
                            name: $slug,
                            meta: ['created_by' => 'goveo:migrate:coupons'],
                        ));
                    }
                    $partnerCache[$slug] = $partnerId;
                    $io->writeln(sprintf('  <comment>PARTNER</comment>  creado «%s»', $slug));
                }
            }

            // --- what we cannot model yet -----------------------------------
            $meta = array_filter([
                'is_commercial'   => $c['isCommercial'] ?? null,
                'commercial_role' => $c['commercial'] ?? null,
                'percentage'      => $c['percentage'] ?? null,
                'agency'          => $c['agency'] ?? null,
                'delegate'        => $c['delegate'] ?? null,
                'stripe_user_id'  => $c['stripeUserId'] ?? null,
            ], static fn ($v) => $v !== null);

            // Salesperson records living in the coupons collection: they carry a
            // commission and personal data but grant nothing, so they are not codes.
            if ($planIds === [] && $discountId === null && $partnerId === null) {
                $meta !== [] ? ++$commercial : ++$empty;
                continue;
            }

            if (!$dryRun) {
                $this->promoCodes->save(new PromoCode(
                    id:               $codeId,
                    code:             $legacyId,
                    planIds:          $planIds,
                    discountId:       $discountId,
                    partnerId:        $partnerId,
                    label:            isset($c['name']) ? (string) $c['name'] : null,
                    billedExternally: !empty($c['noCheckout']),
                    maxUses:          isset($c['uses']) ? (int) $c['uses'] : null,
                    meta:             $meta ?: null,
                ));
            }
            ++$created;
        }

        $io->newLine();
        $io->success(sprintf('%d códigos importados, %d ya existían.', $created, $skipped));
        $io->writeln(sprintf('  Red comercial no importada (sin tarifa ni descuento): <comment>%d</comment>', $commercial));
        $io->writeln(sprintf('  Documentos sin ningún efecto: <comment>%d</comment>', $empty));

        if ($missingPlans !== []) {
            $io->warning(sprintf('%d códigos apuntan a planes que no están en billing_plans:', count($missingPlans)));
            foreach ($missingPlans as $code => $plan) {
                $io->writeln(sprintf('  %-28s → %s', $code, $plan));
            }
        }

        return Command::SUCCESS;
    }

    /** @return array<string,string> nombre en minúsculas → id */
    private function indexPartnersByName(): array
    {
        $index = [];
        foreach ($this->partners->findAll() as $partner) {
            $index[strtolower($partner->getName())] = $partner->getId();
        }

        return $index;
    }
}

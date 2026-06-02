<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs all Supabase migration commands in the correct dependency order,
 * then optionally runs the Firebase commands for data missing from Supabase.
 *
 * Supabase order (dependency-safe):
 *   1.  category-types
 *   2.  categories
 *   3.  category-types-mapping       (depends on 1+2)
 *   4.  default-subcategories        (depends on 2)
 *   5.  partners
 *   6.  partner-zipcodes             (depends on 5)
 *   7.  users
 *   8.  influencers                  (depends on 7)
 *   9.  business                     (depends on 2+5+7)
 *   10. business-managers            (depends on 7+9)
 *   11. geostories                   (depends on 2+8+9)
 *   12. notification-devices         (depends on 7)
 *   13. billing-products  (rates)
 *   14. billing-plans     (depends on 13)
 *   15. products          (geoproducts)
 *
 * Firebase (top-up):
 *   16. goveo:migrate:billing-products
 *   17. goveo:migrate:billing-plans
 *   18. goveo:migrate:products
 *
 * Usage:
 *   docker compose exec php php bin/console goveo:migrate:all --dry-run
 *   docker compose exec php php bin/console goveo:migrate:all
 *   docker compose exec php php bin/console goveo:migrate:all --skip-firebase
 *   docker compose exec php php bin/console goveo:migrate:all --skip-supabase
 */
#[AsCommand(
    name: 'goveo:migrate:all',
    description: 'Runs all data migration commands (Supabase first, then Firebase for missing data).',
)]
final class RunAllMigrationsCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Pass --dry-run to all sub-commands')
            ->addOption('skip-supabase', null, InputOption::VALUE_NONE, 'Skip Supabase migration steps')
            ->addOption('skip-firebase', null, InputOption::VALUE_NONE, 'Skip Firebase migration steps');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io            = new SymfonyStyle($input, $output);
        $dryRun        = (bool) $input->getOption('dry-run');
        $skipSupabase  = (bool) $input->getOption('skip-supabase');
        $skipFirebase  = (bool) $input->getOption('skip-firebase');

        $io->title('goveo:migrate:all');

        if ($dryRun) {
            $io->note('DRY RUN — passing --dry-run to all sub-commands.');
        }

        $steps = [];

        if (!$skipSupabase) {
            // ── categories ──────────────────────────────────────────────────────────
            $steps[] = ['label' => 'Supabase → category_types',              'cmd' => 'goveo:migrate:supabase:category-types'];
            $steps[] = ['label' => 'Supabase → categories',                  'cmd' => 'goveo:migrate:supabase:categories'];
            $steps[] = ['label' => 'Supabase → categories_category_types',   'cmd' => 'goveo:migrate:supabase:category-types-mapping'];
            $steps[] = ['label' => 'Supabase → default_subcategories',       'cmd' => 'goveo:migrate:supabase:default-subcategories'];
            // ── partners ────────────────────────────────────────────────────────────
            $steps[] = ['label' => 'Supabase → partners',                    'cmd' => 'goveo:migrate:supabase:partners'];
            $steps[] = ['label' => 'Supabase → partner_zipcodes',            'cmd' => 'goveo:migrate:supabase:partner-zipcodes'];
            // ── users ───────────────────────────────────────────────────────────────
            $steps[] = ['label' => 'Supabase → users',                       'cmd' => 'goveo:migrate:supabase:users'];
            // ── influencers ─────────────────────────────────────────────────────────
            $steps[] = ['label' => 'Supabase → influencers',                 'cmd' => 'goveo:migrate:supabase:influencers'];
            // ── business ────────────────────────────────────────────────────────────
            $steps[] = ['label' => 'Supabase → business',                    'cmd' => 'goveo:migrate:supabase:business'];
            $steps[] = ['label' => 'Supabase → business_managers',           'cmd' => 'goveo:migrate:supabase:business-managers'];
            // ── geostories ──────────────────────────────────────────────────────────
            $steps[] = ['label' => 'Supabase → geostories',                  'cmd' => 'goveo:migrate:supabase:geostories'];
            // ── notifications ────────────────────────────────────────────────────────
            $steps[] = ['label' => 'Supabase → notification_devices',        'cmd' => 'goveo:migrate:supabase:notification-devices'];
        }

        if (!$skipFirebase) {
            $steps[] = ['label' => 'Firebase → billing_products (rates)',  'cmd' => 'goveo:migrate:billing-products'];
            $steps[] = ['label' => 'Firebase → billing_plans (plans)',     'cmd' => 'goveo:migrate:billing-plans'];
            $steps[] = ['label' => 'Firebase → products (geoproducts)',    'cmd' => 'goveo:migrate:products'];
        }

        $failed = [];

        foreach ($steps as $i => $step) {
            $io->section(sprintf('[%d/%d] %s', $i + 1, count($steps), $step['label']));

            $subCommand = $this->getApplication()?->find($step['cmd']);
            if ($subCommand === null) {
                $io->error(sprintf('Command "%s" not found.', $step['cmd']));
                $failed[] = $step['cmd'];
                continue;
            }

            $args = ['command' => $step['cmd']];
            if ($dryRun) {
                $args['--dry-run'] = true;
            }

            $exitCode = $subCommand->run(new ArrayInput($args), $output);

            if ($exitCode !== Command::SUCCESS) {
                $io->warning(sprintf('"%s" finished with errors (exit code %d). Continuing…', $step['cmd'], $exitCode));
                $failed[] = $step['cmd'];
            }
        }

        if (!empty($failed)) {
            $io->error(sprintf('Finished with errors in: %s', implode(', ', $failed)));
            return Command::FAILURE;
        }

        $io->success('All migration steps completed successfully.');

        return Command::SUCCESS;
    }
}

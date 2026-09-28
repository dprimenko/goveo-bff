<?php

declare(strict_types=1);

namespace App\Products\Infrastructure\Command;

use App\Products\Domain\ProductRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pone una imagen por defecto a los productos que no tienen ninguna.
 *
 * Sin imagen, un producto no sale en la ficha del negocio —ni a los clientes ni,
 * en la app publicada, a su dueño—, y muchos negocios (un restaurante con su
 * carta, una hamburguesería) no tienen foto de cada plato. Hasta que la app
 * nueva enseñe a su dueño los productos sin imagen, esto los hace visibles.
 *
 * La imagen va **marcada como de por defecto** (`Product::setPlaceholderImage`):
 * en cuanto el negocio sube una foto de verdad se quita sola, y quitarla a mano
 * no borra el fichero de Bunny, que comparten todos. Por eso la URL tiene que
 * estar **fuera de las carpetas de los negocios** (`business/…`): al borrar un
 * negocio o un producto se borra su carpeta entera.
 *
 *   goveo:products:fill-missing-images URL --dry-run   # cuántos y cuáles, sin tocar
 *   goveo:products:fill-missing-images URL              # se la pone a todos
 *   goveo:products:fill-missing-images URL --business=bar-manolo
 */
#[AsCommand(
    name: 'goveo:products:fill-missing-images',
    description: 'Pone una imagen por defecto a los productos sin ninguna.',
)]
final class FillMissingProductImagesCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly ProductRepository $products,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('url', InputArgument::REQUIRED, 'La imagen por defecto (https, fuera de business/…).');
        $this->addOption('business', null, InputOption::VALUE_REQUIRED, 'Sólo los de este negocio (slug o id).');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Dice cuántos y cuáles, sin tocar nada.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io  = new SymfonyStyle($input, $output);
        $url = trim((string) $input->getArgument('url'));

        if (!preg_match('~^https://~', $url)) {
            $io->error('La URL tiene que empezar por https://.');

            return Command::INVALID;
        }
        if (preg_match('~/business/~', $url)) {
            $io->error('La imagen no puede estar dentro de business/…: al borrar un negocio se borra su carpeta, y con ella la imagen de todos.');

            return Command::INVALID;
        }

        $where  = "p.deleted_at IS NULL AND (p.images IS NULL OR p.images::jsonb = '[]'::jsonb)";
        $params = [];
        $business = $input->getOption('business');
        if (is_string($business) && $business !== '') {
            $where   .= ' AND (b.slug = ? OR b.id::text = ?)';
            $params   = [$business, $business];
        }

        $rows = $this->db->fetchAllAssociative(
            "SELECT p.id, p.title, b.name AS business
               FROM products p JOIN business b ON b.id = p.business_id
              WHERE {$where}
              ORDER BY b.name, p.title",
            $params,
        );

        if ($rows === []) {
            $io->success('No hay productos sin imagen.');

            return Command::SUCCESS;
        }

        $io->title(sprintf('%d producto(s) sin imagen', count($rows)));
        $io->table(
            ['Negocio', 'Producto'],
            array_map(static fn (array $r) => [$r['business'], $r['title']], array_slice($rows, 0, 50)),
        );
        if (count($rows) > 50) {
            $io->writeln(sprintf('  … y %d más.', count($rows) - 50));
        }

        if ($input->getOption('dry-run')) {
            $io->note('--dry-run: no se ha tocado nada.');

            return Command::SUCCESS;
        }

        $done = 0;
        foreach ($rows as $row) {
            $product = $this->products->findById($row['id']);
            if ($product !== null && $product->setPlaceholderImage($url)) {
                $this->products->save($product);
                $done++;
            }
        }

        $io->success(sprintf('Imagen por defecto puesta a %d producto(s).', $done));

        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Products;

use App\Products\Application\ProductSlugger;
use App\Products\Domain\ProductRepository;
use PHPUnit\Framework\TestCase;

/**
 * **Un producto borrado se queda con su slug.**
 *
 * Crear «test», borrarlo y volver a crear «test» reventaba con un 500: la
 * comprobación de si el nombre estaba cogido se saltaba los borrados y el
 * índice único de la tabla no. Quien lo sufría sólo veía «Algo ha fallado», y
 * probando otra vez con el mismo nombre volvía a fallar siempre.
 *
 * Se arregla del lado del que estaba mal, que es la comprobación: el slug es
 * parte de una URL pública y puede estar compartida, así que reutilizar el de
 * un producto borrado llevaría a quien abriera el enlace viejo a otro producto.
 */
final class ProductSluggerTest extends TestCase
{
    public function testAFreeNameKeepsIt(): void
    {
        self::assertSame('jamon-iberico', $this->slugger([])->forTitle('n-1', 'Jamón ibérico'));
    }

    public function testATakenNameIsNumbered(): void
    {
        $slugger = $this->slugger(['vino-tinto']);

        self::assertSame('vino-tinto-2', $slugger->forTitle('n-1', 'Vino tinto'));
    }

    public function testADeletedProductStillHoldsItsSlug(): void
    {
        // `slugTaken` mira la tabla entera: el «test» de antes sigue ahí,
        // borrado, y su slug sigue ocupado.
        $slugger = $this->slugger(['test']);

        self::assertSame('test-2', $slugger->forTitle('n-1', 'test'));
    }

    public function testAnUnsluggableNameStillGetsOne(): void
    {
        // Un nombre en otro alfabeto —o de emojis— se queda sin letras. Sin
        // esto el slug sería la cadena vacía y la URL, la del negocio.
        self::assertSame('producto', $this->slugger([])->forTitle('n-1', '🎁🎁'));
    }

    /** @param string[] $cogidos */
    private function slugger(array $cogidos): ProductSlugger
    {
        $products = $this->createMock(ProductRepository::class);
        $products->method('slugTaken')->willReturnCallback(
            static fn (string $businessId, string $slug) => in_array($slug, $cogidos, true),
        );

        return new ProductSlugger($products);
    }
}

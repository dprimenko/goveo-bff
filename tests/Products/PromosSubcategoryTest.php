<?php

declare(strict_types=1);

namespace App\Tests\Products;

use App\Products\Application\PromosSubcategory;
use App\Products\Domain\ProductSubcategory;
use App\Products\Domain\ProductSubcategoryRepository;
use PHPUnit\Framework\TestCase;

/**
 * **«Promos» existe sin que nadie la cree, y sólo una por negocio.**
 *
 * Es la subcategoría donde caen las ofertas, así que el sistema que venga
 * después tiene que poder darla por hecha: si hubiera que crearla a mano, la
 * primera promoción de cada tienda empezaría por un error.
 *
 * Lo que se prueba aquí es lo que no se ve al mirar la pantalla: que llamar dos
 * veces no crea dos, y que si otra petición se adelanta —la gestión del
 * catálogo abierta en dos sitios— vale la que ya está en vez de reventar.
 */
final class PromosSubcategoryTest extends TestCase
{
    public function testCreatesItTheFirstTime(): void
    {
        $repo   = new SubcategoriesInMemory();
        $promos = new PromosSubcategory($repo);

        $creada = $promos->ensureFor('negocio-1');

        self::assertTrue($creada->isPromos());
        // El nombre es una clave de traducción, no el texto: así se lee en el
        // idioma de quien mira y no en el de quien la creó.
        self::assertSame(PromosSubcategory::NAME_KEY, $creada->getName());
        self::assertCount(1, $repo->all());
    }

    public function testAskingTwiceReturnsTheSameOne(): void
    {
        $repo   = new SubcategoriesInMemory();
        $promos = new PromosSubcategory($repo);

        $primera = $promos->ensureFor('negocio-1');
        $segunda = $promos->ensureFor('negocio-1');

        self::assertSame($primera->getId(), $segunda->getId());
        self::assertCount(1, $repo->all());
    }

    public function testEachBusinessHasItsOwn(): void
    {
        $repo   = new SubcategoriesInMemory();
        $promos = new PromosSubcategory($repo);

        $una  = $promos->ensureFor('negocio-1');
        $otra = $promos->ensureFor('negocio-2');

        self::assertNotSame($una->getId(), $otra->getId());
        self::assertCount(2, $repo->all());
    }

    /**
     * La carrera que impide el índice único de la base: dos peticiones a la vez
     * comprueban que no hay ninguna, y la segunda en guardar se estrella. Vale
     * la del otro, y quien llamó no se entera.
     */
    public function testWhenAnotherRequestWinsTheRaceItUsesTheirs(): void
    {
        $repo = new SubcategoriesInMemory();
        $repo->alGuardar(function (SubcategoriesInMemory $repo): void {
            $repo->add(ProductSubcategory::promos('la-del-otro', 'negocio-1', PromosSubcategory::NAME_KEY));

            throw new \RuntimeException('duplicate key value violates unique constraint');
        });

        $promos = new PromosSubcategory($repo);

        self::assertSame('la-del-otro', $promos->ensureFor('negocio-1')->getId());
    }

    /** Si el guardado falla por cualquier otro motivo, el fallo sube. */
    public function testAnyOtherFailureIsNotSwallowed(): void
    {
        $repo = new SubcategoriesInMemory();
        $repo->alGuardar(static function (): void {
            throw new \RuntimeException('la base no responde');
        });

        $this->expectException(\RuntimeException::class);

        (new PromosSubcategory($repo))->ensureFor('negocio-1');
    }
}

/** Repositorio de mentira: guarda en memoria y puede fallar a propósito. */
final class SubcategoriesInMemory implements ProductSubcategoryRepository
{
    /** @var ProductSubcategory[] */
    private array $filas = [];

    /** @var null|callable(self): void */
    private $alGuardar = null;

    public function alGuardar(callable $fn): void
    {
        $this->alGuardar = $fn;
    }

    public function add(ProductSubcategory $subcategory): void
    {
        $this->filas[] = $subcategory;
    }

    /** @return ProductSubcategory[] */
    public function all(): array
    {
        return $this->filas;
    }

    public function findById(string $id): ?ProductSubcategory
    {
        foreach ($this->filas as $fila) {
            if ($fila->getId() === $id) {
                return $fila;
            }
        }

        return null;
    }

    public function findByBusinessId(string $businessId): array
    {
        return array_values(array_filter(
            $this->filas,
            static fn (ProductSubcategory $s) => $s->getBusinessId() === $businessId,
        ));
    }

    public function save(ProductSubcategory $subcategory): void
    {
        if ($this->alGuardar !== null) {
            $fn = $this->alGuardar;
            // Una sola vez: lo que se simula es la carrera, no una base rota.
            $this->alGuardar = null;
            $fn($this);
        }

        $this->filas[] = $subcategory;
    }

    public function delete(ProductSubcategory $subcategory): void
    {
        $this->filas = array_values(array_filter(
            $this->filas,
            static fn (ProductSubcategory $s) => $s->getId() !== $subcategory->getId(),
        ));
    }
}

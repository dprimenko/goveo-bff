<?php

declare(strict_types=1);

namespace App\Products\Application;

use App\Products\Domain\ProductSubcategory;
use App\Products\Domain\ProductSubcategoryRepository;
use App\Shared\Domain\UuidGenerator;

/**
 * La subcategoría «Promos», que todos los negocios tienen sin haberla creado.
 *
 * Existe para que publicar una oferta no empiece por inventarse dónde meterla,
 * y para que el sistema de ofertas que venga después sepa dónde mirar sin
 * preguntarle a nadie.
 *
 * **Se crea al primer uso y no se siembra.** Sembrarla en todos los negocios
 * llenaría el catálogo de chips vacíos en fichas que no van a publicar
 * promociones nunca; creándola cuando alguien abre la gestión del catálogo, la
 * tiene quien la necesita y nadie más. Si algún día sobran las vacías, se
 * limpian; lo que no se puede es inventar la que falta a mitad de una oferta.
 *
 * **Su nombre es una clave de traducción**, como las sugerencias: así se lee
 * «Promos» o «Deals» según quién mire, en vez de en el idioma de quien la creó.
 * Reconocerla, en cambio, no depende del nombre sino de `kind`, porque el
 * nombre se puede cambiar.
 */
final class PromosSubcategory
{
    /** La clave que traduce la app (`i18n/locales/*.json`). */
    public const NAME_KEY = 'subcategory.common.promos';

    public function __construct(
        private readonly ProductSubcategoryRepository $subcategories,
    ) {}

    /**
     * La de este negocio, creándola si todavía no existe.
     *
     * Idempotente: dos llamadas a la vez —la gestión del catálogo abierta en
     * dos sitios— acaban en la misma fila, porque la base tiene un índice único
     * parcial por negocio y quien pierda la carrera se encuentra la del otro.
     */
    public function ensureFor(string $businessId): ProductSubcategory
    {
        $existente = $this->find($businessId);
        if ($existente !== null) {
            return $existente;
        }

        $promos = ProductSubcategory::promos(
            id:         UuidGenerator::generate(),
            businessId: $businessId,
            name:       self::NAME_KEY,
        );

        try {
            $this->subcategories->save($promos);
        } catch (\Throwable $e) {
            // La carrera que impide el índice único: si otra petición la creó
            // entre la comprobación y el guardado, vale la suya.
            $otra = $this->find($businessId);
            if ($otra === null) {
                throw $e;
            }

            return $otra;
        }

        return $promos;
    }

    public function find(string $businessId): ?ProductSubcategory
    {
        foreach ($this->subcategories->findByBusinessId($businessId) as $subcategory) {
            if ($subcategory->isPromos()) {
                return $subcategory;
            }
        }

        return null;
    }
}

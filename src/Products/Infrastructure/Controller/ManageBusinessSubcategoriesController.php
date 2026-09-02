<?php

declare(strict_types=1);

namespace App\Products\Infrastructure\Controller;

use App\Business\Application\ManagedBusinessFinder;
use App\Categories\Domain\DefaultSubcategory;
use App\Categories\Domain\DefaultSubcategoryRepository;
use App\Products\Domain\ProductRepository;
use App\Products\Domain\ProductSubcategory;
use App\Products\Domain\ProductSubcategoryRepository;
use App\Shared\Domain\UuidGenerator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Las subcategorías con las que un negocio ordena su catálogo.
 *
 * Son suyas y sólo suyas: «Entrantes» de un restaurante es una fila distinta de
 * la de otro. Lo que se comparte es la **lista de sugerencias** —las
 * `default_subcategories` de su categoría—, y existe justamente para que cada
 * restaurante no tenga que teclear otra vez «Entrantes, Platos, Bebidas,
 * Postres». Es lo que hacía el alta de `store-register`: chips de las de por
 * defecto para marcar, y un campo libre para las propias.
 *
 * El listado devuelve las dos cosas de una vez porque la pantalla necesita las
 * dos para pintarse, y pedirlas por separado sólo añadiría un momento en el que
 * se ven las sugerencias antes que lo que ya tienes.
 *
 * **El nombre de una sugerencia es una clave de i18n** (`subcategory.hostelry.starters`),
 * igual que el de las categorías, y se guarda tal cual al adoptarla: así se lee
 * en el idioma de quien mira y no en el de quien la creó. Una escrita a mano se
 * guarda literal, y en la app las dos pasan por el mismo `subcategoryLabel`, que
 * traduce si hay clave y devuelve el texto si no.
 */
#[Route('/api/businesses/{businessId}/subcategories', name: 'business_subcategories_')]
class ManageBusinessSubcategoriesController
{
    private const MAX_NAME = 255;

    public function __construct(
        private readonly ProductSubcategoryRepository $subcategories,
        private readonly DefaultSubcategoryRepository $defaults,
        private readonly ProductRepository $products,
        private readonly ManagedBusinessFinder $managed,
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(string $businessId): Response
    {
        $business = $this->authorize($businessId);
        if ($business instanceof Response) {
            return $business;
        }

        $own = $this->subcategories->findByBusinessId($business->getId());

        // Las sugerencias ya adoptadas no se ofrecen otra vez. Se comparan por
        // nombre y no por id porque al adoptar una se copia el nombre: la fila
        // del negocio es nueva y no guarda de dónde salió.
        $usados     = array_map(static fn (ProductSubcategory $s) => $s->getName(), $own);
        $sugeridas  = array_values(array_filter(
            $this->defaults->findByCategoryId($business->getCategoryId()),
            static fn (DefaultSubcategory $d) => !in_array($d->getName(), $usados, true),
        ));

        return new JsonResponse([
            'items'     => array_map($this->serialize(...), $own),
            // Sin `icon`: los cuatro que hay apuntan todavía a Cloudinary, que
            // se apaga al publicar esta app. Los chips de la ficha no llevan
            // icono, así que nadie los echa de menos; si algún día se quieren,
            // primero hay que moverlos a Bunny como el resto.
            'available' => array_map(
                static fn (DefaultSubcategory $d) => ['name' => $d->getName()],
                $sugeridas,
            ),
        ]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(string $businessId, Request $request): Response
    {
        $business = $this->authorize($businessId);
        if ($business instanceof Response) {
            return $business;
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '' || mb_strlen($name) > self::MAX_NAME) {
            return $this->invalid('name_required');
        }

        $existentes = $this->subcategories->findByBusinessId($business->getId());
        if ($this->yaExiste($existentes, $name)) {
            // Dos chips con el mismo nombre en la ficha no se distinguen, y el
            // producto acabaría en una de las dos al azar.
            return $this->invalid('duplicate_name');
        }

        $subcategory = new ProductSubcategory(
            id:         UuidGenerator::generate(),
            businessId: $business->getId(),
            name:       $name,
            sortOrder:  $this->siguienteOrden($existentes),
        );

        $this->subcategories->save($subcategory);

        return new JsonResponse($this->serialize($subcategory), Response::HTTP_CREATED);
    }

    #[Route('/{subcategoryId}', name: 'update', methods: ['PATCH'])]
    public function update(string $businessId, string $subcategoryId, Request $request): Response
    {
        $business = $this->authorize($businessId);
        if ($business instanceof Response) {
            return $business;
        }

        $subcategory = $this->locate($business->getId(), $subcategoryId);
        if ($subcategory === null) {
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if ($name === '' || mb_strlen($name) > self::MAX_NAME) {
                return $this->invalid('name_required');
            }

            $otras = array_filter(
                $this->subcategories->findByBusinessId($business->getId()),
                static fn (ProductSubcategory $s) => $s->getId() !== $subcategory->getId(),
            );
            if ($this->yaExiste($otras, $name)) {
                return $this->invalid('duplicate_name');
            }

            $subcategory->rename($name);
        }

        if (array_key_exists('sort_order', $data) && is_numeric($data['sort_order'])) {
            $subcategory->reorder((int) $data['sort_order']);
        }

        $this->subcategories->save($subcategory);

        return new JsonResponse($this->serialize($subcategory));
    }

    #[Route('/{subcategoryId}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $businessId, string $subcategoryId): Response
    {
        $business = $this->authorize($businessId);
        if ($business instanceof Response) {
            return $business;
        }

        $subcategory = $this->locate($business->getId(), $subcategoryId);
        if ($subcategory === null) {
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        // Primero se sacan los productos y luego se borra la fila: al revés, un
        // fallo entre medias los dejaría apuntando a algo inexistente y
        // desaparecerían de los filtros sin estar borrados.
        $movidos = $this->products->clearSubcategory($subcategory->getId());
        $this->subcategories->delete($subcategory);

        // Los productos no se borran con ella: quedan en la ficha sin filtro,
        // que es lo que espera quien sólo quería reorganizar el catálogo.
        return new JsonResponse(['moved_products' => $movidos]);
    }

    // ── Auxiliares ──────────────────────────────────────────────────────────

    /** El negocio, o la respuesta de error si quien pregunta no lo gestiona. */
    private function authorize(string $businessId): mixed
    {
        if (!$this->managed->hasSession()) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        // `null` cubre tanto «no existe» como «es de otro»: ver ManagedBusinessFinder.
        return $this->managed->find($businessId)
            ?? new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
    }

    private function locate(string $businessId, string $subcategoryId): ?ProductSubcategory
    {
        $subcategory = $this->subcategories->findById($subcategoryId);

        return $subcategory !== null && $subcategory->getBusinessId() === $businessId
            ? $subcategory
            : null;
    }

    /** @param ProductSubcategory[] $existentes */
    private function yaExiste(array $existentes, string $name): bool
    {
        foreach ($existentes as $s) {
            if (mb_strtolower($s->getName()) === mb_strtolower($name)) {
                return true;
            }
        }

        return false;
    }

    /** @param ProductSubcategory[] $existentes */
    private function siguienteOrden(array $existentes): int
    {
        $ordenes = array_map(static fn (ProductSubcategory $s) => $s->getSortOrder(), $existentes);

        return $ordenes === [] ? 0 : max($ordenes) + 1;
    }

    private function invalid(string $code): JsonResponse
    {
        return new JsonResponse(['error' => $code], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function serialize(ProductSubcategory $subcategory): array
    {
        return [
            'id'         => $subcategory->getId(),
            'name'       => $subcategory->getName(),
            'sort_order' => $subcategory->getSortOrder(),
        ];
    }
}

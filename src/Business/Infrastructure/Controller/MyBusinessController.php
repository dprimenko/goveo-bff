<?php

declare(strict_types=1);

namespace App\Business\Infrastructure\Controller;

use App\Business\Application\ManagedBusinessFinder;
use App\Business\Domain\Business;
use App\Business\Domain\BusinessRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Ficha editable de un negocio, para su gestor.
 *
 * Distinto de `/public/businesses/{id}`, que es para cualquiera: aquí se
 * devuelve lo que hace falta para rellenar el formulario de edición.
 *
 * **No incluye facturación.** Esos datos están en Stripe junto a la suscripción
 * viva, y dejarlos editar aquí los haría discrepar entre los dos sitios; se
 * cambian a mano cuando alguien lo pide.
 *
 * Se puede editar **antes de estar validado**: el dueño va completando su ficha
 * mientras espera la revisión.
 */
#[Route('/api/businesses/{id}', name: 'my_business_')]
class MyBusinessController
{
    public function __construct(
        private readonly BusinessRepository $businesses,
        private readonly ManagedBusinessFinder $managed,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('', name: 'get', methods: ['GET'])]
    public function get(string $id): Response
    {
        $business = $this->authorize($id);
        if ($business instanceof Response) {
            return $business;
        }

        return new JsonResponse($this->serialize($business));
    }

    #[Route('', name: 'update', methods: ['PATCH'])]
    public function update(string $id, Request $request): Response
    {
        $business = $this->authorize($id);
        if ($business instanceof Response) {
            return $business;
        }

        $payload = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'invalid_payload'], Response::HTTP_BAD_REQUEST);
        }

        $errors = $this->validate($payload);
        if ($errors !== []) {
            return new JsonResponse(
                ['error' => 'validation_failed', 'fields' => $errors],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        // Se mira con `array_key_exists`: es un PATCH, y no mandar un campo lo
        // deja como está, mientras que mandarlo vacío lo borra.
        if (array_key_exists('name', $payload)) {
            // El slug **no** se recalcula: es una ruta pública que puede estar
            // compartida o enlazada, y cambiarla rompería esos enlaces.
            $business->setName(trim((string) $payload['name']));
        }
        if (array_key_exists('description', $payload)) {
            $business->setDescription($this->str($payload['description']));
        }
        if (array_key_exists('avatar', $payload)) {
            $business->setAvatar($this->str($payload['avatar']));
        }
        if (array_key_exists('main_image', $payload)) {
            $business->setMainImage($this->str($payload['main_image']));
        }

        if (isset($payload['address']) && is_array($payload['address'])) {
            $business->setLocation(
                (float) $payload['address']['lat'],
                (float) $payload['address']['lng'],
            );
        }

        $this->applyMeta($business, $payload);

        $lostVerification = false;
        if (array_key_exists('category_id', $payload)) {
            $lostVerification = $business->changeCategory((string) $payload['category_id']);
        }

        $this->businesses->save($business);

        if ($lostVerification) {
            $this->logger->info('Cambio de categoría: el negocio vuelve a validación', [
                'business' => $business->getId(),
            ]);
        }

        return new JsonResponse($this->serialize($business) + [
            // Para poder avisar de que su ficha ha dejado de publicarse.
            'lost_verification' => $lostVerification,
        ]);
    }

    /** @return Business|Response el negocio, o la respuesta de error */
    private function authorize(string $id): Business|Response
    {
        if (!$this->managed->hasSession()) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        // Gestor, no creador: un negocio puede tener varios, y el creador podría
        // haber dejado de gestionarlo. `null` es tanto «no existe» como «es de
        // otro», y se responde 404 en ambos: decir «existe pero no es tuyo»
        // permitiría averiguar qué negocios hay probando ids.
        return $this->managed->find($id)
            ?? new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
    }

    private function serialize(Business $business): array
    {
        $meta = $business->getMeta() ?? [];

        return [
            'id'          => $business->getId(),
            'slug'        => $business->getSlug(),
            'name'        => $business->getName(),
            'description' => $business->getDescription(),
            'avatar'      => $business->getAvatar(),
            'main_image'  => $business->getMainImage(),
            'category_id' => $business->getCategoryId(),
            'verified'    => $business->getVerifiedAt() !== null,
            'address'     => [
                'formatted' => $meta['address'] ?? null,
                'lat'       => $business->getLatitude(),
                'lng'       => $business->getLongitude(),
            ],
            'contact' => [
                'phone'       => $meta['public_phone'] ?? null,
                'website'     => $meta['website_url'] ?? null,
                'booking_url' => $meta['booking_url'] ?? null,
                'is_whatsapp' => (bool) ($meta['is_whatsapp'] ?? false),
            ],
        ];
    }

    private function applyMeta(Business $business, array $payload): void
    {
        $meta = $business->getMeta() ?? [];

        $map = [
            'phone'       => 'public_phone',
            'website'     => 'website_url',
            'booking_url' => 'booking_url',
        ];

        foreach ($map as $field => $key) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }
            $value = $this->str($payload[$field]);
            if ($value === null) {
                unset($meta[$key]);
            } else {
                $meta[$key] = $value;
            }
        }

        if (array_key_exists('is_whatsapp', $payload)) {
            $meta['is_whatsapp'] = (bool) $payload['is_whatsapp'];
        }

        if (isset($payload['address']['formatted'])) {
            $meta['address'] = $this->str($payload['address']['formatted']);
        }

        $business->setMeta($meta === [] ? null : $meta);
    }

    /** @return array<string,string> */
    private function validate(array $p): array
    {
        $errors = [];

        if (array_key_exists('name', $p) && trim((string) $p['name']) === '') {
            $errors['name'] = 'required';
        }

        if (array_key_exists('category_id', $p) && trim((string) $p['category_id']) === '') {
            $errors['category_id'] = 'required';
        }

        if (isset($p['address'])) {
            $address = $p['address'];
            if (!is_array($address) || !isset($address['lat'], $address['lng'])) {
                $errors['address'] = 'invalid';
            } elseif (
                !is_numeric($address['lat']) || !is_numeric($address['lng'])
                || abs((float) $address['lat']) > 90 || abs((float) $address['lng']) > 180
            ) {
                $errors['address'] = 'invalid';
            }
        }

        return $errors;
    }

    private function str(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}

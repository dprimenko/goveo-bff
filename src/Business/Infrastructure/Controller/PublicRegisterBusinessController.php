<?php

declare(strict_types=1);

namespace App\Business\Infrastructure\Controller;

use App\Billing\Domain\BillingPlanRepository;
use App\Business\Application\PublicBusinessRegistration;
use App\Business\Domain\Business;
use App\Business\Domain\BusinessRepository;
use App\Shared\Infrastructure\Storage\BunnyStorageService;
use App\Users\Domain\UserRepository;
use App\Users\Infrastructure\Service\LocalUserResolver;
use Psr\Log\LoggerInterface;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Alta de negocio desde la landing. **Público**: no hay sesión todavía, la
 * cuenta se crea aquí a partir del email.
 *
 * Lo rellena tanto el propio comerciante como un comercial de Goveo; en el
 * segundo caso lo único que cambia es que el enlace de pago se lo pasa él.
 *
 * ⚠️ No acepta importes: el precio sale siempre de la tarifa. Siendo un endpoint
 * abierto, admitir un importe pactado sería regalar suscripciones a cero.
 */
#[Route('/public/registration/business', name: 'public_register_business', methods: ['POST'])]
class PublicRegisterBusinessController
{
    public function __construct(
        private readonly PublicBusinessRegistration $registration,
        private readonly BillingPlanRepository $plans,
        private readonly BusinessRepository $businesses,
        private readonly BunnyStorageService $storage,
        private readonly UserRepository $users,
        private readonly LocalUserResolver $currentUser,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(Request $request): Response
    {
        // Dos formas de llegar: JSON a secas, o multipart con las imágenes y el
        // resto de datos en un campo `payload`. Las fotos tienen que venir en
        // esta misma petición porque el negocio **todavía no existe**, y el
        // endpoint de imágenes exige ser su gestor.
        $raw = $request->request->get('payload');
        $payload = json_decode(
            is_string($raw) ? $raw : ($request->getContent() ?: '{}'),
            true,
        );

        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'invalid_payload'], Response::HTTP_BAD_REQUEST);
        }

        $violations = $this->validate($payload);
        if ($violations !== []) {
            return new JsonResponse(
                ['error' => 'validation_failed', 'fields' => $violations],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        // Se acepta el código legible (`platinum-anual`) además del id: es lo
        // que viaja en los enlaces de la landing.
        $planRef = (string) $payload['billing_plan_id'];
        $plan    = $this->plans->findByCode($planRef) ?? $this->plans->findById($planRef);
        if ($plan === null || !$plan->isActive()) {
            return new JsonResponse(['error' => 'plan_not_available'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Con sesión iniciada, el negocio se cuelga de **ese** usuario y el email
        // del formulario no decide nada: si no, bastaría con entrar como uno y
        // reclamar el correo de otro.
        $owner = null;
        $currentUserId = $this->currentUser->currentId();

        if ($currentUserId !== null) {
            $owner = $this->users->findById($currentUserId);
        }

        if ($owner === null) {
            $existing = $this->users->findByEmail(strtolower(trim((string) $payload['email'])));
            if ($existing !== null) {
                // Ya hay cuenta con ese correo. No se cuelga el negocio sin más:
                // sería dárselo a alguien que no ha dado permiso. Se le pide que
                // entre y reenvíe con sesión.
                //
                // Se comprueba **al enviar** y no antes a propósito: un endpoint
                // de «¿existe este email?» dejaría comprobar direcciones sueltas
                // hasta dar con las que tienen cuenta.
                return new JsonResponse(
                    ['error' => 'account_exists', 'email' => $existing->getEmail()],
                    Response::HTTP_CONFLICT,
                );
            }
        }

        try {
            $result = $this->registration->register($payload, $plan, $owner);
        } catch (ApiErrorException $e) {
            $this->logger->error('Stripe rechazó el enlace del alta web', ['message' => $e->getMessage()]);

            return new JsonResponse(['error' => 'payment_link_failed'], Response::HTTP_BAD_GATEWAY);
        } catch (\Throwable $e) {
            $this->logger->error('No se pudo completar el alta web: {message}', [
                'message'   => $e->getMessage(),
                'exception' => $e,
            ]);

            return new JsonResponse(['error' => 'could_not_register'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $business = $result['business'];
        $this->attachImages($business, $request);

        return new JsonResponse([
            'business' => [
                'id'   => $business->getId(),
                'slug' => $business->getSlug(),
                'name' => $business->getName(),
                // Pagar no valida: alguien de Goveo lo revisa a mano.
                'verified' => false,
            ],
            'payment_url'     => $result['payment_url'],
            'account_created' => $result['account_created'],
        ], Response::HTTP_CREATED);
    }

    /**
     * Sube las imágenes que vengan y las asocia al negocio recién creado.
     *
     * **No hace fallar el alta**: el negocio existe, el cobro está preparado y
     * el usuario va camino del pago. Que una foto no suba se arregla luego desde
     * la app; tumbar el alta aquí sería mucho peor.
     */
    private function attachImages(Business $business, Request $request): void
    {
        $changed = false;

        foreach (['avatar' => 'setAvatar', 'main_image' => 'setMainImage'] as $slot => $setter) {
            $file = $request->files->get($slot);
            if ($file === null || !$file->isValid()) {
                continue;
            }

            try {
                $url = $this->storage->uploadBusinessImage(
                    $business->getId(),
                    $slot,
                    (string) file_get_contents($file->getPathname()),
                );
                $business->$setter($url);
                $changed = true;
            } catch (\Throwable $e) {
                $this->logger->warning('No se pudo subir una imagen del alta: {message}', [
                    'message'  => $e->getMessage(),
                    'business' => $business->getId(),
                    'slot'     => $slot,
                ]);
            }
        }

        if ($changed) {
            $this->businesses->save($business);
        }
    }

    /** @return array<string,string> campo → motivo */
    private function validate(array $p): array
    {
        $errors = [];

        foreach (['name', 'email', 'category_id', 'billing_plan_id'] as $field) {
            if (trim((string) ($p[$field] ?? '')) === '') {
                $errors[$field] = 'required';
            }
        }

        if (isset($p['email']) && !filter_var((string) $p['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'invalid';
        }

        $address = $p['address'] ?? null;
        if (!is_array($address) || !isset($address['lat'], $address['lng'])) {
            $errors['address'] = 'required';
        } elseif (
            !is_numeric($address['lat']) || !is_numeric($address['lng'])
            || abs((float) $address['lat']) > 90 || abs((float) $address['lng']) > 180
        ) {
            $errors['address'] = 'invalid';
        }

        $billing = $p['billing'] ?? null;
        if (!is_array($billing)) {
            $errors['billing'] = 'required';
        } else {
            foreach (['company_name', 'tax_id', 'address', 'email', 'phone'] as $field) {
                if (trim((string) ($billing[$field] ?? '')) === '') {
                    $errors['billing.'.$field] = 'required';
                }
            }
        }

        return $errors;
    }
}

<?php

declare(strict_types=1);

namespace App\Billing\Application;

use App\Billing\Domain\BillingPlan;
use App\Billing\Infrastructure\Stripe\StripeClientFactory;

/**
 * El enlace de pago de Stripe para que un negocio pague su tarifa.
 *
 * Es un **Payment Link** y no una Checkout Session: la sesión caduca a las 24 h,
 * y este enlace tiene que seguir valiendo dentro de un correo o en el WhatsApp
 * en el que se le pasa al cliente. Lo usan el alta pública
 * (`PublicBusinessRegistration`) y el cambio de tarifa desde el panel
 * (`PlanOffer`): el mismo enlace, con el mismo IVA, código y vuelta.
 */
final class PaymentLinkCreator
{
    public function __construct(
        private readonly StripeClientFactory $stripe,
        /** A dónde vuelve quien termina de pagar (goveo-astro). */
        private readonly string $webUrl,
        /**
         * Código de promoción que se aplica solo, sin que nadie lo teclee. Vacío
         * = tarifas a precio de lista.
         */
        private readonly string $defaultPromoCode = '',
    ) {}

    /**
     * @param ?string $email quien va a pagar: sale ya escrito en la página de Stripe
     *
     * @return array{price_id: ?string, link_id: ?string, url: ?string}
     */
    public function create(string $businessId, string $businessName, BillingPlan $plan, ?string $email = null): array
    {
        // Una tarifa gratuita no genera cobro: no hay enlace que enviar.
        if ($plan->getAmountCents() === 0) {
            return ['price_id' => null, 'link_id' => null, 'url' => null];
        }

        $priceId = $plan->getStripePriceId();
        if ($priceId === null) {
            throw new \RuntimeException(sprintf(
                'La tarifa «%s» no está sincronizada con Stripe: ejecuta goveo:stripe:sync.',
                $plan->getName(),
            ));
        }

        $promoCode = trim($this->defaultPromoCode);

        $link = $this->stripe->create()->paymentLinks->create([
            'line_items' => [['price' => $priceId, 'quantity' => 1]],
            'metadata'   => ['goveo_business_id' => $businessId],
            // **Las tarifas son sin IVA y Stripe lo suma encima.** Vendemos a
            // negocios, que descuentan el impuesto: para ellos el precio es el
            // de antes de impuestos, y anunciarlo con el IVA dentro hace que
            // 35 € parezcan 35 y cuesten 28,93 de servicio. Además el tipo
            // depende de dónde esté el cliente, así que un precio con el IVA
            // español metido dentro sólo es correcto en España.
            //
            // Requiere Stripe Tax activo en la cuenta y el comportamiento por
            // defecto de los precios en «exclusive» —ver «IVA» en CLAUDE.md—.
            'automatic_tax' => ['enabled' => true],
            // Sin dirección no hay tipo que aplicar: Stripe necesita saber
            // dónde está el cliente para calcularlo.
            'billing_address_collection' => 'required',
            // El CIF en la factura, y la inversión del sujeto pasivo para un
            // negocio de otro país de la UE, que si no pagaría un IVA que aquí
            // no le toca.
            'tax_id_collection' => ['enabled' => true],
            // La caja de código, sólo si hay uno que meter en ella: sin
            // descuento en marcha, enseñarla vacía invita a buscar por ahí un
            // código que no existe.
            'allow_promotion_codes' => $promoCode !== '',
            'subscription_data' => [
                'metadata' => [
                    'goveo_business_id'   => $businessId,
                    'goveo_business_name' => $businessName,
                ],
            ],
            // **Se vuelve a nuestra web**, no a la página de confirmación de
            // Stripe. Ahí se quedaba el usuario sin más camino que cerrar la
            // pestaña, y la web no tenía forma de saber si había pagado: al
            // volver atrás le seguía ofreciendo «Ir al pago» a alguien que ya
            // había pagado. Con la vuelta, quien llega con la marca ha pagado y
            // quien llega sin ella, no.
            'after_completion' => [
                'type'     => 'redirect',
                'redirect' => ['url' => sprintf('%s/alta?pagado=1', rtrim($this->webUrl, '/'))],
            ],
        ]);

        // El descuento va en la URL y no en el enlace: la API de Payment Links
        // **no acepta `discounts`** —eso es de Checkout Session, que caduca a
        // las 24 h y aquí el enlace tiene que sobrevivir en un correo—. Así que
        // el código viaja como parámetro y Stripe lo aplica al abrir la página.
        // Se guarda ya con él, que es la URL que se manda por correo y la que
        // devuelve `/pago/{negocio}`: sin el parámetro se pagaría precio
        // completo.
        //
        // El correo, igual: el enlace es de un cliente concreto y ya sabemos
        // quién es, así que no tiene que teclearlo. Además es el que Stripe
        // guarda como cliente, y así las facturas van a esa dirección y no a
        // una mal tecleada. Stripe lo deja editable.
        $query = array_filter([
            'prefilled_email'      => trim((string) $email),
            'prefilled_promo_code' => $promoCode,
        ], static fn (string $v): bool => $v !== '');
        $url = $query === []
            ? $link->url
            : $link->url . '?' . http_build_query($query, '', '&', \PHP_QUERY_RFC3986);

        return ['price_id' => $priceId, 'link_id' => $link->id, 'url' => $url];
    }
}

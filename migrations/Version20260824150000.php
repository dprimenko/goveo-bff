<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * billing: cierra el mapeo de los 196 `coupons` de Firestore a `promo_codes`.
 *
 * Inventario real de la colección (goveoapp-fd8b1, 196 documentos), que es lo que
 * justifica cada columna — el modelo se ajustó a los datos, no al revés:
 *
 *   descuento+partner+noCheckout   51   aupacoord*      100 % durante 6/12 meses
 *   descuento+partner              51   aupabeca*       100 % durante 6/12 meses
 *   plan                           50   100mejores…     carga tarifa, no toca precio
 *   (red comercial, sin efecto)    15   dele00x…        NO son códigos de alta
 *   commercial                     13   232xxx          plan + comisión del 40 %
 *   descuento                      12   basic25…
 *   plan+noCheckout                 7   *ext            misma tarifa, facturada fuera
 *   plan+partner                    6   bcn8824…
 *   descuento+noCheckout            3
 *   plan+descuento                  1   goveo1000
 *   (vacíos)                        2   oscarmad2020, santival6235
 *
 * Mapeo campo a campo:
 *
 *   plan                    → promo_codes.plan_ids (UUID v5 del id de Firestore)
 *   percentOff / amountOff  → billing_discounts (amountOff venía en EUROS → ×100)
 *   duration/durationInMonths → billing_discounts.duration / duration_in_months
 *   partner                 → promo_codes.partner_id
 *   noCheckout              → promo_codes.billed_externally
 *   uses                    → promo_codes.max_uses (sólo 2 documentos)
 *   noChanges               → SE TIRA. Verificado contra los 196: coincide al 100 %
 *                             con "no tiene percentOff ni amountOff", así que es
 *                             información derivable (`discount_id IS NULL`).
 *   before                  → SE TIRA. Es `true` en los 114 documentos que lo traen,
 *                             nunca `false`: no distinguía nada.
 *   uploadRef, createdat    → SE TIRAN (procedencia del import antiguo).
 *   isCommercial, percentage, agency, delegate, stripeUserId
 *                           → promo_codes.meta, a la espera de la tabla `commercials`
 *                             y de Stripe Connect. Se guardan para no perderlos.
 *   address, phoneNumber, documentId, email
 *                           → SE TIRAN. Son datos personales del comercial (DNI,
 *                             dirección, teléfono) que no pintan nada en un código
 *                             de alta y que no vamos a replicar sin necesidad.
 *
 * `billed_externally` corrige el diseño de Version20260824120000: allí se asumió que
 * cobrar o no era propiedad del plan. Los datos dicen que no — los 7 planes de los
 * códigos `*ext` los comparte un código de pago (`Goveo Start Anual` lo venden
 * `goveo-start-anual24` cobrando y `goveo-start-anual24ext` sin cobrar), así que el
 * mismo plan se factura de las dos formas y la diferencia está en el código.
 * No es un descuento: el precio y lo que debe el negocio no cambian, sólo el canal.
 */
final class Version20260824150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'billing: promo_codes gana billed_externally (el antiguo noCheckout) y meta para la red comercial pendiente de modelar';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE promo_codes ADD billed_externally BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE promo_codes ADD meta JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE promo_codes DROP COLUMN meta');
        $this->addSql('ALTER TABLE promo_codes DROP COLUMN billed_externally');
    }
}

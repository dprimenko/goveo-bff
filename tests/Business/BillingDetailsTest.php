<?php

declare(strict_types=1);

namespace App\Tests\Business;

use App\Business\Domain\BillingDetails;
use PHPUnit\Framework\TestCase;

/** Corregir la facturación desde el panel: sólo lo que se manda, y bien escrito. */
final class BillingDetailsTest extends TestCase
{
    private const CURRENT = [
        'company_name' => 'Jamones López SL',
        'tax_id'       => 'B12345678',
        'email'        => 'facturas@lopez.es',
        'phone'        => '600000000',
        'address'      => 'Calle Mayor 1, Madrid',
    ];

    public function testOnlyTheFieldsSentChange(): void
    {
        $result = BillingDetails::apply(self::CURRENT, ['address' => 'Calle Fedora 7, Málaga']);

        self::assertSame([], $result['errors']);
        self::assertSame('Calle Fedora 7, Málaga', $result['billing']['address']);
        self::assertSame('Jamones López SL', $result['billing']['company_name']);
    }

    public function testTaxIdAndEmailAreNormalised(): void
    {
        $result = BillingDetails::apply([], ['tax_id' => ' b-12 345.678 ', 'email' => 'Facturas@Lopez.ES ']);

        self::assertSame('B12345678', $result['billing']['tax_id']);
        self::assertSame('facturas@lopez.es', $result['billing']['email']);
    }

    public function testEmptyRemovesTheField(): void
    {
        $result = BillingDetails::apply(self::CURRENT, ['phone' => '  ']);

        self::assertArrayNotHasKey('phone', $result['billing']);
    }

    public function testAnInvalidEmailIsRefusedAndNothingIsWritten(): void
    {
        $result = BillingDetails::apply(self::CURRENT, ['email' => 'no-es-un-correo']);

        self::assertSame(['email' => 'invalid'], $result['errors']);
    }

    public function testUnknownKeysAreIgnored(): void
    {
        $result = BillingDetails::apply(['otra_cosa' => 'x'], ['iban' => 'ES00', 'company_name' => 'A']);

        self::assertSame(['company_name' => 'A'], $result['billing']);
    }
}

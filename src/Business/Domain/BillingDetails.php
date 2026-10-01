<?php

declare(strict_types=1);

namespace App\Business\Domain;

/**
 * Los datos de facturación de un negocio (`meta.billing`): empresa, NIF,
 * correo, teléfono y dirección fiscal. Se piden en el alta y desde el panel se
 * pueden corregir.
 *
 * No son la ficha: la dirección fiscal no es la del local (la que lo sitúa en el
 * mapa), y el correo de facturación no es el de quien lo gestiona.
 */
final class BillingDetails
{
    public const FIELDS = ['company_name', 'tax_id', 'email', 'phone', 'address'];

    private const MAX_LENGTH = 255;

    /**
     * Aplica los cambios que lleguen —sólo esos— sobre lo que había. Vacío o
     * nulo borra el campo.
     *
     * @param array<string, mixed> $current lo de `meta.billing`
     * @param array<string, mixed> $changes lo que manda el panel
     *
     * @return array{billing: array<string, string>, errors: array<string, string>}
     */
    public static function apply(array $current, array $changes): array
    {
        $billing = array_intersect_key($current, array_flip(self::FIELDS));
        $errors  = [];

        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $changes)) {
                continue;
            }

            $value = $changes[$field];
            if ($value !== null && !is_string($value)) {
                $errors[$field] = 'invalid';
                continue;
            }

            $value = trim((string) $value);
            if ($value === '') {
                unset($billing[$field]);
                continue;
            }

            if (mb_strlen($value) > self::MAX_LENGTH) {
                $errors[$field] = 'too_long';
                continue;
            }

            if ($field === 'email') {
                $value = mb_strtolower($value);
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[$field] = 'invalid';
                    continue;
                }
            }

            // Un NIF se escribe de mil maneras («b-12 345 678»): se guarda de una.
            if ($field === 'tax_id') {
                $value = strtoupper((string) preg_replace('/[\s.\-]/', '', $value));
            }

            $billing[$field] = $value;
        }

        return ['billing' => $billing, 'errors' => $errors];
    }
}

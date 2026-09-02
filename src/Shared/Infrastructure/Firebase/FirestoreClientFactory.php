<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Firebase;

use Google\Cloud\Firestore\FirestoreClient;

/**
 * Cliente de Firestore para los comandos de importación del histórico.
 *
 * `FIREBASE_CREDENTIALS` es la **ruta** del JSON de la cuenta de servicio.
 *
 * En producción ese fichero no existe: el despliegue no monta nada de `config/`
 * y los import no se usan a diario. Se copia a mano antes de importar y se
 * borra después (ver `docs/COMANDOS.md`), que para una clave con acceso a todo
 * Firestore es mejor que dejarla viviendo en el servidor.
 *
 * Los errores se explican aquí porque el de Google no ayuda: sin credenciales
 * legibles dice «could not construct ApplicationDefaultCredentials», que no
 * menciona ni el fichero ni la variable.
 */
final class FirestoreClientFactory
{
    public function __construct(
        private readonly string $credentialsPath,
        private readonly string $projectId,
    ) {}

    public function create(): FirestoreClient
    {
        $credentials = $this->credentials();

        return new FirestoreClient([
            // El proyecto va también dentro del JSON: si la variable viene
            // vacía se usa el del propio fichero, que es el que corresponde a
            // esa clave y no puede contradecirla.
            'projectId'   => $this->projectId !== ''
                ? $this->projectId
                : ($credentials['project_id'] ?? null),
            'credentials' => $credentials,
            'transport'   => 'rest',
        ]);
    }

    /** @return array<string, mixed> */
    private function credentials(): array
    {
        $ruta = trim($this->credentialsPath);

        if ($ruta === '') {
            throw new \RuntimeException(
                'FIREBASE_CREDENTIALS está vacío: hace falta la ruta del JSON '
                . 'de la cuenta de servicio.'
            );
        }

        if (!is_file($ruta)) {
            throw new \RuntimeException(
                "No hay ningún fichero en $ruta. En producción la credencial se "
                . 'copia antes de importar y se borra después; ver docs/COMANDOS.md.'
            );
        }

        $contents = file_get_contents($ruta);
        if ($contents === false) {
            throw new \RuntimeException("No se ha podido leer $ruta.");
        }

        return $this->decode($contents, "el fichero $ruta");
    }

    /** @return array<string, mixed> */
    private function decode(string $json, string $origen): array
    {
        $data = json_decode($json, true);

        if (!is_array($data) || !isset($data['private_key'], $data['client_email'])) {
            throw new \RuntimeException(
                "Las credenciales de Firebase en $origen no son un JSON de cuenta "
                . 'de servicio válido (faltan private_key o client_email).'
            );
        }

        return $data;
    }
}

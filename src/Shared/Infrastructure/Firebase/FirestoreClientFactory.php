<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Firebase;

use Google\Cloud\Firestore\FirestoreClient;

final class FirestoreClientFactory
{
    public function __construct(
        private readonly string $credentialsPath,
        private readonly string $projectId,
    ) {}

    public function create(): FirestoreClient
    {
        $credentials = json_decode(file_get_contents($this->credentialsPath), true);

        return new FirestoreClient([
            'projectId'   => $this->projectId,
            'credentials' => $credentials,
            'transport'   => 'rest',
        ]);
    }
}

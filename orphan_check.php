<?php
require '/var/www/html/vendor/autoload.php';

$kernel = new \App\Kernel('prod', false);
$kernel->boot();
$conn = $kernel->getContainer()->get('doctrine')->getConnection();

$creds = json_decode(file_get_contents('/var/www/html/config/firebase-credentials.json'), true);
$db = new Google\Cloud\Firestore\FirestoreClient([
    'projectId'   => 'goveoapp-fd8b1',
    'credentials' => $creds,
    'transport'   => 'rest',
]);

$ns = \Symfony\Component\Uid\Uuid::fromString('7e4d3c2a-1b5f-4e8d-9a6c-0f2e1d3b5a7c');

$docs      = $db->collection('geoproducts')->documents();
$total     = 0;
$noStoreId = 0;
$orphan    = 0;
$orphanStores = [];

foreach ($docs as $doc) {
    if (!$doc->exists()) {
        continue;
    }
    ++$total;
    $d       = $doc->data();
    $storeId = $d['storeId'] ?? null;

    if (empty($storeId)) {
        ++$noStoreId;
        continue;
    }

    $businessUuid = \Symfony\Component\Uid\Uuid::v5($ns, (string) $storeId)->toRfc4122();
    $exists = $conn->fetchOne('SELECT 1 FROM business WHERE id = ?', [$businessUuid]);

    if (!$exists) {
        ++$orphan;
        $orphanStores[$storeId] = ($orphanStores[$storeId] ?? 0) + 1;
    }
}

arsort($orphanStores);

echo PHP_EOL;
echo 'Total productos en Firestore : ' . $total  . PHP_EOL;
echo 'Sin storeId                  : ' . $noStoreId . PHP_EOL;
echo 'Huerfanos (tienda borrada)   : ' . $orphan . PHP_EOL;
echo PHP_EOL . 'Top tiendas borradas (storeId → nº productos):' . PHP_EOL;

$i = 0;
foreach ($orphanStores as $sid => $cnt) {
    echo '  ' . $sid . ' → ' . $cnt . PHP_EOL;
    if (++$i >= 20) {
        echo '  ... y ' . (count($orphanStores) - 20) . ' más' . PHP_EOL;
        break;
    }
}

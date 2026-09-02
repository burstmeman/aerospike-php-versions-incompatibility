<?php

declare(strict_types=1);

require __DIR__ . '/shared/dataset.php';

/**
 * Reads back every value every writer container stored, using the legacy
 * PECL client. Prints one line per (writer, value type) pair so the matrix
 * script can diff this against the other two readers' output.
 */

$namespace = getenv('AEROSPIKE_NAMESPACE') ?: 'test';
$set = getenv('AEROSPIKE_SET') ?: 'compat-demo';

$db = new Aerospike(['hosts' => [
    ['addr' => getenv('AEROSPIKE_HOST') ?: 'aerospike', 'port' => 3000],
]]);

if (!$db->isConnected()) {
    fwrite(STDERR, "Could not connect to Aerospike: {$db->error()}\n");
    exit(1);
}

foreach (writerIds() as $writerId) {
    foreach (array_keys(dataset()) as $typeName) {
        $key = $db->initKey($namespace, $set, keyFor($writerId, $typeName));
        $status = $db->get($key, $record);

        if ($status === Aerospike::OK) {
            $value = $record['bins']['value'] ?? null;
            printf("%-18s %-14s %-10s %s\n", $writerId, $typeName, gettype($value), reprValue($value));
        } elseif ($status === Aerospike::ERR_RECORD_NOT_FOUND) {
            printf("%-18s %-14s %s\n", $writerId, $typeName, 'MISSING (record not found)');
        } else {
            printf("%-18s %-14s ERROR (%d: %s)\n", $writerId, $typeName, $db->errorno(), $db->error());
        }
    }
}

$db->close();

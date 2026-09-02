<?php

declare(strict_types=1);

require __DIR__ . '/shared/dataset.php';

use Aerospike\Client;
use Aerospike\Key;

/**
 * Reads back every value every writer container stored, using the
 * v2-preview client (Rust extension + Rust daemon). Prints one line per
 * (writer, value type) pair so the matrix script can diff this against the
 * other two readers' output.
 */

$namespace = getenv('AEROSPIKE_NAMESPACE') ?: 'test';
$set = getenv('AEROSPIKE_SET') ?: 'compat-demo';

$client = new Client(getenv('AEROSPIKE_INSTANCE') ?: 'default');

foreach (writerIds() as $writerId) {
    foreach (array_keys(dataset()) as $typeName) {
        $key = new Key($namespace, $set, keyFor($writerId, $typeName));

        try {
            $record = $client->get(null, $key);
            if ($record === null) {
                printf("%-18s %-14s %s\n", $writerId, $typeName, 'MISSING (record not found)');
                continue;
            }
            $value = ($record->bins() ?? [])['value'] ?? null;
            printf("%-18s %-14s %-10s %s\n", $writerId, $typeName, gettype($value), reprValue($value));
        } catch (\Throwable $e) {
            printf("%-18s %-14s ERROR (%s)\n", $writerId, $typeName, $e->getMessage());
        }
    }
}

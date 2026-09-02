<?php

declare(strict_types=1);

require __DIR__ . '/shared/dataset.php';

use Aerospike\Client;
use Aerospike\Key;
use Aerospike\ReadPolicy;

/**
 * Reads back every value every writer container stored, using the v1.4.0
 * client (Rust extension + Go Connection Manager). Prints one line per
 * (writer, value type) pair so the matrix script can diff this against the
 * other two readers' output.
 */

$namespace = getenv('AEROSPIKE_NAMESPACE') ?: 'test';
$set = getenv('AEROSPIKE_SET') ?: 'compat-demo';
$socket = getenv('ASLD_SOCKET') ?: '/tmp/asld_grpc.sock';

$client = Client::connect($socket);
$policy = new ReadPolicy();

foreach (writerIds() as $writerId) {
    foreach (array_keys(dataset()) as $typeName) {
        $key = new Key($namespace, $set, keyFor($writerId, $typeName));

        try {
            $record = $client->get($policy, $key, null);
            if ($record === null) {
                printf("%-18s %-14s %s\n", $writerId, $typeName, 'MISSING (record not found)');
                continue;
            }
            $value = ($record->getBins() ?? [])['value'] ?? null;
            printf("%-18s %-14s %-10s %s\n", $writerId, $typeName, gettype($value), reprValue($value));
        } catch (\Throwable $e) {
            printf("%-18s %-14s ERROR (%s)\n", $writerId, $typeName, $e->getMessage());
        }
    }
}

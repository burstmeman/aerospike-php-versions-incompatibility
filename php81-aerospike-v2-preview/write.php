<?php

declare(strict_types=1);

require __DIR__ . '/shared/dataset.php';

use Aerospike\Bin;
use Aerospike\Client;
use Aerospike\Key;

/**
 * Writes the canonical dataset with the v2-preview client: a thin Rust
 * extension talking, over shared memory, to `aerospike-php-daemon` — a
 * long-lived Rust process that owns the real connection to the server (the
 * pure-Rust successor to v1.4.0's Go-based Connection Manager). No hacks:
 * whatever `put()` does with each PHP value is exactly what this client
 * generation does with it.
 */

$namespace = getenv('AEROSPIKE_NAMESPACE') ?: 'test';
$set = getenv('AEROSPIKE_SET') ?: 'compat-demo';
$writerId = 'php81-v2-preview';

$client = new Client(getenv('AEROSPIKE_INSTANCE') ?: 'default');

foreach (dataset() as $typeName => $value) {
    $key = new Key($namespace, $set, keyFor($writerId, $typeName));

    try {
        $client->put(null, $key, [new Bin('value', $value)]);
        printf("%-14s OK\n", $typeName);
    } catch (\Throwable $e) {
        printf("%-14s FAILED (%s)\n", $typeName, $e->getMessage());
    }
}

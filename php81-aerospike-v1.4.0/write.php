<?php

declare(strict_types=1);

require __DIR__ . '/shared/dataset.php';

use Aerospike\Bin;
use Aerospike\Client;
use Aerospike\Key;
use Aerospike\WritePolicy;

/**
 * Writes the canonical dataset with the v1.4.0 client: a thin Rust
 * extension that forwards every call, over gRPC, to the Aerospike
 * Connection Manager (ACM) — a separate Go process that holds the real
 * connection to the server. No hacks: whatever `put()` does with each PHP
 * value is exactly what this client generation does with it.
 */

$namespace = getenv('AEROSPIKE_NAMESPACE') ?: 'test';
$set = getenv('AEROSPIKE_SET') ?: 'compat-demo';
$writerId = 'php81-v1-4-0';
$socket = getenv('ASLD_SOCKET') ?: '/tmp/asld_grpc.sock';

$client = Client::connect($socket);
$policy = new WritePolicy();

foreach (dataset() as $typeName => $value) {
    $key = new Key($namespace, $set, keyFor($writerId, $typeName));

    try {
        $client->put($policy, $key, [new Bin('value', $value)]);
        printf("%-14s OK\n", $typeName);
    } catch (\Throwable $e) {
        printf("%-14s FAILED (%s)\n", $typeName, $e->getMessage());
    }
}

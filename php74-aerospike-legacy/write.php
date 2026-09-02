<?php

declare(strict_types=1);

require __DIR__ . '/shared/dataset.php';

/**
 * Writes the canonical dataset with the legacy PECL client (`aerospike`
 * extension, C code, talks to the server directly over the native wire
 * protocol). No hacks: whatever `put()` does with each PHP value is exactly
 * what this client generation does with it.
 */

$namespace = getenv('AEROSPIKE_NAMESPACE') ?: 'test';
$set = getenv('AEROSPIKE_SET') ?: 'compat-demo';
$writerId = 'php74';

$db = new Aerospike(['hosts' => [
    ['addr' => getenv('AEROSPIKE_HOST') ?: 'aerospike', 'port' => 3000],
]]);

if (!$db->isConnected()) {
    fwrite(STDERR, "Could not connect to Aerospike: {$db->error()}\n");
    exit(1);
}

foreach (dataset() as $typeName => $value) {
    $key = $db->initKey($namespace, $set, keyFor($writerId, $typeName));
    $status = $db->put($key, ['value' => $value]);

    printf(
        "%-14s %s\n",
        $typeName,
        $status === Aerospike::OK ? 'OK' : "FAILED ({$db->errorno()}: {$db->error()})"
    );
}

$db->close();

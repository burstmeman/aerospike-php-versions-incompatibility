# Aerospike PHP client version incompatibility

Three Aerospike PHP client generations write and read the same dataset
against one server, using only each client's own documented API — no
compatibility shims, no casts.

## Versions

| | PHP | Client | Architecture |
|---|---|---|---|
| **legacy** | 7.4 | [`aerospike-community/aerospike-client-php`](https://github.com/aerospike-community/aerospike-client-php) | C extension, talks to the server directly |
| **v1.4.0** | 8.1 | [`aerospike/php-client`](https://github.com/aerospike/php-client) `main` | Rust ext → gRPC → Go "Connection Manager" sidecar |
| **v2-preview** | 8.1 | [`aerospike/php-client`](https://github.com/aerospike/php-client) `v2-preview` | Rust ext → shared memory → Rust daemon |

## Compatibility matrix

| written by ↓ / read by → | legacy | v1.4.0 | v2-preview |
|---|---|---|---|
| **legacy** | ✅ | ⚠️ | ⚠️ |
| **v1.4.0** | ⚠️ | ✅ | ✅ |
| **v2-preview** | ⚠️ | ✅ | ✅ |

✅ full · ⚠️ partial, broken down below. Diagonal = a client reading back
what it wrote itself.

**Legacy write → new read**
- `string`, `int`: OK
- `bool`, `null` (list/map element): v1.4.0 decodes correctly; v2-preview doesn't — surfaces as `['particle_type' => 11, 'data' => Blob(...)]`
- `object` (bare or nested): neither decodes it — raw undecoded bytes (v1.4.0) or the same wrapped array (v2-preview)

**New write → legacy read**
- `string`, `int`: OK
- `bool` (top-level bin): whole read fails — `ERROR (-1: "Unsupported bytes type")`, not just that bin
- `bool` (list/map element): silently becomes `1`/`0`
- `null` (list/map element): silently dropped, shrinking the list/map
- `object`: write itself fails before reaching the server

**v1.4.0 ↔ v2-preview**: full, same wire types — except writing an object nested in a list/map, which fails cleanly on v2-preview and is unverified on v1.4.0 (see [Why](#why)).

Verified live: legacy same-stack, and everything the legacy reader sees
(written with the official `aerospike-client-go` v7.9.0, same library
v1.4.0 embeds). Not run live: v1.4.0's own decode/write paths — building
it needs a working path to `crates.io`. Reproduce with
[`./run-matrix.sh`](#reproducing-it).

## Compatibility Details

Each block below is real PHP: one array per value type, one element per
client, ordered as commented. `ERROR(...)`, `MISSING()`, `FAILED(...)` are
not real calls — they stand in for a failed read, a record that was never
written, and a failed write, respectively.

<details>
<summary><strong>Write/read by 7.4</strong></summary>

```php
// value written by 7.4, read back by all three

$string = [
    'Hello, Aerospike!',                     // write/read by 7.4
    'Hello, Aerospike!',                     // read by 1.4.0
    'Hello, Aerospike!',                     // read by v2-preview
];

$int_positive = [
    42,                                      // write/read by 7.4
    42,                                      // read by 1.4.0
    42,                                      // read by v2-preview
];

$int_negative = [
    -17,                                     // write/read by 7.4
    -17,                                     // read by 1.4.0
    -17,                                     // read by v2-preview
];

$bool_true = [
    true,                                    // write/read by 7.4
    true,                                    // read by 1.4.0
    ['particle_type' => 11, 'data' => Aerospike\Blob('b:1;')], // read by v2-preview
];

$bool_false = [
    false,                                   // write/read by 7.4
    false,                                   // read by 1.4.0
    ['particle_type' => 11, 'data' => Aerospike\Blob('b:0;')], // read by v2-preview
];

$list_with_bool = [
    [1, 'two', true, false],                 // write/read by 7.4
    [1, 'two', true, false],                 // read by 1.4.0
    [
        1,
        'two',
        ['particle_type' => 11, 'data' => Aerospike\Blob('b:1;')],
        ['particle_type' => 11, 'data' => Aerospike\Blob('b:0;')],
    ], // read by v2-preview
];

$list_with_null = [
    [1, 'two', null],                        // write/read by 7.4
    [1, 'two', null],                        // read by 1.4.0
    [
        1,
        'two',
        ['particle_type' => 11, 'data' => Aerospike\Blob('N;')],
    ], // read by v2-preview
];

$map_with_bool = [
    ['a' => 1, 'b' => 'two', 'c' => true, 'd' => false], // write/read by 7.4
    ['a' => 1, 'b' => 'two', 'c' => true, 'd' => false], // read by 1.4.0
    [
        'a' => 1,
        'b' => 'two',
        'c' => ['particle_type' => 11, 'data' => Aerospike\Blob('b:1;')],
        'd' => ['particle_type' => 11, 'data' => Aerospike\Blob('b:0;')],
    ], // read by v2-preview
];

$map_with_null = [
    ['a' => 1, 'b' => 'two', 'c' => null],   // write/read by 7.4
    ['a' => 1, 'b' => 'two', 'c' => null],   // read by 1.4.0
    [
        'a' => 1,
        'b' => 'two',
        'c' => ['particle_type' => 11, 'data' => Aerospike\Blob('N;')],
    ], // read by v2-preview
];

$php_object = [
    DemoObject(label: 'a plain PHP object', count: 3), // write/read by 7.4
    Aerospike\BLOB('O:10:"DemoObject":2:{s:5:"label";s:18:"a plain PHP object";s:5:"count";i:3;}'), // read by 1.4.0
    [
        'particle_type' => 11,
        'data' => Aerospike\Blob('O:10:"DemoObject":2:{s:5:"label";s:18:"a plain PHP object";s:5:"count";i:3;}'),
    ], // read by v2-preview
];

$list_with_object = [
    [DemoObject(label: 'nested', count: 7)], // write/read by 7.4
    [
        Aerospike\BLOB('O:10:"DemoObject":2:{s:5:"label";s:6:"nested";s:5:"count";i:7;}'),
    ], // read by 1.4.0
    [
        [
            'particle_type' => 11,
            'data' => Aerospike\Blob('O:10:"DemoObject":2:{s:5:"label";s:6:"nested";s:5:"count";i:7;}'),
        ],
    ], // read by v2-preview
];

$map_with_object = [
    ['a' => DemoObject(label: 'nested', count: 7)], // write/read by 7.4
    [
        'a' => Aerospike\BLOB('O:10:"DemoObject":2:{s:5:"label";s:6:"nested";s:5:"count";i:7;}'),
    ], // read by 1.4.0
    [
        'a' => [
            'particle_type' => 11,
            'data' => Aerospike\Blob('O:10:"DemoObject":2:{s:5:"label";s:6:"nested";s:5:"count";i:7;}'),
        ],
    ], // read by v2-preview
];
```

</details>

<details>
<summary><strong>Write/read by 1.4.0</strong></summary>

```php
// value written by 1.4.0, read back by all three

$string = [
    'Hello, Aerospike!',                     // read by 7.4
    'Hello, Aerospike!',                     // write/read by 1.4.0
    'Hello, Aerospike!',                     // read by v2-preview
];

$int_positive = [
    42,                                      // read by 7.4
    42,                                      // write/read by 1.4.0
    42,                                      // read by v2-preview
];

$int_negative = [
    -17,                                     // read by 7.4
    -17,                                     // write/read by 1.4.0
    -17,                                     // read by v2-preview
];

$bool_true = [
    ERROR('Unsupported bytes type'),         // whole record read fails, not just this bin
    true,                                    // write/read by 1.4.0
    true,                                    // read by v2-preview
];

$bool_false = [
    ERROR('Unsupported bytes type'),         // whole record read fails, not just this bin
    false,                                   // write/read by 1.4.0
    false,                                   // read by v2-preview
];

$list_with_bool = [
    [1, 'two', 1, 0],                        // bool became int
    [1, 'two', true, false],                 // write/read by 1.4.0
    [1, 'two', true, false],                 // read by v2-preview
];

$list_with_null = [
    [1, 'two'],                              // null silently dropped
    [1, 'two', null],                        // write/read by 1.4.0
    [1, 'two', null],                        // read by v2-preview
];

$map_with_bool = [
    ['a' => 1, 'b' => 'two', 'c' => 1, 'd' => 0], // bool became int
    ['a' => 1, 'b' => 'two', 'c' => true, 'd' => false], // write/read by 1.4.0
    ['a' => 1, 'b' => 'two', 'c' => true, 'd' => false], // read by v2-preview
];

$map_with_null = [
    ['a' => 1, 'b' => 'two'],                // null silently dropped
    ['a' => 1, 'b' => 'two', 'c' => null],   // write/read by 1.4.0
    ['a' => 1, 'b' => 'two', 'c' => null],   // read by v2-preview
];

$php_object = [
    MISSING(),                               // record not found
    FAILED('Invalid input for argument `value`'), // write/read by 1.4.0
    MISSING(),                               // record not found
];

$list_with_object = [
    MISSING(),                               // record not found
    FAILED('exact outcome not verified'),    // write never confirmed live — see Why
    MISSING(),                               // record not found
];

$map_with_object = [
    MISSING(),                               // record not found
    FAILED('exact outcome not verified'),    // write never confirmed live — see Why
    MISSING(),                               // record not found
];
```

</details>

<details>
<summary><strong>Write/read by v2-preview</strong></summary>

```php
// value written by v2-preview, read back by all three

$string = [
    'Hello, Aerospike!',                     // read by 7.4
    'Hello, Aerospike!',                     // read by 1.4.0
    'Hello, Aerospike!',                     // write/read by v2-preview
];

$int_positive = [
    42,                                      // read by 7.4
    42,                                      // read by 1.4.0
    42,                                      // write/read by v2-preview
];

$int_negative = [
    -17,                                     // read by 7.4
    -17,                                     // read by 1.4.0
    -17,                                     // write/read by v2-preview
];

$bool_true = [
    ERROR('Unsupported bytes type'),         // whole record read fails, not just this bin
    true,                                    // read by 1.4.0
    true,                                    // write/read by v2-preview
];

$bool_false = [
    ERROR('Unsupported bytes type'),         // whole record read fails, not just this bin
    false,                                   // read by 1.4.0
    false,                                   // write/read by v2-preview
];

$list_with_bool = [
    [1, 'two', 1, 0],                        // bool became int
    [1, 'two', true, false],                 // read by 1.4.0
    [1, 'two', true, false],                 // write/read by v2-preview
];

$list_with_null = [
    [1, 'two'],                              // null silently dropped
    [1, 'two', null],                        // read by 1.4.0
    [1, 'two', null],                        // write/read by v2-preview
];

$map_with_bool = [
    ['a' => 1, 'b' => 'two', 'c' => 1, 'd' => 0], // bool became int
    ['a' => 1, 'b' => 'two', 'c' => true, 'd' => false], // read by 1.4.0
    ['a' => 1, 'b' => 'two', 'c' => true, 'd' => false], // write/read by v2-preview
];

$map_with_null = [
    ['a' => 1, 'b' => 'two'],                // null silently dropped
    ['a' => 1, 'b' => 'two', 'c' => null],   // read by 1.4.0
    ['a' => 1, 'b' => 'two', 'c' => null],   // write/read by v2-preview
];

$php_object = [
    MISSING(),                               // record not found
    MISSING(),                               // record not found
    FAILED(
        'bin "value" is an instance of DemoObject, which Aerospike cannot store. '
        . 'A bin takes null, bool, int, float, string, array, or an Aerospike\Blob, '
        . 'Aerospike\GeoJson, Aerospike\Hll, Aerospike\OrderedMap or Aerospike\SortedMap'
    ), // write/read by v2-preview
];

$list_with_object = [
    MISSING(),                               // record not found
    MISSING(),                               // record not found
    FAILED('bin "value"[0] is an instance of DemoObject, which Aerospike cannot store...'), // write/read by v2-preview
];

$map_with_object = [
    MISSING(),                               // record not found
    MISSING(),                               // record not found
    FAILED('bin "value"["a"] is an instance of DemoObject, which Aerospike cannot store...'), // write/read by v2-preview
];
```

</details>

## Why

- Aerospike tags every value with a particle type. `string`, `int`, and CDT containers (`list`, `map`) have stable types across all three generations.
- The legacy client has no `bool` type and no generic blob type. Any value it can't map natively falls back to PHP `serialize()`, tagged particle type 11 (`PHP_BLOB`): `false` → `b:0;`, `null` → `N;`, an object → its serialized bytes.
- v1.4.0's Go client ([`aerospike-client-go`](https://github.com/aerospike/aerospike-client-go)) hardcodes a decoder for exactly `b:1;`/`b:0;`/`N;` → native bool/null, at any CDT depth. Anything else tagged particle type 11 (e.g. a serialized object) stays raw bytes.
- v2-preview has no decoder for particle type 11 at all — surfaces `{particle_type, data}` instead of guessing.
- New clients write `bool` as native particle type `BOOL = 17`, added after the legacy client was written. Legacy's top-level bin decode fails outright on a type it doesn't recognize (`ERROR -1`); its CDT-element decode is more permissive — it reinterprets an unrecognized `bool` as an integer and drops an unrecognized `null` instead of erroring.
- Object writes fail on both new clients, but differently: v2-preview's array→wire conversion is `Result`-based and fails cleanly, naming the exact path (`ext/src/value.rs`). v1.4.0's equivalent calls `.unwrap()` on an `Option` (`src/lib.rs`) — a Rust panic on an object element, not a caught error; not verified at runtime here.
- Documented, not a secret: `PHP_BLOB`'s own comment says it exists "to support the old PHP7 client," and v2-preview's README covers 1.x migration directly.

## Layout

```
docker-compose.yml            Aerospike server + one container per client
shared/dataset.php             canonical dataset, PHP 7.4 syntax (shared by all three)
php74-aerospike-legacy/        legacy image: Dockerfile, write.php, read.php
php81-aerospike-v1.4.0/        v1.4.0 image: Rust ext + Go ACM, entrypoint, config
php81-aerospike-v2-preview/    v2-preview image: Rust ext + Rust daemon, entrypoint, config
run-matrix.sh                  build + write with all three + read with all three
```

## Reproducing it

Needs Docker + Compose v2, and internet access for `github.com`,
`static.rust-lang.org`/`sh.rustup.rs`, `go.dev`, `crates.io`. Every service
is pinned to `linux/amd64` (the legacy client's C SDK is x86_64-only).

```sh
git clone https://github.com/<org>/aerospike-php-versions-incompatibility.git
cd aerospike-php-versions-incompatibility
./run-matrix.sh
```

Builds all three images, writes the dataset with each, reads every
writer's data back with each, and saves raw output under `results/`. One
client on its own:

```sh
docker compose run --rm php74 php write.php
docker compose run --rm php81-v2-preview php read.php
```

## License

MIT. Independent of Aerospike, Inc.; not affiliated with or endorsed by it.

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

Every cell below is exactly what `reprValue()` in
[`shared/dataset.php`](shared/dataset.php) prints — real PHP, not JSON:
`[...]` is a list, `['k' => v, ...]` is an associative array.

### Write/read by 7.4

| value type | Write/read by 7.4 | Read by 1.4.0 | Read by v2-preview |
|---|---|---|---|
| <pre>string</pre> | <pre>'Hello, Aerospike!'</pre> | <pre>'Hello, Aerospike!'</pre> | <pre>'Hello, Aerospike!'</pre> |
| <pre>int_positive</pre> | <pre>42</pre> | <pre>42</pre> | <pre>42</pre> |
| <pre>int_negative</pre> | <pre>-17</pre> | <pre>-17</pre> | <pre>-17</pre> |
| <pre>bool_true</pre> | <pre>true</pre> | <pre>true</pre> | <pre>[<br>  'particle_type' => 11,<br>  'data' => Aerospike\Blob('b:1;')<br>]</pre> |
| <pre>bool_false</pre> | <pre>false</pre> | <pre>false</pre> | <pre>[<br>  'particle_type' => 11,<br>  'data' => Aerospike\Blob('b:0;')<br>]</pre> |
| <pre>list_with_bool</pre> | <pre>[<br>  1,<br>  'two',<br>  true,<br>  false<br>]</pre> | <pre>[<br>  1,<br>  'two',<br>  true,<br>  false<br>]</pre> | <pre>[<br>  1,<br>  'two',<br>  [<br>    'particle_type' => 11,<br>    'data' => Aerospike\Blob('b:1;')<br>  ],<br>  [<br>    'particle_type' => 11,<br>    'data' => Aerospike\Blob('b:0;')<br>  ]<br>]</pre> |
| <pre>list_with_null</pre> | <pre>[<br>  1,<br>  'two',<br>  null<br>]</pre> | <pre>[<br>  1,<br>  'two',<br>  null<br>]</pre> | <pre>[<br>  1,<br>  'two',<br>  [<br>    'particle_type' => 11,<br>    'data' => Aerospike\Blob('N;')<br>  ]<br>]</pre> |
| <pre>map_with_bool</pre> | <pre>[<br>  'a' => 1,<br>  'b' => 'two',<br>  'c' => true,<br>  'd' => false<br>]</pre> | <pre>[<br>  'a' => 1,<br>  'b' => 'two',<br>  'c' => true,<br>  'd' => false<br>]</pre> | <pre>[<br>  'a' => 1,<br>  'b' => 'two',<br>  'c' => [<br>    'particle_type' => 11,<br>    'data' => Aerospike\Blob('b:1;')<br>  ],<br>  'd' => [<br>    'particle_type' => 11,<br>    'data' => Aerospike\Blob('b:0;')<br>  ]<br>]</pre> |
| <pre>map_with_null</pre> | <pre>[<br>  'a' => 1,<br>  'b' => 'two',<br>  'c' => null<br>]</pre> | <pre>[<br>  'a' => 1,<br>  'b' => 'two',<br>  'c' => null<br>]</pre> | <pre>[<br>  'a' => 1,<br>  'b' => 'two',<br>  'c' => [<br>    'particle_type' => 11,<br>    'data' => Aerospike\Blob('N;')<br>  ]<br>]</pre> |
| <pre>php_object</pre> | <pre>DemoObject(label: 'a plain PHP object', count: 3)</pre> | <pre>Aerospike\BLOB('O:10:"DemoObject":2:{s:5:"label";s:18:"a plain PHP object";s:5:"count";i:3;}')</pre> | <pre>[<br>  'particle_type' => 11,<br>  'data' => Aerospike\Blob('O:10:"DemoObject":2:{s:5:"label";s:18:"a plain PHP object";s:5:"count";i:3;}')<br>]</pre> |
| <pre>list_with_object</pre> | <pre>[<br>  DemoObject(label: 'nested', count: 7)<br>]</pre> | <pre>[<br>  Aerospike\BLOB('O:10:"DemoObject":2:{s:5:"label";s:6:"nested";s:5:"count";i:7;}')<br>]</pre> | <pre>[<br>  [<br>    'particle_type' => 11,<br>    'data' => Aerospike\Blob('O:10:"DemoObject":2:{s:5:"label";s:6:"nested";s:5:"count";i:7;}')<br>  ]<br>]</pre> |
| <pre>map_with_object</pre> | <pre>[<br>  'a' => DemoObject(label: 'nested', count: 7)<br>]</pre> | <pre>[<br>  'a' => Aerospike\BLOB('O:10:"DemoObject":2:{s:5:"label";s:6:"nested";s:5:"count";i:7;}')<br>]</pre> | <pre>[<br>  'a' => [<br>    'particle_type' => 11,<br>    'data' => Aerospike\Blob('O:10:"DemoObject":2:{s:5:"label";s:6:"nested";s:5:"count";i:7;}')<br>  ]<br>]</pre> |

### Write/read by 1.4.0

| value type | Read by 7.4 | Write/read by 1.4.0 | Read by v2-preview |
|---|---|---|---|
| <pre>string</pre> | <pre>'Hello, Aerospike!'</pre> | <pre>'Hello, Aerospike!'</pre> | <pre>'Hello, Aerospike!'</pre> |
| <pre>int_positive</pre> | <pre>42</pre> | <pre>42</pre> | <pre>42</pre> |
| <pre>int_negative</pre> | <pre>-17</pre> | <pre>-17</pre> | <pre>-17</pre> |
| <pre>bool_true</pre> | <pre>ERROR (-1: "Unsupported bytes type")</pre> | <pre>true</pre> | <pre>true</pre> |
| <pre>bool_false</pre> | <pre>ERROR (-1: "Unsupported bytes type")</pre> | <pre>false</pre> | <pre>false</pre> |
| <pre>list_with_bool</pre> | <pre>[<br>  1,<br>  'two',<br>  1,<br>  0<br>]</pre> | <pre>[<br>  1,<br>  'two',<br>  true,<br>  false<br>]</pre> | <pre>[<br>  1,<br>  'two',<br>  true,<br>  false<br>]</pre> |
| <pre>list_with_null</pre> | <pre>[<br>  1,<br>  'two'<br>]</pre> | <pre>[<br>  1,<br>  'two',<br>  null<br>]</pre> | <pre>[<br>  1,<br>  'two',<br>  null<br>]</pre> |
| <pre>map_with_bool</pre> | <pre>[<br>  'a' => 1,<br>  'b' => 'two',<br>  'c' => 1,<br>  'd' => 0<br>]</pre> | <pre>[<br>  'a' => 1,<br>  'b' => 'two',<br>  'c' => true,<br>  'd' => false<br>]</pre> | <pre>[<br>  'a' => 1,<br>  'b' => 'two',<br>  'c' => true,<br>  'd' => false<br>]</pre> |
| <pre>map_with_null</pre> | <pre>[<br>  'a' => 1,<br>  'b' => 'two'<br>]</pre> | <pre>[<br>  'a' => 1,<br>  'b' => 'two',<br>  'c' => null<br>]</pre> | <pre>[<br>  'a' => 1,<br>  'b' => 'two',<br>  'c' => null<br>]</pre> |
| <pre>php_object</pre> | <pre>MISSING (record not found)</pre> | <pre>FAILED (Invalid input for argument `value`)</pre> | <pre>MISSING (record not found)</pre> |
| <pre>list_with_object</pre> | <pre>MISSING (record not found)</pre> | <pre>FAILED (exact outcome not verified)</pre> | <pre>MISSING (record not found)</pre> |
| <pre>map_with_object</pre> | <pre>MISSING (record not found)</pre> | <pre>FAILED (exact outcome not verified)</pre> | <pre>MISSING (record not found)</pre> |

### Write/read by v2-preview

| value type | Read by 7.4 | Read by 1.4.0 | Write/read by v2-preview |
|---|---|---|---|
| <pre>string</pre> | <pre>'Hello, Aerospike!'</pre> | <pre>'Hello, Aerospike!'</pre> | <pre>'Hello, Aerospike!'</pre> |
| <pre>int_positive</pre> | <pre>42</pre> | <pre>42</pre> | <pre>42</pre> |
| <pre>int_negative</pre> | <pre>-17</pre> | <pre>-17</pre> | <pre>-17</pre> |
| <pre>bool_true</pre> | <pre>ERROR (-1: "Unsupported bytes type")</pre> | <pre>true</pre> | <pre>true</pre> |
| <pre>bool_false</pre> | <pre>ERROR (-1: "Unsupported bytes type")</pre> | <pre>false</pre> | <pre>false</pre> |
| <pre>list_with_bool</pre> | <pre>[<br>  1,<br>  'two',<br>  1,<br>  0<br>]</pre> | <pre>[<br>  1,<br>  'two',<br>  true,<br>  false<br>]</pre> | <pre>[<br>  1,<br>  'two',<br>  true,<br>  false<br>]</pre> |
| <pre>list_with_null</pre> | <pre>[<br>  1,<br>  'two'<br>]</pre> | <pre>[<br>  1,<br>  'two',<br>  null<br>]</pre> | <pre>[<br>  1,<br>  'two',<br>  null<br>]</pre> |
| <pre>map_with_bool</pre> | <pre>[<br>  'a' => 1,<br>  'b' => 'two',<br>  'c' => 1,<br>  'd' => 0<br>]</pre> | <pre>[<br>  'a' => 1,<br>  'b' => 'two',<br>  'c' => true,<br>  'd' => false<br>]</pre> | <pre>[<br>  'a' => 1,<br>  'b' => 'two',<br>  'c' => true,<br>  'd' => false<br>]</pre> |
| <pre>map_with_null</pre> | <pre>[<br>  'a' => 1,<br>  'b' => 'two'<br>]</pre> | <pre>[<br>  'a' => 1,<br>  'b' => 'two',<br>  'c' => null<br>]</pre> | <pre>[<br>  'a' => 1,<br>  'b' => 'two',<br>  'c' => null<br>]</pre> |
| <pre>php_object</pre> | <pre>MISSING (record not found)</pre> | <pre>MISSING (record not found)</pre> | <pre>FAILED (bin "value" is an instance of DemoObject, which Aerospike cannot store. A bin takes null, bool, int, float, string, array, or an Aerospike\Blob, Aerospike\GeoJson, Aerospike\Hll, Aerospike\OrderedMap or Aerospike\SortedMap)</pre> |
| <pre>list_with_object</pre> | <pre>MISSING (record not found)</pre> | <pre>MISSING (record not found)</pre> | <pre>FAILED (bin "value"[0] is an instance of DemoObject, which Aerospike cannot store...)</pre> |
| <pre>map_with_object</pre> | <pre>MISSING (record not found)</pre> | <pre>MISSING (record not found)</pre> | <pre>FAILED (bin "value"["a"] is an instance of DemoObject, which Aerospike cannot store...)</pre> |

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

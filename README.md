# Aerospike PHP client version incompatibility

Three generations of the Aerospike PHP client, in three PHP versions, all
pointed at the same server, writing and reading the same data:

| | PHP | Client | Architecture |
|---|---|---|---|
| **legacy** | 7.4 | [`aerospike-community/aerospike-client-php`](https://github.com/aerospike-community/aerospike-client-php) | C extension, talks to the server directly |
| **v1.4.0** | 8.1 | [`aerospike/php-client`](https://github.com/aerospike/php-client) (branch `main`) | Rust extension → gRPC → a Go "Connection Manager" sidecar → the server |
| **v2-preview** | 8.1 | [`aerospike/php-client`](https://github.com/aerospike/php-client) (branch `v2-preview`) | Rust extension → shared memory → a Rust daemon → the server |

None of these three can be loaded into the same PHP process — they are
mutually exclusive native extensions. So this repo builds **one Docker image
per client**, points all three at one Aerospike server, and has each one
write a fixed set of values under its own name, so every other client can
try reading them back. No compatibility shims, no `unserialize()` fallbacks,
no cast tricks — every write and every read is the raw call the client
documents. Whatever a client does with a value on its own is exactly what
shows up here.

## Compatibility matrix

**How to read this.** Rows are who wrote the value, columns are who read it
back. `full` means every value type round-trips correctly; `partial` means
some types work and some don't (see the per-type tables below); `broken`
means the read is actively wrong, not just missing.

| written by ↓ / read by → | legacy (7.4) | v1.4.0 | v2-preview |
|---|---|---|---|
| **legacy (7.4)**   | full    | partial — see below | partial — see below |
| **v1.4.0**          | partial — see below | full    | full (same wire types) |
| **v2-preview**      | partial — see below | full (same wire types) | full |

**Confidence.** The legacy client's own row (write with legacy, read back
with legacy — including `php_object` reconstructing correctly) was confirmed
by actually building this repo's `php74` image and running it against a real
server. The legacy ↔ v2-preview cross-stack cells were confirmed the same
way in an earlier, separate harness using these same two client generations.
The new-client-writes cells that don't depend on v1.4.0-specific decode
logic (i.e. everything the legacy reader does, since it only ever sees wire
bytes and doesn't care which client produced them) were confirmed directly:
writing with the official, vendored
[`aerospike-client-go`](https://github.com/aerospike/aerospike-client-go)
v7.9.0 — the same library v1.4.0's Connection Manager embeds — against a
live server, then reading with this repo's actual `php74` image. The
remaining cells (what v1.4.0 itself decodes) are derived from reading that
same library's source rather than from building the full v1.4.0 Rust/Go
stack end-to-end *in this repo*. Run [`./run-matrix.sh`](#reproducing-it) to
get a matrix from a live run on your own machine; please open an issue with
the output if anything here turns out to be wrong.

### Written by legacy (7.4)

| value type | read by legacy | read by v1.4.0 | read by v2-preview |
|---|---|---|---|
| `string`         | `"Hello, Aerospike!"` | same | same |
| `int_positive`   | `42` | same | same |
| `int_negative`   | `-17` | same | same |
| `bool_true`      | `true` | `true` | **`['particle_type' => 11, 'data' => Blob("b:1;")]`** |
| `bool_false`     | `false` | `false` | **`['particle_type' => 11, 'data' => Blob("b:0;")]`** |
| `list_with_bool` | `[1, two, true, false]` | same | `[1, two, {particle_type: 11, data: Blob("b:1;")}, {particle_type: 11, data: Blob("b:0;")}]` |
| `list_with_null` | `[1, two, null]` | same | `[1, two, {particle_type: 11, data: Blob("N;")}]` |
| `map_with_bool`  | `{a: 1, b: two, c: true, d: false}` | same | same per-leaf wrapping as `list_with_bool`, keyed instead of indexed |
| `map_with_null`  | `{a: 1, b: two, c: null}` | same | same per-leaf wrapping as `list_with_null`, keyed instead of indexed |
| `php_object`     | a reconstructed `DemoObject` instance | raw undecoded bytes (a `serialize()` blob is not `b:0;`/`b:1;`/`N;`, see explanation) | raw undecoded bytes / wrapper, see explanation |
| `list_with_object` | `[DemoObject{label: "nested", count: 7}]` — the object round-trips too, same mechanism as `php_object` | predicted: raw undecoded bytes for that element, not verified in this repo | predicted: wrapped shape for that element, not verified in this repo |
| `map_with_object`  | `{a: DemoObject{label: "nested", count: 7}}` | predicted, not verified | predicted, not verified |

### Written by v1.4.0

| value type | read by legacy | read by v1.4.0 | read by v2-preview |
|---|---|---|---|
| `string`         | `"Hello, Aerospike!"` | same | same |
| `int_positive`   | `42` | same | same |
| `int_negative`   | `-17` | same | same |
| `bool_true`      | **`get()` fails: `ERROR (-1: Unsupported bytes type)` — the whole record, not just this bin** | `true` | `true` |
| `bool_false`     | same failure as `bool_true` | `false` | `false` |
| `list_with_bool` | **`[1, two, 1, 0]`** — the `bool` elements silently decode as the integers `1`/`0`, not an error | `[1, two, true, false]` | same |
| `list_with_null` | **`[1, two]`** — the `null` element is silently dropped, shrinking the list from 3 elements to 2 | `[1, two, null]` | same |
| `map_with_bool`  | **`{a: 1, b: two, c: 1, d: 0}`** — same silent `bool`→integer reinterpretation | `{a: 1, b: two, c: true, d: false}` | same |
| `map_with_null`  | **`{a: 1, b: two}`** — the `null`-valued key silently vanishes | `{a: 1, b: two, c: null}` | same |
| `php_object`     | **write fails before it reaches the server** — `new Aerospike\Bin('value', $object)` throws, there is no bin to read | (n/a) | (n/a) |
| `list_with_object` | (n/a) — **write may not fail cleanly**, see [Objects](#objects) | (n/a) | (n/a) |
| `map_with_object`  | (n/a) — same caveat as `list_with_object` | (n/a) | (n/a) |

### Written by v2-preview

Identical to the v1.4.0 table above, with one exception. Every scalar and
CDT row is the same: the legacy reader only ever sees wire bytes, and both
newer clients produce the same particle types for these values (native
`BOOL = 17`, native CDT elements) — and v1.4.0 and v2-preview read each
other's writes identically too (`full`, same wire types). `php_object` on
its own is the same as well: both `Bin` constructors reject a bare PHP
object before anything is sent.

`list_with_object`/`map_with_object` are where the two newer clients
diverge from each other, in how the write itself fails — see
[Objects](#objects) for why v2-preview's failure is a clean, well-formed
exception naming the exact array path, while v1.4.0's is a different, less
robust code path this repo has not been able to exercise to find out what
it actually does at runtime.

## Why

### The short version

Aerospike tags every stored value with a **particle type** — a byte on the
wire that says how to decode what follows. The relevant ones here, as the
official Go client enumerates them:

```go
// github.com/aerospike/aerospike-client-go, types/particle_type
NULL     = 0
INTEGER  = 1
STRING   = 3
BLOB     = 4
PHP_BLOB = 11 // Had to reintroduce to support the old PHP7 client
BOOL     = 17
MAP      = 19
LIST     = 20
```

`string`, `int` and the CDT container types (`map`, `list`) have had stable
particle types across every client generation, which is why they round-trip
everywhere in the matrix above. `bool`, `null` (as a list/map element) and
"none of the above" (a PHP object) are where the three generations
disagree, broken down one at a time below.

All three problem types below share one root cause: the legacy client has
no `bool` bin type and no generic "unknown value" bin type, so for **any**
PHP value it cannot map onto a native Aerospike type — a bare `bool`, `null`
as a map/list leaf, or an arbitrary object — it falls back to PHP's own
`serialize()` and tags the resulting bytes with particle type 11
(`PHP_BLOB`, described in Aerospike's own client-interop docs as the
"foreign blob" type each language SDK gets one of — Java's is 7). A PHP
`false` becomes the bytes `b:0;`, `null` becomes `N;`, an object becomes
whatever `serialize()` produces for it — and all of it reads back correctly
through the *same* client via `unserialize()`. What differs is what each
newer client does with those bytes, and what each newer client's *own*
native type looks like to the legacy reader.

### `bool`

- **old → v1.4.0: works.** v1.4.0's Rust extension never talks to the
  server itself — its Go "Connection Manager" sidecar does, using the
  official [`aerospike-client-go`](https://github.com/aerospike/aerospike-client-go)
  library. That library's decoder has a **hand-written special case** for
  particle type 11, in both the top-level value decoder and the CDT
  (map/list) element decoder — condensed here for readability, not a
  verbatim quote (see `value.go` and `unpacker.go` in that repo for the real
  thing):

  ```go
  case ParticleType.PHP_BLOB:
      switch {
      case count == 4 && bytes.Equal(buf, []byte{0x62, 0x3A, 0x31, 0x3B}): // "b:1;"
          val = true
      case count == 4 && bytes.Equal(buf, []byte{0x62, 0x3A, 0x30, 0x3B}): // "b:0;"
          val = false
      case count == 2 && bytes.Equal(buf, []byte{0x4E, 0x3B}): // "N;"
          val = nil
      default:
          val = rawBytes // anything else: an opaque blob, not decoded
      }
  ```

  It recognizes exactly the byte sequences for PHP's `serialize(true)` and
  `serialize(false)` (and, relevant to the next section, `serialize(null)`)
  and turns them into native Go `true`/`false`/`nil`, which flows through
  the gRPC hop to PHP as a real `bool` with no PHP-side code involved. A
  plain, unconditional part of the vendored library — every `Get()`
  benefits, no opt-in needed.

- **old → v2-preview: broken.** v2-preview's Rust extension talks to the
  server through its own Rust daemon, built directly on `aerospike-core`,
  which has no reason to know about a PHP-specific legacy particle type —
  it has **no decoder for particle type 11 at all**. The bin comes back as
  a generic `['particle_type' => 11, 'data' => Aerospike\Blob]` shape
  instead of a `bool` — not silently wrong, but not usable without writing
  code that knows this shape exists, which defeats the purpose of a typed
  client.

- **new → old, as the whole bin: the read fails outright, not silently.**
  Both newer clients write `bool` as Aerospike's **native** boolean
  particle type, `BOOL = 17` — added well after the legacy PHP7 client was
  written. Confirmed directly against a live server — writing with the same
  `aerospike-client-go` library v1.4.0 embeds, reading with this repo's
  actual `php74` image: `Aerospike::get()` returns a non-`OK` status,
  `-1` / `"Unsupported bytes type"`, for the **entire record** — not just
  that bin. This held even with a second, perfectly ordinary bin alongside
  it: the whole read fails the moment *any* one of the record's bins
  carries a particle type the client's top-level dispatch doesn't
  recognize, not merely that one bin.

- **new → old, as a list/map element: silently reinterpreted as an
  integer.** A `bool` *inside* a CDT is a different code path from a `bool`
  *bin*, and it fails differently: `list_with_bool` (`[1, "two", true,
  false]`) comes back as `[1, "two", 1, 0]` — no error, `true`/`false`
  silently become the integers `1`/`0`. The CDT element decoder is
  evidently more permissive about an unrecognized element type than the
  top-level bin-type dispatch is, which makes this the quietest — and
  arguably worst — failure mode of the three: no exception, no missing
  data, just a `bool` that has silently become the wrong type.

### `null` (only meaningful as a list/map element — see [the dataset note](shared/dataset.php))

- **old → v1.4.0: works,** for the same reason as `bool` — the same decoder
  snippet above also matches `N;` (PHP's `serialize(null)`) and produces a
  native Go `nil`, at any CDT nesting depth.
- **old → v2-preview: broken,** for the same reason as `bool` — no decoder
  for particle type 11, so the leaf comes back wrapped instead of as `null`.
- **new → old: silently dropped, not an error.** Confirmed the same way as
  the `bool`-as-element case above, with `list_with_null` and
  `map_with_null` kept separate from the `bool` cases specifically so this
  result can be attributed to `null` alone rather than to a `bool` sibling:
  `[1, "two", null]` comes back as `[1, "two"]`, and `{a: 1, b: "two", c:
  null}` comes back as `{a: 1, b: "two"}`. No error, no placeholder — the
  element is simply removed, which is arguably worse for calling code than
  either an exception or a literal `null`: `count()` on the result changes,
  and nothing about the response says why.

### Objects

- **old → old (same stack): works, including nested.** Confirmed directly:
  a `DemoObject` round-trips as a bare bin, as a `list` element
  (`list_with_object`), and as a `map` value (`map_with_object`) — the
  legacy client's `serialize()` fallback applies per-element, recursively,
  not just at the top level, so a whole object graph nested inside a CDT
  comes back fully reconstructed.
- **old → v1.4.0: broken (undecoded bytes, not a reconstructed object).**
  The byte-pattern decoder shown above recognizes exactly two sequences —
  `b:1;` and `b:0;` (this repo's dataset never needs the `N;` case outside
  a list/map, see above). A serialized object's bytes (`O:8:"DemoObject":…`)
  match neither, so they fall through to the `default` case as an opaque,
  undecoded blob. This is a narrow compatibility patch for the two scalar
  cases the legacy client falls back to `serialize()` for most often, not a
  general `unserialize()`.
- **old → v2-preview: broken (undecoded bytes, wrapped instead of raw).**
  Same root cause as `bool`/`null` — no particle-type-11 decoder at all, so
  the object's bytes surface inside the generic
  `['particle_type' => 11, 'data' => Aerospike\Blob]` shape.
- **new → old, as a bare bin: the write itself fails, cleanly.** Neither
  v1.4.0's nor v2-preview's `Bin` constructor recognizes a PHP object as a
  valid value at all (confirmed in both Rust extensions' source: the value
  conversion match has no arm for it), so `new Aerospike\Bin('value',
  $object)` throws before anything reaches the server. There is nothing for
  any reader to read back.
- **new → old, as a list/map element: still fails, but the two clients get
  there very differently — confirmed from source, not run.** v2-preview's
  array conversion (`table_to_wire` in `ext/src/value.rs`) is a proper
  `Result`-returning recursive function: each element is converted with
  `?`, so an object nested at any depth surfaces as one clean,
  well-formed exception naming the exact path (`value[0] is an object of
  class DemoObject, which Aerospike cannot store…`) — the same quality of
  failure as the bare-bin case. v1.4.0's equivalent (the free `from_zval`
  function in `src/lib.rs`) builds a list with
  `arr.iter().map(|(_, v)| from_zval(v).unwrap())`: for an object element,
  the inner `from_zval` call throws its own PHP exception *and* returns
  `None`, and that `None` then hits `.unwrap()` — a Rust panic, not a
  `Result::Err`. Whether that panic reaches PHP as a second, differently
  worded exception, a fatal error, or something worse depends on whether
  `ext-php-rs` catches a panic at that specific call boundary, which this
  repo has not been able to verify empirically (see the confidence note at
  the top): building v1.4.0 needs a working path to `crates.io`, and this
  particular case was found by reading the source, not by running it.

### Not a hack, a documented gap

None of this is undocumented mystery behavior. `PHP_BLOB`'s comment in the
Go client's own source says exactly what it is for
(`// Had to reintroduce to support the old PHP7 client`), and the v2-preview
client's own README is explicit that migrating from the 1.x line is a
documented, ongoing concern (see its
[`compat/`](https://github.com/aerospike/php-client/tree/v2-preview/compat)
directory and README section on coming from the 1.x client). The
incompatibility is real; it is also not a secret.

## Layout

```
docker-compose.yml              one Aerospike server + one container per client
shared/dataset.php              the canonical values every writer attempts, PHP 7.4 syntax
php74-aerospike-7.4/            legacy client image, write.php, read.php
php81-aerospike-v1.4.0/         v1.4.0 image (Rust ext + Go ACM), entrypoint, config
php81-aerospike-v2-preview/     v2-preview image (Rust ext + Rust daemon), entrypoint, config
run-matrix.sh                   builds everything, writes with all three, reads with all three
```

Every `write.php`/`read.php` pair is intentionally small: build a client the
way that client's own documentation shows, loop over
[`shared/dataset.php`](shared/dataset.php)'s values, and print one line per
value type. `shared/dataset.php` is written to PHP 7.4 syntax on purpose —
it is `require`d by all three containers, including the 7.4 one, and the
whole comparison is only meaningful if every client is handed values built
exactly the same way.

## Reproducing it

Requires Docker with Compose v2 (`docker compose`, no hyphen) and internet
access — the v1.4.0 and v2-preview images build their extensions from
source (Rust + Cargo, and for v1.4.0 also Go), which needs to reach
`github.com`, `static.rust-lang.org` / `sh.rustup.rs`, `go.dev` and
`crates.io`. Every service is pinned to `linux/amd64`, because the legacy
client's C SDK dependency only ships prebuilt x86_64 binaries — on
non-amd64 hosts (Apple Silicon, an arm64 CI runner) Docker will build and
run it under emulation, which works but is slower.

```sh
git clone https://github.com/<org>/aerospike-php-versions-incompatibility.git
cd aerospike-php-versions-incompatibility
./run-matrix.sh
```

`run-matrix.sh` builds all three client images, starts a single Aerospike
server, has each client write the dataset under its own name, then has each
client read every writer's data back — saving the raw output of each step
under `results/`. Reading the transcripts directly is more informative than
any summary: each `read.php` line is `writer  value-type  php-type  value`,
so a `MISSING`, an `ERROR`, or a value that doesn't match what was written is
visible without any further processing.

To poke at one client on its own:

```sh
docker compose run --rm php74 php write.php
docker compose run --rm php81-v2-preview php read.php
```

## License

MIT. This repository is independent of Aerospike, Inc. and is not affiliated
with or endorsed by it; it exists to document and reproduce publicly visible
behavior of publicly available client libraries.

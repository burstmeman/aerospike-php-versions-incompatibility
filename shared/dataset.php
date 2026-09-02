<?php

declare(strict_types=1);

/**
 * Written against PHP 7.4 syntax on purpose, even though two of the three
 * writer/reader containers run PHP 8.1: this file is `require`d by all
 * three, and the whole comparison is only fair if every client is handed
 * values built the exact same way. Anything 8.0+-only (constructor property
 * promotion, `readonly`, `match`, union/`mixed` types, `array_is_list()`)
 * would make this file fail to parse under 7.4 before a single Aerospike
 * call happens.
 */

/**
 * A plain PHP object, used as the "none of these clients have a native bin
 * type for this" example. Not `stdClass`: a real class with named
 * properties reads as an ordinary application value would, which is the
 * point — nobody actually stores an anonymous object.
 */
final class DemoObject
{
    /** @var string */
    public $label;

    /** @var int */
    public $count;

    public function __construct(string $label, int $count)
    {
        $this->label = $label;
        $this->count = $count;
    }
}

/**
 * The canonical values every writer container attempts to store, and the
 * canonical writer identifiers every reader container looks for.
 *
 * Kept in exactly one place because the whole point of this repository falls
 * apart if the writers do not all attempt to store the same thing: any
 * observed difference must come from the client, not from the test data.
 *
 * @return array<string, mixed> value-type name => value
 */
function dataset(): array
{
    return [
        'string'       => 'Hello, Aerospike!',
        'int_positive' => 42,
        'int_negative' => -17,
        'bool_true'    => true,
        'bool_false'   => false,
        // A `null` bin on its own would just delete the bin (Aerospike's
        // write semantics), so `null` is only interesting as an element
        // nested inside a list or a map, never as a top-level value.
        //
        // `bool` and `null` elements are kept in *separate* lists/maps
        // rather than combined into one "mixed" case: a legacy CDT decoder
        // that gives up on a whole bin over one element type it can't name
        // would make a combined case fail for both types at once, without
        // saying which one actually caused it. Isolated, each case's
        // read result is attributable to exactly one element type.
        'list_with_bool'   => [1, 'two', true, false],
        'list_with_null'   => [1, 'two', null],
        'map_with_bool'    => ['a' => 1, 'b' => 'two', 'c' => true, 'd' => false],
        'map_with_null'    => ['a' => 1, 'b' => 'two', 'c' => null],
        'php_object'       => new DemoObject('a plain PHP object', 3),
        // Same isolation logic as bool/null above: a PHP object as a list/map
        // element, on its own with no other elements, so a failure here is
        // attributable to the object alone.
        'list_with_object' => [new DemoObject('nested', 7)],
        'map_with_object'  => ['a' => new DemoObject('nested', 7)],
    ];
}

/**
 * One Aerospike set per writer, so all three writer containers can run
 * against the same server without one overwriting another's records.
 *
 * @return string[]
 */
function writerIds(): array
{
    return ['php74', 'php81-v1-4-0', 'php81-v2-preview'];
}

/** The record key a given writer used to store a given value type. */
function keyFor(string $writerId, string $typeName): string
{
    return "$writerId:$typeName";
}

/** PHP 7.4 has no `array_is_list()` (added in 8.1). */
function isList(array $value): bool
{
    return $value === [] || array_keys($value) === range(0, count($value) - 1);
}

/**
 * A short, readable rendering of whatever came back from a read — including
 * shapes no client fully understands, such as an opaque byte-blob wrapper or
 * an array carrying a raw, undecoded particle type. Never throws: a demo
 * whose reporting script crashes on the exact input it exists to show off
 * would defeat the point.
 *
 * @param mixed $value
 */
function reprValue($value): string
{
    if (is_null($value)) {
        return 'null';
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_scalar($value)) {
        return (string) $value;
    }
    if (is_array($value)) {
        return reprArray($value);
    }
    if (is_object($value)) {
        return reprObject($value);
    }
    return gettype($value);
}

/** @param array<array-key, mixed> $value */
function reprArray(array $value): string
{
    $list = isList($value);
    $parts = [];
    foreach ($value as $key => $item) {
        $prefix = $list ? '' : var_export($key, true) . ' => ';
        $parts[] = $prefix . reprValue($item);
    }
    return '[' . implode(', ', $parts) . ']';
}

/**
 * Every client wraps bytes it cannot natively decode in its own class
 * (`Aerospike\Bytes`, `Aerospike\Blob`, ...). Rather than hard-coding each
 * one, this reads whatever byte-accessor the object happens to expose.
 */
function reprObject(object $value): string
{
    if (method_exists($value, 'bytes')) {
        $bytes = $value->bytes();
    } elseif (property_exists($value, 's')) {
        $bytes = $value->s;
    } else {
        $bytes = null;
    }

    if ($bytes === null) {
        return get_class($value) . ' ' . json_encode(get_object_vars($value), JSON_UNESCAPED_UNICODE);
    }

    $printable = preg_match('/^[\x20-\x7E]*$/', $bytes) === 1;

    return get_class($value) . '(' . ($printable ? "\"$bytes\"" : '0x' . bin2hex($bytes)) . ')';
}

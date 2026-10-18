<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Pipeline;

use JOetjen\Cooper\Node\MapNode;
use JOetjen\Cooper\Value\CooperSecret;
use JOetjen\Cooper\Value\CooperTuple;

/**
 * The very last pipeline step: turns the internal `MapNode` maps into
 * the plain associative `array`s a caller gets back, recursing through
 * lists, tuples, and secrets, and gives a `Module` marker back as its
 * class-string. Everything else -- scalars, value objects,
 * whatever a consumer-registered resolver or tag returned -- passes
 * through untouched.
 *
 * A map key PHP considers numeric (`"0"`, `"42"`) becomes an `int`
 * array key; that is PHP's own array semantics and cannot be avoided in
 * a plain `array`.
 *
 * @internal
 */
final class Native
{
    private function __construct()
    {
    }

    public static function convert(mixed $value): mixed
    {
        if ($value instanceof MapNode) {
            $out = [];
            foreach ($value->entries() as $key => $item) {
                $out[$key] = self::convert($item);
            }

            return $out;
        }
        if (is_array($value)) {
            return array_map(self::convert(...), $value);
        }
        if ($value instanceof CooperTuple) {
            return new CooperTuple(array_map(self::convert(...), $value->items));
        }
        if ($value instanceof CooperSecret) {
            return new CooperSecret(self::convert($value->reveal()), $value->redacted);
        }
        if ($value instanceof Module) {
            return $value->classString();
        }

        return $value;
    }
}

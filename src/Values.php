<?php

declare(strict_types=1);

namespace JOetjen\Cooper;

use JOetjen\Cooper\Node\MapNode;
use JOetjen\Cooper\Value\CooperSecret;

/**
 * Structural equality over loaded values -- what `-key = [...]`
 * (CASC.md §8.4) removes by, and what the value classes' own `equals()`
 * methods build on.
 *
 * Strict where PHP's `==` is loose: `1` and `1.0` differ, as do `"1"`
 * and `1` (the Elixir original's exact-match `in`). Arrays compare by
 * keys and values in order; objects of the same class compare by their
 * own `equals()` when they have one.
 */
final class Values
{
    private function __construct()
    {
    }

    /**
     * Whether `$a` and `$b` are structurally equal.
     *
     * @param mixed $a the first value
     * @param mixed $b the second value
     * @return bool true when both hold the same value
     */
    public static function equals(mixed $a, mixed $b): bool
    {
        if (is_array($a) || is_array($b)) {
            if (!is_array($a) || !is_array($b) || count($a) !== count($b)) {
                return false;
            }
            if (array_keys($a) !== array_keys($b)) {
                return false;
            }
            foreach ($a as $key => $value) {
                if (!self::equals($value, $b[$key])) {
                    return false;
                }
            }

            return true;
        }

        if (is_object($a) || is_object($b)) {
            if (!is_object($a) || !is_object($b) || $a::class !== $b::class) {
                return false;
            }
            if ($a instanceof MapNode && $b instanceof MapNode) {
                return self::equals($a->entries(), $b->entries());
            }
            if ($a instanceof CooperSecret && $b instanceof CooperSecret) {
                return $a->redacted === $b->redacted && self::equals($a->reveal(), $b->reveal());
            }
            if (method_exists($a, 'equals')) {
                return (bool) $a->equals($b);
            }

            return $a == $b;
        }

        return $a === $b;
    }
}

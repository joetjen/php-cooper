<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Value;

/**
 * A CASC integer (CASC.md §6.3) too large for PHP's 64-bit `int` --
 * `12345678901234567890`, or `!int("...")` of one -- kept exactly, as
 * the Elixir original's arbitrary-precision integers are.
 *
 * Only ever constructed for a magnitude outside `PHP_INT_MIN..
 * PHP_INT_MAX` (see `represent()`, the one entry point the pipeline
 * uses): an integer that fits stays a native `int`, so `port = 8080`
 * never needs unwrapping. This is php-dextrin's own hybrid rule, backed
 * by `bcmath` the same way. Rendering, comparison, and the digits are
 * all a config value needs; arithmetic is left to the caller (`bcadd()`
 * and friends take `$digits` directly).
 */
final class CooperInteger implements \Stringable
{
    /**
     * Canonical decimal digits: no leading zeros, `-` only when negative.
     *
     * @var numeric-string
     */
    public readonly string $digits;

    /**
     * @param string $digits decimal digits, optionally signed with `-`
     * @throws \InvalidArgumentException when `$digits` is not a decimal integer
     */
    public function __construct(string $digits)
    {
        if (preg_match('/\A-?\d+\z/', $digits) !== 1) {
            throw new \InvalidArgumentException('not a decimal integer: ' . json_encode($digits));
        }
        $negative = str_starts_with($digits, '-');
        $magnitude = ltrim($negative ? substr($digits, 1) : $digits, '0');
        $canonical = $magnitude === '' ? '0' : ($negative ? "-{$magnitude}" : $magnitude);
        assert(is_numeric($canonical));
        $this->digits = $canonical;
    }

    /**
     * The value of `$digits` as CASC holds it: a native `int` when it
     * fits in one, a `CooperInteger` otherwise.
     *
     * @param string $digits decimal digits, optionally signed with `-`
     * @return int|self the integer
     */
    public static function represent(string $digits): int|self
    {
        $value = new self($digits);

        return $value->fitsInInt() ? (int) $value->digits : $value;
    }

    /**
     * @return bool whether the value lies within `PHP_INT_MIN..PHP_INT_MAX`
     */
    public function fitsInInt(): bool
    {
        return bccomp($this->digits, (string) PHP_INT_MIN, 0) >= 0 && bccomp($this->digits, (string) PHP_INT_MAX, 0) <= 0;
    }

    /**
     * @param mixed $other the value to compare against
     * @return bool true for a `CooperInteger` with the same value -- never
     *              for an `int` or a float, which strict CASC equality
     *              (§8.4) keeps apart
     */
    public function equals(mixed $other): bool
    {
        return $other instanceof self && $other->digits === $this->digits;
    }

    /**
     * @return float the nearest float, as `!float(...)` converts it
     */
    public function toFloat(): float
    {
        return (float) $this->digits;
    }

    /**
     * The decimal digits -- also valid CASC if fed back in, and what the
     * value reads as inside a string (§7).
     *
     * @return string the digits
     */
    public function __toString(): string
    {
        return $this->digits;
    }
}

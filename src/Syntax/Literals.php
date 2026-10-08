<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Syntax;

use JOetjen\Cooper\Value\CooperInteger;

/**
 * Literal-text conversions shared by `Evaluator` (bare literals) and
 * `Resolver` (the `!int`/`!duration`/`!bytes`/... tagged forms, which
 * CASC.md §7.5 says follow the same rules): numbers (§6.3), strings
 * (§6.5), durations (§6.8), and byte sizes (§6.9). Every method throws
 * `\InvalidArgumentException` with a message naming exactly what is
 * wrong; callers turn that into a staged `CooperError`.
 *
 * An integer is PHP's 64-bit `int` when it fits, and a `CooperInteger`
 * holding every digit when it does not -- the Elixir original's integers
 * are arbitrary-precision, and so are CASC's. A duration's or byte
 * size's count follows the same rule: it was once held to 64 bits, and a
 * total past them was a load error the reference never raised.
 *
 * @internal
 */
final class Literals
{
    private const DURATION_NS = [
        'ns' => 1,
        'us' => 1_000,
        'µs' => 1_000,
        'ms' => 1_000_000,
        's' => 1_000_000_000,
        'm' => 60_000_000_000,
        'h' => 3_600_000_000_000,
        'd' => 86_400_000_000_000,
    ];

    private const DURATION_RANK = ['d' => 7, 'h' => 6, 'm' => 5, 's' => 4, 'ms' => 3, 'us' => 2, 'µs' => 2, 'ns' => 1];

    private const BYTE_MULTIPLIER = [
        'b' => 1,
        'kb' => 1_000,
        'mb' => 1_000_000,
        'gb' => 1_000_000_000,
        'tb' => 1_000_000_000_000,
        'pb' => 1_000_000_000_000_000,
        'kib' => 1024,
        'mib' => 1024 ** 2,
        'gib' => 1024 ** 3,
        'tib' => 1024 ** 4,
        'pib' => 1024 ** 5,
    ];

    private const ESCAPES = ['n' => "\n", 'r' => "\r", 't' => "\t", '"' => '"', '\\' => '\\'];

    private function __construct()
    {
    }

    /**
     * An `INTEGER` token's value: optional sign, `0x`/`0o`/`0b` prefix,
     * `_` digit separators. A native `int` when it fits in one, a
     * `CooperInteger` otherwise.
     */
    public static function integer(string $text): int|CooperInteger
    {
        $negative = str_starts_with($text, '-');
        $body = ltrim($text, '+-');
        $body = str_replace('_', '', $body);

        $base = 10;
        $prefix = strtolower(substr($body, 0, 2));
        if ($prefix === '0x' || $prefix === '0o' || $prefix === '0b') {
            $base = ['0x' => 16, '0o' => 8, '0b' => 2][$prefix];
            $body = substr($body, 2);
        }

        $value = 0;
        $digits = strtolower($body);
        $length = strlen($digits);
        for ($i = 0; $i < $length; $i++) {
            $digit = (int) base_convert($digits[$i], 36, 10);
            // Accumulate toward the sign's own limit, so PHP_INT_MIN
            // (one larger in magnitude than PHP_INT_MAX) is reachable;
            // past it, the whole literal is redone in arbitrary precision.
            if ($negative) {
                if ($value < intdiv(PHP_INT_MIN + $digit, $base)) {
                    return self::bigInteger($negative, $digits, $base);
                }
                $value = $value * $base - $digit;
            } else {
                if ($value > intdiv(PHP_INT_MAX - $digit, $base)) {
                    return self::bigInteger($negative, $digits, $base);
                }
                $value = $value * $base + $digit;
            }
        }

        return $value;
    }

    /**
     * `integer()` for a literal past PHP's `int`, accumulated with
     * `bcmath` -- the slow path only an overflowing literal takes.
     */
    private static function bigInteger(bool $negative, string $digits, int $base): CooperInteger
    {
        $value = '0';
        $length = strlen($digits);
        for ($i = 0; $i < $length; $i++) {
            $digit = (string) (int) base_convert($digits[$i], 36, 10);
            $value = bcadd(bcmul($value, (string) $base, 0), $digit, 0);
        }

        return new CooperInteger($negative ? "-{$value}" : $value);
    }

    /**
     * A `FLOAT` token's value (`_` digit separators allowed).
     *
     * @throws \InvalidArgumentException when the value overflows to infinity
     */
    public static function float(string $text): float
    {
        $value = (float) str_replace('_', '', $text);
        if (is_infinite($value)) {
            throw new \InvalidArgumentException('float literal ' . json_encode($text) . ' is out of range');
        }

        return $value;
    }

    /**
     * A duration literal's total nanoseconds (CASC.md §6.8). A
     * single-unit literal may be fractional; a compound one needs
     * integer components, strictly descending, no unit repeated.
     *
     * @return int|CooperInteger the count, a `CooperInteger` beyond 64 bits
     * @throws \InvalidArgumentException when `$text` breaks one of those rules
     */
    public static function duration(string $text): int|CooperInteger
    {
        // Each component's digits may carry `_` separators (CASC.md
        // §6.8: "Digit separators (`_`) are allowed per §6.3"), the shape
        // the DURATION token accepts: a digit first, then digits or `_`.
        preg_match_all('/(\d[\d_]*+(?:\.\d[\d_]*+)?+)(ns|us|µs|ms|d|h|m|s)/', $text, $matches, PREG_SET_ORDER);

        // Re-joining every component and comparing with the original is
        // what rejects garbage between components ("5msXYZ9s").
        $joined = implode('', array_map(static fn (array $m): string => $m[0], $matches));
        if ($matches === [] || $joined !== $text) {
            throw new \InvalidArgumentException('invalid duration literal ' . json_encode($text, JSON_UNESCAPED_UNICODE));
        }
        // The separators are dropped from the amounts only once the text
        // is known to be nothing but components.
        $matches = array_map(static fn (array $m): array => [$m[0], str_replace('_', '', $m[1]), $m[2]], $matches);

        if (count($matches) === 1) {
            [, $amount, $unit] = $matches[0];

            return self::scaled($amount, self::DURATION_NS[$unit]);
        }

        $quoted = json_encode($text, JSON_UNESCAPED_UNICODE);
        $ranks = [];
        foreach ($matches as [, $amount, $unit]) {
            if (str_contains($amount, '.')) {
                throw new \InvalidArgumentException("duration {$quoted}: compound literals must use integer components");
            }
            $ranks[] = self::DURATION_RANK[$unit];
        }
        if (count(array_unique($ranks)) !== count($ranks)) {
            throw new \InvalidArgumentException("duration {$quoted}: a unit is repeated");
        }
        $sorted = $ranks;
        rsort($sorted);
        if ($sorted !== $ranks) {
            throw new \InvalidArgumentException("duration {$quoted}: units must be strictly descending");
        }

        // Summed as exact integers, as the reference sums them, so every
        // digit counts: `1000000000000d1ns` ends in ...0001.
        $total = '0';
        foreach ($matches as [, $amount, $unit]) {
            $total = bcadd($total, bcmul(self::digits($amount), (string) self::DURATION_NS[$unit], 0), 0);
        }

        return CooperInteger::represent($total);
    }

    /**
     * A byte-size literal's total bytes (CASC.md §6.9), unit matched
     * case-insensitively.
     *
     * @return int|CooperInteger the count, a `CooperInteger` beyond 64 bits
     * @throws \InvalidArgumentException on an unknown unit or malformed text
     */
    public static function bytes(string $text): int|CooperInteger
    {
        if (preg_match('/\A(\d+(?:\.\d+)?)([a-zA-Z]+)\z/', $text, $m) !== 1) {
            throw new \InvalidArgumentException('invalid byte-size literal ' . json_encode($text));
        }
        $multiplier = self::BYTE_MULTIPLIER[strtolower($m[2])] ?? null;
        if ($multiplier === null) {
            throw new \InvalidArgumentException('invalid byte-size unit in ' . json_encode($text));
        }

        return self::scaled($m[1], $multiplier);
    }

    /**
     * Processes a double-quoted string's escapes (`\n`, `\r`, `\t`,
     * `\"`, `\\`, `\uXXXX`); any other escaped character stands for
     * itself, as in the Elixir original.
     *
     * @throws \InvalidArgumentException on a `\u` escape naming a surrogate
     */
    public static function unescape(string $text): string
    {
        return (string) preg_replace_callback('/\\\\(u[0-9a-fA-F]{4}|.)/', static function (array $m): string {
            $escaped = $m[1];
            if (strlen($escaped) === 5 && $escaped[0] === 'u') {
                $codepoint = (int) hexdec(substr($escaped, 1));
                if ($codepoint >= 0xD800 && $codepoint <= 0xDFFF) {
                    throw new \InvalidArgumentException("invalid unicode escape \\{$escaped}: a surrogate is not a character");
                }

                return (string) mb_chr($codepoint, 'UTF-8');
            }

            return self::ESCAPES[$escaped] ?? $escaped;
        }, $text);
    }

    /**
     * A triple-quoted string's content (CASC.md §6.5): drops the newline
     * right after the opening `"""`, then strips the smallest common
     * leading whitespace across non-blank lines.
     */
    public static function dedent(string $content): string
    {
        if (str_starts_with($content, "\n")) {
            $content = substr($content, 1);
        }
        $lines = explode("\n", $content);

        $indent = null;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $count = strspn($line, " \t");
            $indent = $indent === null ? $count : min($indent, $count);
        }
        $indent ??= 0;

        return implode("\n", array_map(static fn (string $line): string => mb_substr($line, $indent), $lines));
    }

    /**
     * `$amount` (integer or decimal text) times `$factor`, exactly, rounded
     * half away from zero -- `1.5B` is 2 bytes, `0.1ns` is 0, `0.5ns` is
     * 1 -- as the Elixir original computes it: the amount's digits times
     * the factor, over 10^(fraction digits), as `div(2n + d, 2d)`.
     *
     * Both once went through a float, in the reference and (to match it)
     * here: past 2^53 that gave the nearest double rather than the
     * product (`9007199254740993ns` was ...992), and an amount too large
     * for a float crashed the reference and was an error here. No float
     * is involved now, and any amount loads.
     */
    private static function scaled(string $amount, int $factor): int|CooperInteger
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $numerator = bcmul(self::digits($whole . $fraction), (string) $factor, 0);
        $denominator = bcpow('10', (string) strlen($fraction), 0);

        return CooperInteger::represent(bcdiv(bcadd(bcmul('2', $numerator, 0), $denominator, 0), bcmul('2', $denominator, 0), 0));
    }

    /**
     * An amount's decimal digits (separators and any `.` already
     * dropped) as `bcmath` digits.
     *
     * @return numeric-string
     */
    private static function digits(string $amount): string
    {
        $digits = ltrim($amount, '0');
        $digits = $digits === '' ? '0' : $digits;
        assert(is_numeric($digits));

        return $digits;
    }
}

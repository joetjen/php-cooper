<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Pipeline;

use JOetjen\Cooper\Node\MapNode;
use JOetjen\Cooper\Value\CooperAtom;
use JOetjen\Cooper\Value\CooperInteger;
use JOetjen\Cooper\Value\CooperSecret;
use JOetjen\Cooper\Value\CooperTuple;

/**
 * Stringifies a resolved value for embedding in an interpolated string
 * (CASC.md §7) -- shared by `Loop` (a loop binding substituted into a
 * key or body string) and `Resolver`.
 *
 * Renders exactly what the Elixir original's `to_string/1` does for the
 * same value, so one CASC document interpolates to the same text in
 * every Cooper port -- CASC.md §7's "a value interpolated into a string
 * reads as CASC writes it": floats in Erlang's shortest form (`1.0`,
 * `52.52`, `1.0e3`), `nil` as `"nil"`, infinity as `"inf"`/`"-inf"`,
 * durations and byte sizes in their base unit (`500000000ns`, `512B`),
 * and offset date-times in UTC as `1979-05-27 05:32:00Z`, with the
 * fractional digits as written (`CooperDateTime`). A `DateTimeInterface`
 * a consumer's own tag or resolver returns has no written precision; it
 * renders the same way, its fraction without trailing zeros.
 *
 * Lists, maps, and tuples have no string form and are an error here; the
 * reference once crashed on a tuple and concatenated a list's elements
 * as raw bytes.
 *
 * `inspect()` renders a value for an error message, after Elixir's
 * `inspect/1`.
 *
 * @internal
 */
final class Display
{
    private function __construct()
    {
    }

    /**
     * @throws \InvalidArgumentException for a value with no string form
     */
    public static function toString(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value) => (string) $value,
            $value instanceof CooperInteger => $value->digits,
            // CASC's own spelling (§6.3), which reads back as the same
            // value -- not the reference's internal `infinity` atom name.
            is_float($value) && is_infinite($value) => $value > 0 ? 'inf' : '-inf',
            is_float($value) => self::float($value),
            $value === true => 'true',
            $value === false => 'false',
            $value === null => 'nil',
            $value instanceof CooperAtom => $value->name,
            $value instanceof CooperSecret => (string) $value,
            $value instanceof \DateTimeInterface => self::dateTime($value),
            $value instanceof \Stringable => (string) $value,
            default => throw new \InvalidArgumentException('cannot interpolate ' . self::inspect($value) . ' into a string'),
        };
    }

    /**
     * Whether `toString()` accepts `$value`.
     */
    public static function displayable(mixed $value): bool
    {
        return is_scalar($value) || $value === null || $value instanceof \Stringable || $value instanceof \DateTimeInterface;
    }

    /**
     * A float in Erlang's shortest round-trip form (`float_to_binary(F,
     * [short])`): the fewest significant digits that read back to the
     * same float, written in decimal notation (always with a fraction,
     * `100.0`) unless scientific notation (`1.0e3`) is strictly shorter
     * -- or the magnitude is at least 2^53, where integers stop being
     * exactly representable and Erlang always switches to scientific.
     */
    public static function float(float $value): string
    {
        if (is_infinite($value)) {
            return $value > 0 ? 'inf' : '-inf';
        }
        if (is_nan($value)) {
            return 'nan';
        }
        if ($value == 0.0) {
            return (fdiv(1, $value) < 0) ? '-0.0' : '0.0';
        }

        $sign = $value < 0 ? '-' : '';
        $abs = abs($value);
        for ($precision = 0; $precision <= 17; $precision++) {
            $repr = sprintf("%.{$precision}e", $abs);
            if ((float) $repr === $abs) {
                break;
            }
        }
        [$mantissa, $exponentText] = explode('e', $repr);
        $digits = str_replace('.', '', $mantissa);
        $exponent = (int) $exponentText;
        $digits = rtrim($digits, '0');
        if ($digits === '') {
            $digits = '0';
        }
        $count = strlen($digits);

        $scientific = $digits[0] . '.' . ($count > 1 ? substr($digits, 1) : '0') . 'e' . $exponent;
        if ($exponent >= $count - 1) {
            $decimal = $digits . str_repeat('0', $exponent - ($count - 1)) . '.0';
        } elseif ($exponent >= 0) {
            $decimal = substr($digits, 0, $exponent + 1) . '.' . substr($digits, $exponent + 1);
        } else {
            $decimal = '0.' . str_repeat('0', -$exponent - 1) . $digits;
        }

        $useScientific = $abs >= 9007199254740992.0 || strlen($scientific) < strlen($decimal);

        return $sign . ($useScientific ? $scientific : $decimal);
    }

    /**
     * A value rendered for an error message, after Elixir's `inspect/1`:
     * strings quoted, atoms with a leading `:`, `nil` for null.
     */
    public static function inspect(mixed $value): string
    {
        return match (true) {
            is_string($value) => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            // The reference holds infinity as an atom, and inspects it so.
            is_float($value) && is_infinite($value) => $value > 0 ? ':infinity' : ':neg_infinity',
            is_float($value) => self::float($value),
            $value === null => 'nil',
            is_bool($value), is_int($value) => self::toString($value),
            $value instanceof CooperAtom => ':' . $value->name,
            $value instanceof CooperSecret => CooperSecret::MARKER,
            $value instanceof CooperTuple => '{' . implode(', ', array_map(self::inspect(...), $value->items)) . '}',
            $value instanceof MapNode => '%{' . implode(', ', array_map(
                static fn (string $key, mixed $item): string => self::inspect($key) . ' => ' . self::inspect($item),
                $value->keys(),
                array_values($value->entries()),
            )) . '}',
            is_array($value) => array_is_list($value)
                ? '[' . implode(', ', array_map(self::inspect(...), $value)) . ']'
                : '%{' . implode(', ', array_map(
                    static fn (int|string $key, mixed $item): string => self::inspect((string) $key) . ' => ' . self::inspect($item),
                    array_keys($value),
                    $value,
                )) . '}',
            $value instanceof \DateTimeInterface => self::dateTime($value),
            $value instanceof \Stringable => (string) $value,
            is_object($value) => '#' . $value::class,
            default => get_debug_type($value),
        };
    }

    private static function dateTime(\DateTimeInterface $value): string
    {
        $utc = \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'));
        $fraction = rtrim($utc->format('u'), '0');

        return $utc->format('Y-m-d H:i:s') . ($fraction === '' ? '' : ".{$fraction}") . 'Z';
    }
}

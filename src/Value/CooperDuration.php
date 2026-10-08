<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Value;

/**
 * A CASC duration (CASC.md §6.8) -- `500ms`, `1h30m`, or
 * `!duration("5m")` -- as a whole number of nanoseconds, the same base
 * unit the Elixir original's `{:duration, nanoseconds}` uses.
 *
 * The count follows the integer rule (`CooperInteger`): a native `int`
 * when it fits in 64 bits, a `CooperInteger` beyond. It was once a plain
 * `int`, and `300000000d` -- about 820 000 years, which the reference
 * loads without complaint -- was a load error here.
 */
final class CooperDuration implements \Stringable
{
    /**
     * The duration's total length in nanoseconds.
     */
    public readonly int|CooperInteger $nanoseconds;

    /**
     * @param int|CooperInteger $nanoseconds the duration's total length in
     *                                       nanoseconds; a `CooperInteger`
     *                                       that fits is kept as an `int`
     */
    public function __construct(int|CooperInteger $nanoseconds)
    {
        $this->nanoseconds = $nanoseconds instanceof CooperInteger
            ? CooperInteger::represent($nanoseconds->digits)
            : $nanoseconds;
    }

    /**
     * @return int|CooperInteger the duration in whole microseconds,
     *                           truncated toward zero
     */
    public function toMicroseconds(): int|CooperInteger
    {
        return self::divided($this->nanoseconds, 1_000);
    }

    /**
     * @return int|CooperInteger the duration in whole milliseconds,
     *                           truncated toward zero
     */
    public function toMilliseconds(): int|CooperInteger
    {
        return self::divided($this->nanoseconds, 1_000_000);
    }

    /**
     * @return float the duration in (fractional) seconds -- the nearest
     *               float, for a count beyond 64 bits
     */
    public function toSeconds(): float
    {
        $nanoseconds = $this->nanoseconds instanceof CooperInteger ? $this->nanoseconds->toFloat() : $this->nanoseconds;

        return $nanoseconds / 1_000_000_000;
    }

    /**
     * @param mixed $other the value to compare against
     * @return bool true for a duration of the same length
     */
    public function equals(mixed $other): bool
    {
        // Both counts are canonical (an `int` whenever one fits), so
        // equal lengths have equal digits.
        return $other instanceof self && (string) $other->nanoseconds === (string) $this->nanoseconds;
    }

    /**
     * The duration in its base unit -- `"500000000ns"` -- which is also
     * valid CASC if fed back in.
     *
     * @return string the nanosecond count with an `ns` suffix
     */
    public function __toString(): string
    {
        return $this->nanoseconds . 'ns';
    }

    /**
     * `$count` divided by `$divisor`, truncated toward zero.
     */
    private static function divided(int|CooperInteger $count, int $divisor): int|CooperInteger
    {
        if (is_int($count)) {
            return intdiv($count, $divisor);
        }

        return CooperInteger::represent(bcdiv($count->digits, (string) $divisor, 0));
    }
}

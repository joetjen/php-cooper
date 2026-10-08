<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Value;

/**
 * A CASC offset date-time (CASC.md §6.6) -- `1979-05-27T07:32:00Z`,
 * `...00.120+02:00` -- matching Elixir's `DateTime`: an instant at
 * microsecond resolution, normalized to UTC, together with how many
 * fractional digits were written (`$precision`, at most 6).
 *
 * It was once a bare `DateTimeImmutable`, which holds the microseconds
 * but not their count, so `.120` came back as `.12`, `.0` as no fraction
 * at all, and `.5` and `.50` -- different values to the reference's
 * strict equality -- were the same. `toDateTimeImmutable()` hands the
 * instant to code that wants PHP's own type.
 */
final class CooperDateTime implements \Stringable, \JsonSerializable
{
    /**
     * The instant, in UTC.
     */
    public readonly \DateTimeImmutable $instant;

    /**
     * @param \DateTimeInterface $instant the instant, in any zone; held in UTC
     * @param int $precision how many fractional digits to render, `0..6`
     * @throws \InvalidArgumentException when `$precision` is out of range
     */
    public function __construct(\DateTimeInterface $instant, public readonly int $precision = 0)
    {
        if ($precision < 0 || $precision > 6) {
            throw new \InvalidArgumentException("invalid date-time precision: {$precision}");
        }
        $this->instant = \DateTimeImmutable::createFromInterface($instant)->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * Parses `YYYY-MM-DDTHH:MM:SS[.fff]` followed by `Z` or `±HH:MM`.
     * Fractional digits past the sixth are truncated, as Elixir's
     * `DateTime.from_iso8601/1` truncates them -- never rounded up into
     * the next second.
     *
     * @param string $text e.g. `"1979-05-27T07:32:00.120+02:00"`
     * @return self the instant, in UTC
     * @throws \InvalidArgumentException when `$text` is not a valid offset date-time
     */
    public static function parse(string $text): self
    {
        if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})T([0-9:.]+)(Z|[+-]\d{2}:\d{2})\z/', $text, $m) !== 1) {
            throw new \InvalidArgumentException('invalid date-time ' . json_encode($text));
        }
        // Both checked first: `DateTimeImmutable` would roll `02-30`
        // over into March, and an hour past 23 over into the next day.
        $date = new CooperDate((int) $m[1], (int) $m[2], (int) $m[3]);
        $time = CooperTime::parse($m[4]);
        $offset = $m[5];
        // `-00:00` is RFC 3339's "offset unknown" rather than UTC, and
        // the reference's `DateTime.from_iso8601/1` refuses it; this port
        // once read it as `Z`.
        if ($offset === '-00:00' || ($offset !== 'Z' && ((int) substr($offset, 1, 2) > 23 || (int) substr($offset, 4, 2) > 59))) {
            throw new \InvalidArgumentException('invalid UTC offset in ' . json_encode($text));
        }

        $instant = \DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i:s.uP',
            sprintf('%sT%02d:%02d:%02d.%06d%s', $date, $time->hour, $time->minute, $time->second, $time->microsecond, $offset === 'Z' ? '+00:00' : $offset),
        );
        if ($instant === false) {
            throw new \InvalidArgumentException('invalid date-time ' . json_encode($text));
        }

        return new self($instant, $time->precision);
    }

    /**
     * The instant as PHP's own type, in `$timezone` (default: UTC).
     *
     * @param \DateTimeZone|null $timezone the zone to express the instant in
     * @return \DateTimeImmutable the same instant
     */
    public function toDateTimeImmutable(?\DateTimeZone $timezone = null): \DateTimeImmutable
    {
        return $timezone === null ? $this->instant : $this->instant->setTimezone($timezone);
    }

    /**
     * @return string the ISO 8601 form in UTC, `YYYY-MM-DDTHH:MM:SS[.fff]Z`
     */
    public function toIso8601(): string
    {
        return $this->instant->format('Y-m-d\TH:i:s') . $this->fraction() . 'Z';
    }

    /**
     * @param mixed $other the value to compare against
     * @return bool true for the same instant written with the same
     *              precision -- Elixir's strict equality on `DateTime`
     */
    public function equals(mixed $other): bool
    {
        return $other instanceof self && $other->toIso8601() === $this->toIso8601();
    }

    /**
     * Space-separated, as Elixir's `DateTime` prints -- the form an
     * interpolated `"@{when}"` produces in every Cooper implementation.
     * Use `toIso8601()` for the `T`-separated form.
     *
     * @return string `YYYY-MM-DD HH:MM:SS[.fff]Z`
     */
    public function __toString(): string
    {
        return $this->instant->format('Y-m-d H:i:s') . $this->fraction() . 'Z';
    }

    /**
     * Encodes as the ISO 8601 form (`toIso8601()`).
     *
     * @return string the `T`-separated form
     */
    public function jsonSerialize(): string
    {
        return $this->toIso8601();
    }

    /**
     * @return string `.` and the first `$precision` microsecond digits, or
     *                nothing at precision 0
     */
    private function fraction(): string
    {
        return $this->precision === 0 ? '' : '.' . substr($this->instant->format('u'), 0, $this->precision);
    }
}

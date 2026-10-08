<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Value;

/**
 * A CASC local date (CASC.md §6.6) -- `1979-05-27` -- with no time or
 * zone attached, matching Elixir's `Date`. PHP has no date-only type,
 * and a `DateTimeImmutable` at midnight would invent a time and a zone
 * the document never stated; `toDateTimeImmutable()` makes that choice
 * explicit when it is wanted.
 */
final class CooperDate implements \Stringable, \JsonSerializable
{
    /**
     * @param int $year the year
     * @param int $month the month, `1..12`
     * @param int $day the day of the month
     * @throws \InvalidArgumentException when the date does not exist
     */
    public function __construct(
        public readonly int $year,
        public readonly int $month,
        public readonly int $day,
    ) {
        // `checkdate()` takes years from 1 only; the reference's
        // proleptic Gregorian calendar has a year 0 (`0000-02-29` is
        // real), which shares 2000's leap rule, and refusing it once made
        // `0000-01-01` a load error here and nowhere else.
        if (!checkdate($month, $day, $year === 0 ? 2000 : $year)) {
            throw new \InvalidArgumentException(sprintf('invalid date: %04d-%02d-%02d', $year, $month, $day));
        }
    }

    /**
     * Midnight on this date, in `$timezone` (default: PHP's default zone).
     *
     * @param \DateTimeZone|null $timezone the zone to place the date in
     * @return \DateTimeImmutable midnight at the start of this date
     */
    public function toDateTimeImmutable(?\DateTimeZone $timezone = null): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('now', $timezone))
            ->setDate($this->year, $this->month, $this->day)
            ->setTime(0, 0);
    }

    /**
     * @param mixed $other the value to compare against
     * @return bool true for the same calendar date
     */
    public function equals(mixed $other): bool
    {
        return $other instanceof self && (string) $other === (string) $this;
    }

    /**
     * @return string the ISO 8601 form, `YYYY-MM-DD`
     */
    public function __toString(): string
    {
        return sprintf('%04d-%02d-%02d', $this->year, $this->month, $this->day);
    }

    /**
     * Encodes as the same text `__toString()` returns.
     *
     * @return string the string form
     */
    public function jsonSerialize(): string
    {
        return $this->__toString();
    }
}

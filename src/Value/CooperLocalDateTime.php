<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Value;

/**
 * A CASC local date-time (CASC.md §6.6) -- `1979-05-27T07:32:00` with
 * no offset -- matching Elixir's `NaiveDateTime`. An *offset* date-time
 * (`...Z`, `...+02:00`) is a real instant and loads as a
 * `CooperDateTime` instead; this one names a wall-clock reading in no
 * particular zone, which `toDateTimeImmutable()` lets the caller place.
 */
final class CooperLocalDateTime implements \Stringable, \JsonSerializable
{
    /**
     * @param CooperDate $date the calendar date
     * @param CooperTime $time the time of day
     */
    public function __construct(
        public readonly CooperDate $date,
        public readonly CooperTime $time,
    ) {
    }

    /**
     * This wall-clock reading, in `$timezone` (default: PHP's default zone).
     *
     * @param \DateTimeZone|null $timezone the zone to interpret the reading in
     * @return \DateTimeImmutable the corresponding instant
     */
    public function toDateTimeImmutable(?\DateTimeZone $timezone = null): \DateTimeImmutable
    {
        return $this->date->toDateTimeImmutable($timezone)
            ->setTime($this->time->hour, $this->time->minute, $this->time->second, $this->time->microsecond);
    }

    /**
     * @return string the ISO 8601 form, `YYYY-MM-DDTHH:MM:SS[.fff]`
     */
    public function toIso8601(): string
    {
        return "{$this->date}T{$this->time}";
    }

    /**
     * @param mixed $other the value to compare against
     * @return bool true for the same date and time
     */
    public function equals(mixed $other): bool
    {
        return $other instanceof self && $this->date->equals($other->date) && $this->time->equals($other->time);
    }

    /**
     * Space-separated, as Elixir's `NaiveDateTime` prints -- the form an
     * interpolated `"@{when}"` produces in every Cooper implementation.
     * Use `toIso8601()` for the `T`-separated form.
     *
     * @return string `YYYY-MM-DD HH:MM:SS[.fff]`
     */
    public function __toString(): string
    {
        return "{$this->date} {$this->time}";
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
}

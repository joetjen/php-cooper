<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Value;

/**
 * A CASC local time of day (CASC.md §6.6) -- `07:32:00`, optionally with
 * fractional seconds -- matching Elixir's `Time`: microsecond resolution,
 * and the number of fractional digits written (`$precision`, at most 6)
 * kept so `07:32:00.5` renders back as written rather than as
 * `07:32:00.500000`.
 */
final class CooperTime implements \Stringable, \JsonSerializable
{
    /**
     * @param int $hour `0..23`
     * @param int $minute `0..59`
     * @param int $second `0..59`
     * @param int $microsecond `0..999999`
     * @param int $precision how many fractional digits to render, `0..6`
     * @throws \InvalidArgumentException when a field is out of range
     */
    public function __construct(
        public readonly int $hour,
        public readonly int $minute,
        public readonly int $second,
        public readonly int $microsecond = 0,
        public readonly int $precision = 0,
    ) {
        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59 || $second < 0 || $second > 59
            || $microsecond < 0 || $microsecond > 999_999 || $precision < 0 || $precision > 6) {
            throw new \InvalidArgumentException(sprintf('invalid time: %02d:%02d:%02d', $hour, $minute, $second));
        }
    }

    /**
     * Parses `HH:MM:SS` with optional fractional seconds; digits past the
     * sixth are truncated, as Elixir's own `Time.from_iso8601/1` does.
     *
     * @param string $text e.g. `"07:32:00.5"`
     * @return self the parsed time
     * @throws \InvalidArgumentException when `$text` is not a valid time
     */
    public static function parse(string $text): self
    {
        if (preg_match('/\A(\d{2}):(\d{2}):(\d{2})(?:\.(\d+))?\z/', $text, $m) !== 1) {
            throw new \InvalidArgumentException('invalid time: ' . json_encode($text));
        }
        $fraction = substr($m[4] ?? '', 0, 6);
        $precision = strlen($fraction);
        $microsecond = $precision === 0 ? 0 : (int) str_pad($fraction, 6, '0');

        return new self((int) $m[1], (int) $m[2], (int) $m[3], $microsecond, $precision);
    }

    /**
     * @param mixed $other the value to compare against
     * @return bool true for the same time of day and precision
     */
    public function equals(mixed $other): bool
    {
        return $other instanceof self && (string) $other === (string) $this;
    }

    /**
     * @return string `HH:MM:SS`, plus `$precision` fractional digits when non-zero
     */
    public function __toString(): string
    {
        $text = sprintf('%02d:%02d:%02d', $this->hour, $this->minute, $this->second);
        if ($this->precision === 0) {
            return $text;
        }

        return $text . '.' . substr(sprintf('%06d', $this->microsecond), 0, $this->precision);
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

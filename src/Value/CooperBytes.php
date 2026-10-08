<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Value;

/**
 * A CASC byte size (CASC.md §6.9) -- `512MiB`, `10GB`, or
 * `!bytes("512MiB")` -- as a whole number of bytes, matching the Elixir
 * original's `{:bytes, count}`.
 *
 * The count follows the integer rule (`CooperInteger`): a native `int`
 * when it fits in 64 bits, a `CooperInteger` beyond -- `20000000PiB`
 * was once a load error here and loads in the reference.
 */
final class CooperBytes implements \Stringable
{
    /**
     * The size in bytes.
     */
    public readonly int|CooperInteger $bytes;

    /**
     * @param int|CooperInteger $bytes the size in bytes; a `CooperInteger`
     *                                 that fits is kept as an `int`
     */
    public function __construct(int|CooperInteger $bytes)
    {
        $this->bytes = $bytes instanceof CooperInteger ? CooperInteger::represent($bytes->digits) : $bytes;
    }

    /**
     * @param mixed $other the value to compare against
     * @return bool true for a byte size of the same count
     */
    public function equals(mixed $other): bool
    {
        // Both counts are canonical (an `int` whenever one fits), so
        // equal sizes have equal digits.
        return $other instanceof self && (string) $other->bytes === (string) $this->bytes;
    }

    /**
     * The size in its base unit -- `"536870912B"` -- which is also valid
     * CASC if fed back in.
     *
     * @return string the byte count with a `B` suffix
     */
    public function __toString(): string
    {
        return $this->bytes . 'B';
    }
}

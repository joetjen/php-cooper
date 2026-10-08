<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Value;

/**
 * Shared CIDR math for `CooperIPv4`/`CooperIPv6` -- both address
 * families need the identical operations (prefix to mask, network/
 * broadcast/host-range), differing only in part count and part width
 * (4 x 8-bit octets vs 8 x 16-bit hextets).
 *
 * Unlike the Elixir original, which folds an address into one
 * arbitrary-precision integer, this works part by part: PHP's `int` is
 * 64 bits wide, too narrow for an IPv6 address, and assuming the GMP
 * extension just for this would be a heavy requirement. Carry/borrow
 * across parts is handled explicitly in `increment()`/`decrement()`.
 *
 * @internal not part of the public API -- use the address classes' own methods
 */
final class Cidr
{
    private function __construct()
    {
    }

    /**
     * @return string|null an error message, or null when `$prefix` is valid
     */
    public static function prefixError(?int $prefix, int $bits): ?string
    {
        if ($prefix === null || ($prefix >= 0 && $prefix <= $bits)) {
            return null;
        }

        return "invalid CIDR prefix {$prefix} (must be 0..{$bits})";
    }

    /**
     * The mask implied by `$prefix`, as parts. `null` (no CIDR suffix at
     * all) means "this one address" -- a full host mask.
     *
     * @return list<int>
     */
    public static function mask(?int $prefix, int $parts, int $partBits): array
    {
        $remaining = $prefix ?? $parts * $partBits;
        $full = (1 << $partBits) - 1;
        $mask = [];
        for ($i = 0; $i < $parts; $i++) {
            $take = max(0, min($partBits, $remaining));
            $mask[] = $take === 0 ? 0 : ($full << ($partBits - $take)) & $full;
            $remaining -= $take;
        }

        return $mask;
    }

    /**
     * @param list<int> $address
     * @return list<int>
     */
    public static function network(array $address, ?int $prefix, int $partBits): array
    {
        $mask = self::mask($prefix, count($address), $partBits);

        return array_map(static fn (int $part, int $m): int => $part & $m, $address, $mask);
    }

    /**
     * @param list<int> $address
     * @return list<int>
     */
    public static function broadcast(array $address, ?int $prefix, int $partBits): array
    {
        $full = (1 << $partBits) - 1;
        $mask = self::mask($prefix, count($address), $partBits);

        return array_map(
            static fn (int $part, int $m): int => ($part & $m) | ($full ^ $m),
            $address,
            $mask,
        );
    }

    /**
     * The first and last usable host addresses. A block with 2 or fewer
     * addresses (`/31`, `/32` for v4; `/127`, `/128` for v6) reserves no
     * address as a pure network/broadcast id -- every address in it is a
     * usable host (RFC 3021 for the v4 `/31` case).
     *
     * @param list<int> $address
     * @return array{0: list<int>, 1: list<int>}
     */
    public static function hostRange(array $address, ?int $prefix, int $partBits): array
    {
        $bits = count($address) * $partBits;
        $network = self::network($address, $prefix, $partBits);
        $broadcast = self::broadcast($address, $prefix, $partBits);

        if (($prefix ?? $bits) >= $bits - 1) {
            return [$network, $broadcast];
        }

        return [self::increment($network, $partBits), self::decrement($broadcast, $partBits)];
    }

    /**
     * @param list<int> $cidr
     * @param list<int> $other
     */
    public static function contains(array $cidr, ?int $prefix, array $other, int $partBits): bool
    {
        return self::network($cidr, $prefix, $partBits) === self::network($other, $prefix, $partBits);
    }

    /**
     * @param list<int> $parts
     * @return list<int>
     */
    private static function increment(array $parts, int $partBits): array
    {
        $full = (1 << $partBits) - 1;
        for ($i = count($parts) - 1; $i >= 0; $i--) {
            if ($parts[$i] < $full) {
                $parts[$i]++;
                break;
            }
            $parts[$i] = 0;
        }

        return $parts;
    }

    /**
     * @param list<int> $parts
     * @return list<int>
     */
    private static function decrement(array $parts, int $partBits): array
    {
        $full = (1 << $partBits) - 1;
        for ($i = count($parts) - 1; $i >= 0; $i--) {
            if ($parts[$i] > 0) {
                $parts[$i]--;
                break;
            }
            $parts[$i] = $full;
        }

        return $parts;
    }
}

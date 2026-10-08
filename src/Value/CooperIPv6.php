<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Value;

/**
 * A parsed IPv6 address, with an optional CIDR prefix (CASC.md §6.7 --
 * `::1` or `::1/128`). The constructor validates, and it is the same
 * validation a bare literal goes through at load time.
 *
 * Unlike `CooperIPv4`, there is no `broadcast()` -- IPv6 has no
 * broadcast address, and faking one would be misleading.
 *
 * `__toString()` renders exactly what Erlang's `:inet.ntoa/1` (and so
 * the Elixir original) does: lowercase hex, the longest run of two or
 * more zero groups (the first, on a tie) compressed to `::`, and the
 * IPv4-compatible (`::a.b.c.d`) and IPv4-mapped (`::ffff:a.b.c.d`)
 * forms written in dotted-quad notation.
 */
final class CooperIPv6 implements \Stringable, \JsonSerializable
{
    private const PARTS = 8;
    private const PART_BITS = 16;
    private const BITS = 128;

    /** @var list<int> */
    public readonly array $address;

    /**
     * @param array<mixed> $address eight hextets, each `0..65535`
     * @param int|null $prefix the CIDR prefix, `0..128`, or null for none
     * @throws \InvalidArgumentException on an invalid address or prefix
     */
    public function __construct(array $address, public readonly ?int $prefix = null)
    {
        $address = array_values($address);
        $valid = count($address) === self::PARTS;
        foreach ($address as $part) {
            $valid = $valid && is_int($part) && $part >= 0 && $part <= 0xFFFF;
        }
        if (!$valid) {
            $shown = implode(', ', array_map(static fn (mixed $p): string => var_export($p, true), $address));
            throw new \InvalidArgumentException("invalid IPv6 address: {{$shown}}");
        }
        $error = Cidr::prefixError($prefix, self::BITS);
        if ($error !== null) {
            throw new \InvalidArgumentException($error);
        }
        /** @var list<int> $address */
        $this->address = $address;
    }

    /**
     * Parses colon-hex text (`::` compression allowed), with an optional
     * `/prefix`.
     *
     * @param string $text e.g. `"2001:db8::/32"`
     * @return self the parsed address
     * @throws \InvalidArgumentException when `$text` is not a valid IPv6 address
     */
    public static function parse(string $text): self
    {
        $parts = explode('/', $text, 2);
        $prefix = null;
        if (count($parts) === 2) {
            if (preg_match('/\A\d+\z/', $parts[1]) !== 1) {
                throw new \InvalidArgumentException('invalid CIDR prefix: ' . json_encode($parts[1]));
            }
            $prefix = (int) $parts[1];
        }
        $addressText = $parts[0];
        $groups = self::parseGroups($addressText);
        if ($groups === null) {
            throw new \InvalidArgumentException('invalid IP address: ' . json_encode($addressText));
        }

        return new self($groups, $prefix);
    }

    /**
     * Whether `$other`'s address falls within this block.
     *
     * @param self $other the address to test
     * @return bool true when `$other` is inside this block
     */
    public function contains(self $other): bool
    {
        return Cidr::contains($this->address, $this->prefix, $other->address, self::PART_BITS);
    }

    /**
     * @return self the block's network address, its own prefix carried through
     */
    public function network(): self
    {
        return new self(Cidr::network($this->address, $this->prefix, self::PART_BITS), $this->prefix);
    }

    /**
     * @return self the netmask implied by the prefix, as a bare address (no prefix)
     */
    public function netmask(): self
    {
        return new self(Cidr::mask($this->prefix, self::PARTS, self::PART_BITS));
    }

    /**
     * The first usable host address. For a /127 or /128, where every
     * address is usable, this is the network address.
     *
     * @return self the first usable host, without a prefix
     */
    public function firstHost(): self
    {
        return new self(Cidr::hostRange($this->address, $this->prefix, self::PART_BITS)[0]);
    }

    /**
     * The last usable host address. For a /127 or /128, where every
     * address is usable, this is the highest address in the block.
     *
     * @return self the last usable host, without a prefix
     */
    public function lastHost(): self
    {
        return new self(Cidr::hostRange($this->address, $this->prefix, self::PART_BITS)[1]);
    }

    /**
     * @param mixed $other the value to compare against
     * @return bool true for the same address and prefix
     */
    public function equals(mixed $other): bool
    {
        return $other instanceof self && $other->address === $this->address && $other->prefix === $this->prefix;
    }

    /**
     * @return string the address in `:inet.ntoa/1` form, with `/prefix` when one is set
     */
    public function __toString(): string
    {
        $text = self::format($this->address);

        return $this->prefix === null ? $text : "{$text}/{$this->prefix}";
    }

    /**
     * @return list<int>|null
     */
    private static function parseGroups(string $text): ?array
    {
        $hex = '[0-9A-Fa-f]{1,4}';
        if (substr_count($text, '::') > 1) {
            return null;
        }
        if (str_contains($text, '::')) {
            [$head, $tail] = explode('::', $text, 2);
            $headGroups = $head === '' ? [] : explode(':', $head);
            $tailGroups = $tail === '' ? [] : explode(':', $tail);
            $explicit = count($headGroups) + count($tailGroups);
            if ($explicit > self::PARTS - 1) {
                return null;
            }
            $groups = [...$headGroups, ...array_fill(0, self::PARTS - $explicit, '0'), ...$tailGroups];
        } else {
            $groups = explode(':', $text);
            if (count($groups) !== self::PARTS) {
                return null;
            }
        }

        $parsed = [];
        foreach ($groups as $group) {
            if (preg_match("/\\A{$hex}\\z/", $group) !== 1) {
                return null;
            }
            $parsed[] = (int) hexdec($group);
        }

        return $parsed;
    }

    /**
     * @param list<int> $a
     */
    private static function format(array $a): string
    {
        if ($a === [0, 0, 0, 0, 0, 0, 0, 0]) {
            return '::';
        }
        if ($a === [0, 0, 0, 0, 0, 0, 0, 1]) {
            return '::1';
        }
        if ($a[0] === 0 && $a[1] === 0 && $a[2] === 0 && $a[3] === 0 && $a[4] === 0) {
            if ($a[5] === 0) {
                return '::' . self::dotted($a[6], $a[7]);
            }
            if ($a[5] === 0xFFFF) {
                return '::ffff:' . self::dotted($a[6], $a[7]);
            }
        }

        // Longest run of at least two zero groups, first one on a tie.
        $bestStart = -1;
        $bestLength = 1;
        for ($i = 0; $i < self::PARTS; $i++) {
            if ($a[$i] !== 0) {
                continue;
            }
            $length = 0;
            while ($i + $length < self::PARTS && $a[$i + $length] === 0) {
                $length++;
            }
            if ($length > $bestLength) {
                $bestStart = $i;
                $bestLength = $length;
            }
            $i += $length;
        }

        $hex = array_map(static fn (int $part): string => dechex($part), $a);
        if ($bestStart === -1) {
            return implode(':', $hex);
        }

        $head = implode(':', array_slice($hex, 0, $bestStart));
        $tail = implode(':', array_slice($hex, $bestStart + $bestLength));

        return "{$head}::{$tail}";
    }

    private static function dotted(int $high, int $low): string
    {
        return implode('.', [$high >> 8, $high & 0xFF, $low >> 8, $low & 0xFF]);
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

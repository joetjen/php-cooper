<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Value;

/**
 * A parsed IPv4 address, with an optional CIDR prefix (CASC.md §6.7 --
 * `127.0.0.1` or `127.0.0.1/32`). The constructor validates, and it is
 * the same validation a bare literal goes through at load time -- an
 * out-of-range octet or prefix is an error, never a silently accepted
 * garbage value.
 */
final class CooperIPv4 implements \Stringable, \JsonSerializable
{
    private const PARTS = 4;
    private const PART_BITS = 8;
    private const BITS = 32;

    /** @var list<int> */
    public readonly array $address;

    /**
     * @param array<mixed> $address four octets, each `0..255`
     * @param int|null $prefix the CIDR prefix, `0..32`, or null for none
     * @throws \InvalidArgumentException on an invalid address or prefix
     */
    public function __construct(array $address, public readonly ?int $prefix = null)
    {
        $address = array_values($address);
        if (count($address) !== self::PARTS) {
            throw new \InvalidArgumentException('invalid IPv4 address: ' . self::describe($address));
        }
        foreach ($address as $octet) {
            if (!is_int($octet) || $octet < 0 || $octet > 255) {
                throw new \InvalidArgumentException('invalid IPv4 address: ' . self::describe($address));
            }
        }
        $error = Cidr::prefixError($prefix, self::BITS);
        if ($error !== null) {
            throw new \InvalidArgumentException($error);
        }
        /** @var list<int> $address */
        $this->address = $address;
    }

    /**
     * Parses dotted-quad text, with an optional `/prefix`.
     *
     * @param string $text e.g. `"10.0.0.0/8"`
     * @return self the parsed address
     * @throws \InvalidArgumentException when `$text` is not a valid IPv4 address
     */
    public static function parse(string $text): self
    {
        [$addressText, $prefix] = self::splitPrefix($text);
        if (preg_match('/\A(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})\z/', $addressText, $m) !== 1) {
            throw new \InvalidArgumentException('invalid IP address: ' . json_encode($addressText));
        }
        $octets = [(int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4]];
        foreach ($octets as $octet) {
            if ($octet > 255) {
                throw new \InvalidArgumentException('invalid IP address: ' . json_encode($addressText));
            }
        }

        return new self($octets, $prefix);
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
     * @return self the block's broadcast address, its own prefix carried through
     */
    public function broadcast(): self
    {
        return new self(Cidr::broadcast($this->address, $this->prefix, self::PART_BITS), $this->prefix);
    }

    /**
     * @return self the netmask implied by the prefix, as a bare address (no prefix)
     */
    public function netmask(): self
    {
        return new self(Cidr::mask($this->prefix, self::PARTS, self::PART_BITS));
    }

    /**
     * The first usable host address. For a /31 or /32, where no address
     * is reserved as a pure network id, this is the network address.
     *
     * @return self the first usable host, without a prefix
     */
    public function firstHost(): self
    {
        return new self(Cidr::hostRange($this->address, $this->prefix, self::PART_BITS)[0]);
    }

    /**
     * The last usable host address. For a /31 or /32, where no address
     * is reserved as a pure broadcast id, this is the broadcast address.
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
     * @return string dotted-quad text, with `/prefix` when one is set
     */
    public function __toString(): string
    {
        $text = implode('.', $this->address);

        return $this->prefix === null ? $text : "{$text}/{$this->prefix}";
    }

    /**
     * @return array{0: string, 1: int|null}
     */
    private static function splitPrefix(string $text): array
    {
        $parts = explode('/', $text, 2);
        if (count($parts) === 1) {
            return [$text, null];
        }
        if (preg_match('/\A\d+\z/', $parts[1]) !== 1) {
            throw new \InvalidArgumentException('invalid CIDR prefix: ' . json_encode($parts[1]));
        }

        return [$parts[0], (int) $parts[1]];
    }

    /**
     * @param array<mixed> $address
     */
    private static function describe(array $address): string
    {
        return '{' . implode(', ', array_map(static fn (mixed $p): string => var_export($p, true), $address)) . '}';
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

<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Tests\Value;

use JOetjen\Cooper\Value\CooperIPv4;
use PHPUnit\Framework\TestCase;

/**
 * Ported from the Elixir suite's `ipv4_test.exs`.
 */
final class CooperIPv4Test extends TestCase
{
    public function testConstruction(): void
    {
        $ip = new CooperIPv4([10, 0, 0, 0], 8);

        self::assertSame([10, 0, 0, 0], $ip->address);
        self::assertSame(8, $ip->prefix);
        self::assertNull((new CooperIPv4([127, 0, 0, 1]))->prefix);
    }

    public function testAnOutOfRangeOctet(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid IPv4 address');

        new CooperIPv4([256, 0, 0, 1]);
    }

    public function testOutOfRangePrefixes(): void
    {
        foreach ([33 => 'invalid CIDR prefix 33 (must be 0..32)', -1 => 'invalid CIDR prefix -1 (must be 0..32)'] as $prefix => $message) {
            try {
                new CooperIPv4([10, 0, 0, 0], $prefix);
                self::fail('expected an error');
            } catch (\InvalidArgumentException $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
    }

    public function testParse(): void
    {
        self::assertTrue(CooperIPv4::parse('192.168.1.200/24')->equals(new CooperIPv4([192, 168, 1, 200], 24)));
        self::assertTrue(CooperIPv4::parse('1.2.3.04')->equals(new CooperIPv4([1, 2, 3, 4])));
    }

    public function testToString(): void
    {
        self::assertSame('127.0.0.1', (string) new CooperIPv4([127, 0, 0, 1]));
        self::assertSame('127.0.0.1/32', (string) new CooperIPv4([127, 0, 0, 1], 32));
    }

    public function testAnOrdinaryBlock(): void
    {
        $cidr = new CooperIPv4([192, 168, 1, 200], 24);

        self::assertSame('192.168.1.0/24', (string) $cidr->network());
        self::assertSame('192.168.1.255/24', (string) $cidr->broadcast());
        self::assertSame('255.255.255.0', (string) $cidr->netmask());
        self::assertSame('192.168.1.1', (string) $cidr->firstHost());
        self::assertSame('192.168.1.254', (string) $cidr->lastHost());
    }

    public function testASlash32IsItsOwnEverything(): void
    {
        $cidr = new CooperIPv4([10, 0, 0, 5], 32);

        foreach ([$cidr->network(), $cidr->broadcast(), $cidr->firstHost(), $cidr->lastHost()] as $ip) {
            self::assertSame([10, 0, 0, 5], $ip->address);
        }
    }

    public function testASlash31HasBothAddressesUsable(): void
    {
        $cidr = new CooperIPv4([10, 0, 0, 0], 31);

        self::assertSame([10, 0, 0, 0], $cidr->network()->address);
        self::assertSame([10, 0, 0, 1], $cidr->broadcast()->address);
        self::assertSame([10, 0, 0, 0], $cidr->firstHost()->address);
        self::assertSame([10, 0, 0, 1], $cidr->lastHost()->address);
    }

    public function testNoPrefixBehavesLikeSlash32AndSlash0CoversEverything(): void
    {
        $host = new CooperIPv4([10, 0, 0, 5]);
        self::assertSame([10, 0, 0, 5], $host->network()->address);
        self::assertSame([255, 255, 255, 255], $host->netmask()->address);

        $all = new CooperIPv4([1, 2, 3, 4], 0);
        self::assertSame([0, 0, 0, 0], $all->network()->address);
        self::assertSame([255, 255, 255, 255], $all->broadcast()->address);
        self::assertSame([0, 0, 0, 0], $all->netmask()->address);
    }

    public function testContains(): void
    {
        $cidr = new CooperIPv4([192, 168, 1, 0], 24);

        self::assertTrue($cidr->contains(new CooperIPv4([192, 168, 1, 200])));
        self::assertFalse($cidr->contains(new CooperIPv4([192, 168, 2, 1])));

        $single = new CooperIPv4([127, 0, 0, 1]);
        self::assertTrue($single->contains(new CooperIPv4([127, 0, 0, 1])));
        self::assertFalse($single->contains(new CooperIPv4([127, 0, 0, 2])));
    }

    public function testContainsAgreesWithAnIntegerReferenceForRandomBlocks(): void
    {
        // Property-style: the part-wise mask math against plain 32-bit
        // integer arithmetic, over random addresses and prefixes.
        mt_srand(4242);
        for ($i = 0; $i < 500; $i++) {
            $a = mt_rand(0, 0xFFFFFFFF);
            $b = mt_rand(0, 0xFFFFFFFF);
            $prefix = mt_rand(0, 32);
            $mask = $prefix === 0 ? 0 : (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;
            $cidr = new CooperIPv4(self::octets($a), $prefix);

            self::assertSame(($a & $mask) === ($b & $mask), $cidr->contains(new CooperIPv4(self::octets($b))));
            self::assertSame(self::octets($a & $mask), $cidr->network()->address);
            self::assertSame(self::octets(($a & $mask) | (~$mask & 0xFFFFFFFF)), $cidr->broadcast()->address);
        }
    }

    /**
     * @return list<int>
     */
    private static function octets(int $value): array
    {
        return [($value >> 24) & 0xFF, ($value >> 16) & 0xFF, ($value >> 8) & 0xFF, $value & 0xFF];
    }
}

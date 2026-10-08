<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Tests\Value;

use JOetjen\Cooper\Value\CooperIPv6;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ported from the Elixir suite's `ipv6_test.exs`, plus the exact
 * `:inet.ntoa/1` rendering rules.
 */
final class CooperIPv6Test extends TestCase
{
    public function testConstructionAndValidation(): void
    {
        self::assertSame(32, (new CooperIPv6([0x2001, 0xDB8, 0, 0, 0, 0, 0, 0], 32))->prefix);

        try {
            new CooperIPv6([0x10000, 0, 0, 0, 0, 0, 0, 1]);
            self::fail('expected an error');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('invalid IPv6 address', $e->getMessage());
        }

        $this->expectExceptionMessage('invalid CIDR prefix 129 (must be 0..128)');
        new CooperIPv6([0, 0, 0, 0, 0, 0, 0, 1], 129);
    }

    /**
     * Every expected string is what Erlang's `:inet.ntoa/1` returns.
     *
     * @return iterable<string, array{list<int>, string}>
     */
    public static function renderings(): iterable
    {
        yield 'loopback' => [[0, 0, 0, 0, 0, 0, 0, 1], '::1'];
        yield 'unspecified' => [[0, 0, 0, 0, 0, 0, 0, 0], '::'];
        yield 'documentation' => [[0x2001, 0xDB8, 0, 0, 0, 0, 0, 1], '2001:db8::1'];
        yield 'first longest run on a tie' => [[1, 0, 2, 0, 0, 3, 0, 0], '1:0:2::3:0:0'];
        yield 'longest run wins' => [[1, 0, 0, 2, 0, 0, 3, 4], '1::2:0:0:3:4'];
        yield 'single zero kept' => [[1, 0, 1, 1, 1, 1, 1, 1], '1:0:1:1:1:1:1:1'];
        yield 'leading run' => [[0, 0, 1, 1, 1, 1, 1, 1], '::1:1:1:1:1:1'];
        yield 'trailing run' => [[1, 1, 1, 1, 1, 1, 0, 0], '1:1:1:1:1:1::'];
        yield 'ipv4-compatible' => [[0, 0, 0, 0, 0, 0, 0x1234, 0], '::18.52.0.0'];
        yield 'ipv4-compatible small' => [[0, 0, 0, 0, 0, 0, 0, 2], '::0.0.0.2'];
        yield 'ipv4-mapped' => [[0, 0, 0, 0, 0, 0xFFFF, 0x0102, 0x0304], '::ffff:1.2.3.4'];
        yield 'not compatible' => [[0, 0, 0, 0, 0, 1, 0, 0], '::1:0:0'];
        yield 'trailing mask' => [[0xFFFF, 0xFFFF, 0, 0, 0, 0, 0, 0], 'ffff:ffff::'];
    }

    /**
     * @param list<int> $address
     */
    #[DataProvider('renderings')]
    public function testRendersAsInetNtoa(array $address, string $expected): void
    {
        self::assertSame($expected, (string) new CooperIPv6($address));
    }

    public function testRendersWithAPrefix(): void
    {
        self::assertSame('2001:db8::/32', (string) new CooperIPv6([0x2001, 0xDB8, 0, 0, 0, 0, 0, 0], 32));
    }

    public function testParse(): void
    {
        self::assertSame([0x2001, 0xDB8, 0, 0, 0, 0, 0, 1], CooperIPv6::parse('2001:DB8::1')->address);
        self::assertSame([1, 2, 3, 4, 5, 6, 7, 8], CooperIPv6::parse('1:2:3:4:5:6:7:8')->address);
        self::assertSame(128, CooperIPv6::parse('::1/128')->prefix);

        foreach (['1::2::3', '1:2:3:4:5:6:7:8::', '12345::1', '1:2:3'] as $bad) {
            try {
                CooperIPv6::parse($bad);
                self::fail("accepted {$bad}");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('invalid IP address', $e->getMessage());
            }
        }
    }

    public function testAnOrdinaryBlock(): void
    {
        $cidr = new CooperIPv6([0x2001, 0xDB8, 0, 0, 0, 0, 0, 0], 32);

        self::assertSame([0x2001, 0xDB8, 0, 0, 0, 0, 0, 0], $cidr->network()->address);
        self::assertSame(32, $cidr->network()->prefix);
        self::assertSame([0xFFFF, 0xFFFF, 0, 0, 0, 0, 0, 0], $cidr->netmask()->address);
        self::assertSame([0x2001, 0xDB8, 0, 0, 0, 0, 0, 1], $cidr->firstHost()->address);
        self::assertSame([0x2001, 0xDB8, 0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF, 0xFFFE], $cidr->lastHost()->address);
    }

    public function testPointToPointAndSingleAddressBlocks(): void
    {
        $host = new CooperIPv6([0, 0, 0, 0, 0, 0, 0, 1], 128);
        self::assertSame([0, 0, 0, 0, 0, 0, 0, 1], $host->network()->address);
        self::assertSame([0, 0, 0, 0, 0, 0, 0, 1], $host->firstHost()->address);
        self::assertSame([0, 0, 0, 0, 0, 0, 0, 1], $host->lastHost()->address);

        $pair = new CooperIPv6([0x2001, 0xDB8, 0, 0, 0, 0, 0, 0], 127);
        self::assertSame([0x2001, 0xDB8, 0, 0, 0, 0, 0, 0], $pair->firstHost()->address);
        self::assertSame([0x2001, 0xDB8, 0, 0, 0, 0, 0, 1], $pair->lastHost()->address);
    }

    public function testNoPrefixAndSlash0(): void
    {
        self::assertSame([0, 0, 0, 0, 0, 0, 0, 1], (new CooperIPv6([0, 0, 0, 0, 0, 0, 0, 1]))->network()->address);

        $all = new CooperIPv6([0x2001, 0xDB8, 0, 0, 0, 0, 0, 1], 0);
        self::assertSame([0, 0, 0, 0, 0, 0, 0, 0], $all->network()->address);
        self::assertSame([0, 0, 0, 0, 0, 0, 0, 0], $all->netmask()->address);
    }

    public function testContains(): void
    {
        $cidr = new CooperIPv6([0x2001, 0xDB8, 0, 0, 0, 0, 0, 0], 32);

        self::assertTrue($cidr->contains(new CooperIPv6([0x2001, 0xDB8, 0, 0, 0, 0, 0, 1])));
        self::assertFalse($cidr->contains(new CooperIPv6([0x2001, 0xDB9, 0, 0, 0, 0, 0, 1])));
    }

    public function testParseRoundTripsTheRenderingForRandomAddresses(): void
    {
        mt_srand(6464);
        for ($i = 0; $i < 300; $i++) {
            $address = [];
            for ($j = 0; $j < 8; $j++) {
                // Bias toward zero groups so `::` compression is exercised.
                $address[] = mt_rand(0, 2) === 0 ? 0 : mt_rand(0, 0xFFFF);
            }
            $text = (string) new CooperIPv6($address);
            if (str_contains($text, '.')) {
                continue; // dotted-quad renderings are output-only
            }
            self::assertSame($address, CooperIPv6::parse($text)->address, $text);
        }
    }
}

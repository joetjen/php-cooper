<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Tests\Syntax;

use JOetjen\Cooper\Syntax\Literals;
use JOetjen\Cooper\Value\CooperInteger;
use PHPUnit\Framework\TestCase;

final class LiteralsTest extends TestCase
{
    public function testIntegerLimits(): void
    {
        self::assertSame(PHP_INT_MAX, Literals::integer('9_223_372_036_854_775_807'));
        self::assertSame(PHP_INT_MIN, Literals::integer('-9223372036854775808'));
        self::assertSame(PHP_INT_MAX, Literals::integer('0x7FFF_FFFF_FFFF_FFFF'));
    }

    public function testAnIntegerBeyond64BitsKeepsEveryDigit(): void
    {
        // CASC.md §6.3 integers are arbitrary-precision, as the
        // reference's are: one past either end of PHP's `int` becomes a
        // `CooperInteger`, never a float or an error.
        foreach ([
            '9223372036854775808' => '9223372036854775808',
            '-9223372036854775809' => '-9223372036854775809',
            '0x8000000000000000' => '9223372036854775808',
            '-0x8000_0000_0000_0001' => '-9223372036854775809',
            '0o1777777777777777777777' => '18446744073709551615',
            '0b1' . str_repeat('0', 64) => '18446744073709551616',
            '12_345_678_901_234_567_890' => '12345678901234567890',
        ] as $text => $digits) {
            $value = Literals::integer($text);

            self::assertInstanceOf(CooperInteger::class, $value, $text);
            self::assertSame($digits, (string) $value, $text);
        }
    }

    public function testIntegersAgreeWithPhpForRandomValues(): void
    {
        mt_srand(31337);
        for ($i = 0; $i < 1000; $i++) {
            $value = mt_rand(PHP_INT_MIN, PHP_INT_MAX);
            $sign = $value < 0 ? '-' : '';
            $magnitude = ltrim((string) $value, '-');

            self::assertSame($value, Literals::integer((string) $value));
            if ($value >= 0) {
                self::assertSame($value, Literals::integer('0x' . dechex($value)));
                self::assertSame($value, Literals::integer('0o' . decoct($value)));
                self::assertSame($value, Literals::integer('0b' . decbin($value)));
            }
            self::assertSame($value, Literals::integer($sign . implode('_', str_split($magnitude, 3))));
        }
    }

    public function testDurationEdges(): void
    {
        self::assertSame(0, Literals::duration('0.1ns'));
        self::assertSame(1, Literals::duration('0.5ns'));
        self::assertSame(3, Literals::duration('2.5ns'));
        self::assertSame(1_000_000_000, Literals::duration('1.0000000001s'));
        self::assertSame(90_061_001_001_001, Literals::duration('1d1h1m1s1ms1us1ns'));

        foreach (['5msXYZ9s' => 'invalid duration', '_1ms' => 'invalid duration'] as $text => $message) {
            try {
                Literals::duration($text);
                self::fail("accepted {$text}");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testASingleUnitDurationIsTheExactProductNotTheNearestFloat(): void
    {
        // The reference once multiplied a single-unit amount as a float,
        // and this port copied it: `9007199254740993ns` was ...992, and
        // `9223372036854775807ns` became 2^63. Both now compute exactly.
        self::assertSame(9_007_199_254_740_993, Literals::duration('9007199254740993ns'));
        self::assertSame(PHP_INT_MAX, Literals::duration('9223372036854775807ns'));
    }

    public function testADurationBeyond64BitsKeepsEveryDigit(): void
    {
        // Each expected count is what the Elixir reference loads for the
        // same literal; one that fits stays a native `int`.
        self::assertSame(8_640_000_000_000_000_000, Literals::duration('100000d'));
        foreach ([
            '300000d' => '25920000000000000000',
            '300000000d' => '25920000000000000000000',
            '123456789012345678901234567890d' => '10666666570666666657066666665696000000000000',
            '12345678901234567890.5ms' => '12345678901234567890500000',
            '1000000000000d1ns' => '86400000000000000000000001',
            '106751d23h47m16s854ms775us808ns' => '9223372036854775808',
            // Too large for any float -- the reference once crashed here.
            str_repeat('9', 400) . 'ns' => str_repeat('9', 400),
        ] as $text => $digits) {
            $value = Literals::duration($text);

            self::assertInstanceOf(CooperInteger::class, $value, $text);
            self::assertSame($digits, (string) $value, $text);
        }
    }

    public function testADurationIsTheExactRoundedProductForRandomAmounts(): void
    {
        // Rounded half away from zero by adding one half at full scale and
        // truncating -- a different route from `Literals`' `div(2n + d,
        // 2d)` to the same count.
        $units = ['d' => '86400000000000', 'h' => '3600000000000', 'm' => '60000000000', 's' => '1000000000', 'ms' => '1000000', 'us' => '1000', 'ns' => '1'];
        mt_srand(4242);
        for ($i = 0; $i < 500; $i++) {
            $unit = array_rand($units);
            $amount = self::randomDigits(mt_rand(1, 30));
            if (mt_rand(0, 1) === 1) {
                $amount .= '.' . self::randomDigits(mt_rand(1, 20), true);
            }
            $expected = bcadd(bcadd(bcmul($amount, $units[$unit], 40), '0.5', 40), '0', 0);

            self::assertSame($expected, (string) Literals::duration("{$amount}{$unit}"), "{$amount}{$unit}");
        }
    }

    public function testACompoundDurationIsTheExactSumOfItsComponentsForRandomAmounts(): void
    {
        $units = ['d' => '86400000000000', 'h' => '3600000000000', 'm' => '60000000000', 's' => '1000000000', 'ms' => '1000000', 'us' => '1000', 'ns' => '1'];
        mt_srand(4243);
        for ($i = 0; $i < 300; $i++) {
            $text = '';
            $expected = '0';
            foreach ($units as $unit => $factor) {
                if (mt_rand(0, 2) === 0) {
                    continue;
                }
                $amount = self::randomDigits(mt_rand(1, 25));
                $text .= "{$amount}{$unit}";
                $expected = bcadd($expected, bcmul($amount, $factor, 0), 0);
            }
            if ($text === '') {
                continue;
            }

            self::assertSame($expected, (string) Literals::duration($text), $text);
        }
    }

    public function testAByteSizeBeyond64BitsKeepsEveryDigit(): void
    {
        foreach ([
            '20000000PiB' => '22517998136852480000000',
            '99999999999999999999B' => '99999999999999999999',
            '777777777777777777777777777777kb' => '777777777777777777777777777777000',
            str_repeat('9', 400) . 'B' => str_repeat('9', 400),
        ] as $text => $digits) {
            $value = Literals::bytes($text);

            self::assertInstanceOf(CooperInteger::class, $value, $text);
            self::assertSame($digits, (string) $value, $text);
        }
        self::assertSame(1001, Literals::bytes('1.0005KB'));
    }

    /**
     * @return numeric-string `$length` random decimal digits, the first
     *                        non-zero unless `$leadingZero`
     */
    private static function randomDigits(int $length, bool $leadingZero = false): string
    {
        $digits = (string) mt_rand($leadingZero ? 0 : 1, 9);
        for ($i = 1; $i < $length; $i++) {
            $digits .= mt_rand(0, 9);
        }
        assert(is_numeric($digits));

        return $digits;
    }

    public function testADurationTakesDigitSeparatorsAsAnIntegerDoes(): void
    {
        // CASC.md §6.8: "Digit separators (`_`) are allowed per §6.3".
        self::assertSame(1_000_000_000, Literals::duration('1_000ms'));
        self::assertSame(5_400_000_000_000, Literals::duration('1h3_0m'));
        self::assertSame(1_500_000_000, Literals::duration('1.5_00s'));
    }

    public function testByteSizeEdges(): void
    {
        self::assertSame(2, Literals::bytes('1.5B'));
        self::assertSame(1024, Literals::bytes('1kib'));

        $this->expectExceptionMessage('invalid byte-size unit in "5XB"');
        Literals::bytes('5XB');
    }

    public function testUnescapeAndDedent(): void
    {
        self::assertSame("a\tb\"c\\d\u{263A}x", Literals::unescape('a\tb\"c\\\\d\u263Ax'));
        self::assertSame("x\n  y\n\nz", Literals::dedent("\n    x\n      y\n\n    z"));

        $this->expectExceptionMessage('surrogate');
        Literals::unescape('\uD800');
    }
}

<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Tests\Value;

use JOetjen\Cooper\Value\CooperAtom;
use JOetjen\Cooper\Value\CooperBytes;
use JOetjen\Cooper\Value\CooperDate;
use JOetjen\Cooper\Value\CooperDateTime;
use JOetjen\Cooper\Value\CooperDuration;
use JOetjen\Cooper\Value\CooperInteger;
use JOetjen\Cooper\Value\CooperLocalDateTime;
use JOetjen\Cooper\Value\CooperTime;
use JOetjen\Cooper\Value\CooperTuple;
use JOetjen\Cooper\Values;
use PHPUnit\Framework\TestCase;

/**
 * The small value classes and `Values::equals()`.
 */
final class ValueObjectsTest extends TestCase
{
    public function testAtomsAreInterned(): void
    {
        self::assertSame(CooperAtom::of('info'), CooperAtom::of('info'));
        self::assertNotSame(CooperAtom::of('info'), CooperAtom::of('debug'));
        self::assertSame('info', (string) CooperAtom::of('info'));
        self::assertTrue(CooperAtom::of('x')->equals(unserialize(serialize(CooperAtom::of('x')))));
    }

    public function testTuplesAreReadOnlyArrayLike(): void
    {
        $tuple = new CooperTuple([1, 'two']);

        self::assertCount(2, $tuple);
        self::assertSame('two', $tuple[1]);
        self::assertTrue(isset($tuple[0]));
        self::assertFalse(isset($tuple[2]));
        self::assertSame([1, 'two'], iterator_to_array($tuple));

        $this->expectException(\LogicException::class);
        $tuple[0] = 5;
    }

    public function testTupleIndexOutOfRange(): void
    {
        $this->expectException(\OutOfRangeException::class);

        (new CooperTuple([]))[0];
    }

    public function testDurationConversions(): void
    {
        $duration = new CooperDuration(1_500_000_000);

        self::assertSame(1_500_000, $duration->toMicroseconds());
        self::assertSame(1_500, $duration->toMilliseconds());
        self::assertSame(1.5, $duration->toSeconds());
        self::assertSame('1500000000ns', (string) $duration);
        self::assertSame('512B', (string) new CooperBytes(512));
    }

    public function testADurationOrByteSizeBeyond64BitsKeepsEveryDigit(): void
    {
        $duration = new CooperDuration(new CooperInteger('25920000000000000000000'));
        $bytes = new CooperBytes(new CooperInteger('22517998136852480000000'));

        self::assertSame('25920000000000000000000ns', (string) $duration);
        self::assertEquals(new CooperInteger('25920000000000000000'), $duration->toMicroseconds());
        // A quotient that fits is an `int` again.
        self::assertSame(25_920_000_000_000_000, $duration->toMilliseconds());
        self::assertSame(2.592e13, $duration->toSeconds());
        self::assertSame('22517998136852480000000B', (string) $bytes);
        self::assertTrue($duration->equals(new CooperDuration(new CooperInteger('25920000000000000000000'))));
        self::assertFalse($duration->equals(new CooperDuration(5)));
        self::assertTrue($bytes->equals(new CooperBytes(new CooperInteger('22517998136852480000000'))));
    }

    public function testACountThatFitsIsANativeIntWhicheverWayItWasGiven(): void
    {
        // The same hybrid rule `CooperInteger::represent()` keeps, so
        // equal counts are equal objects however they were built.
        self::assertSame(5, (new CooperDuration(new CooperInteger('5')))->nanoseconds);
        self::assertSame(512, (new CooperBytes(new CooperInteger('512')))->bytes);
        self::assertEquals(new CooperDuration(5), new CooperDuration(new CooperInteger('5')));
    }

    public function testAnOffsetDateTimeKeepsThePrecisionItWasWrittenWith(): void
    {
        $value = CooperDateTime::parse('1979-05-27T07:32:00.120+02:00');

        self::assertSame(120_000, (int) $value->instant->format('u'));
        self::assertSame(3, $value->precision);
        self::assertSame('1979-05-27 05:32:00.120Z', (string) $value);
        self::assertSame('1979-05-27T05:32:00.120Z', $value->toIso8601());
        self::assertSame('"1979-05-27T05:32:00.120Z"', json_encode($value));
        self::assertSame('1979-05-27T05:32:00.120000+00:00', $value->toDateTimeImmutable()->format('Y-m-d\TH:i:s.uP'));
        self::assertSame('1979-05-27T07:32:00+02:00', $value->toDateTimeImmutable(new \DateTimeZone('+02:00'))->format(DATE_ATOM));
        self::assertSame('1979-05-27 07:32:00Z', (string) CooperDateTime::parse('1979-05-27T07:32:00Z'));
        self::assertSame('1979-05-27 07:32:00.0Z', (string) CooperDateTime::parse('1979-05-27T07:32:00.0Z'));
    }

    public function testAnOffsetDateTimeTruncatesFractionalDigitsPastTheSixth(): void
    {
        // As Elixir's `DateTime.from_iso8601/1` does: microsecond
        // resolution, precision 6, never rounded up into the next second.
        self::assertSame('1980-01-01T00:59:59.999999Z', CooperDateTime::parse('1979-12-31T23:59:59.999999999-01:00')->toIso8601());
        self::assertSame('1979-05-27T07:32:00.000000Z', CooperDateTime::parse('1979-05-27T07:32:00.0000001Z')->toIso8601());
    }

    public function testOffsetDateTimesAreEqualOnlyAtTheSameInstantAndPrecision(): void
    {
        $half = CooperDateTime::parse('1979-05-27T07:32:00.5Z');

        self::assertTrue($half->equals(CooperDateTime::parse('1979-05-27T09:32:00.5+02:00')));
        self::assertFalse($half->equals(CooperDateTime::parse('1979-05-27T07:32:00.50Z')));
        self::assertFalse($half->equals(CooperDateTime::parse('1979-05-27T07:32:00.6Z')));
    }

    public function testAnOffsetCanMoveTheInstantOutOfTheFourDigitYears(): void
    {
        // Year 0000 is a real (leap) year to the reference's proleptic
        // calendar; `checkdate()` once refused it here.
        self::assertSame('-0001-12-31T23:59:00Z', CooperDateTime::parse('0000-01-01T00:00:00+00:01')->toIso8601());
        self::assertSame('10000-01-01T04:30:00Z', CooperDateTime::parse('9999-12-31T23:30:00-05:00')->toIso8601());
        self::assertSame('1979-05-26T07:33:00Z', CooperDateTime::parse('1979-05-27T07:32:00+23:59')->toIso8601());
    }

    public function testAnInvalidOffsetDateTimeIsRefused(): void
    {
        // `-00:00` is RFC 3339's "offset unknown", not UTC, and the
        // reference's `DateTime.from_iso8601/1` refuses it.
        foreach (['1979-02-30T07:32:00Z', '1979-05-27T07:32:00+24:00', '1979-05-27T07:32:00', '1979-05-27T07:32:00-00:00'] as $text) {
            try {
                CooperDateTime::parse($text);
                self::fail("accepted {$text}");
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testDatesAndTimes(): void
    {
        $date = new CooperDate(1979, 5, 27);
        $time = CooperTime::parse('07:32:00.50');
        $local = new CooperLocalDateTime($date, $time);
        $utc = new \DateTimeZone('UTC');

        self::assertSame('1979-05-27', (string) $date);
        self::assertSame('07:32:00.50', (string) $time);
        self::assertSame('1979-05-27 07:32:00.50', (string) $local);
        self::assertSame('1979-05-27T07:32:00.50', $local->toIso8601());
        self::assertSame('1979-05-27T00:00:00+00:00', $date->toDateTimeImmutable($utc)->format(DATE_ATOM));
        self::assertSame('1979-05-27 07:32:00.500000', $local->toDateTimeImmutable($utc)->format('Y-m-d H:i:s.u'));
    }

    public function testYearZeroIsALeapYear(): void
    {
        self::assertSame('0000-02-29', (string) new CooperDate(0, 2, 29));
    }

    public function testInvalidDatesAndTimes(): void
    {
        foreach ([static fn () => new CooperDate(2023, 2, 30), static fn () => CooperTime::parse('24:00:00'), static fn () => CooperTime::parse('7:32')] as $make) {
            try {
                $make();
                self::fail('expected an error');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testJsonEncoding(): void
    {
        self::assertSame(
            '{"a":"info","t":[1,"x"],"ip":"10.0.0.0/8","d":"1979-05-27","l":"1979-05-27T07:32:00","m":"07:32:00.5"}',
            json_encode([
                'a' => CooperAtom::of('info'),
                't' => new CooperTuple([1, 'x']),
                'ip' => \JOetjen\Cooper\Value\CooperIPv4::parse('10.0.0.0/8'),
                'd' => new CooperDate(1979, 5, 27),
                'l' => new CooperLocalDateTime(new CooperDate(1979, 5, 27), new CooperTime(7, 32, 0)),
                'm' => CooperTime::parse('07:32:00.5'),
            ], JSON_UNESCAPED_SLASHES),
        );
    }

    public function testValuesEqualityIsStrictAndStructural(): void
    {
        self::assertTrue(Values::equals([1, [2, 'x']], [1, [2, 'x']]));
        self::assertFalse(Values::equals(1, 1.0));
        self::assertFalse(Values::equals('1', 1));
        self::assertFalse(Values::equals(['a' => 1], ['b' => 1]));
        self::assertTrue(Values::equals(new CooperTuple([1]), new CooperTuple([1])));
        self::assertFalse(Values::equals(new CooperTuple([1]), [1]));
        self::assertTrue(Values::equals(new CooperDuration(5), new CooperDuration(5)));
        self::assertTrue(Values::equals(new CooperBytes(new CooperInteger('99999999999999999999')), new CooperBytes(new CooperInteger('99999999999999999999'))));
        self::assertFalse(Values::equals(CooperDateTime::parse('1979-05-27T07:32:00.5Z'), CooperDateTime::parse('1979-05-27T07:32:00.50Z')));
        self::assertTrue(Values::equals(CooperAtom::of('a'), CooperAtom::of('a')));
    }
}

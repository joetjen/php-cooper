<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Tests;

use JOetjen\Cooper\Tests\Support\Conformance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The shared conformance corpus (`tests/conformance/cases`): every
 * `*.casc` is loaded the way the reference's oracle loads it, and the
 * result is compared with `<case>.expected.json`, which the oracle wrote
 * by running the same file through the Elixir reference.
 *
 * A `<case>.php.json` would override the expected file where CASC.md
 * leaves the result to the host language, listed with its reason in
 * `tests/conformance/DIVERGENCES.md`; there is none at present.
 */
final class ConformanceTest extends TestCase
{
    private const CASES = __DIR__ . '/conformance/cases';

    /**
     * @return iterable<string, array{string}>
     */
    public static function cases(): iterable
    {
        $files = glob(self::CASES . '/*.casc') ?: [];
        sort($files, SORT_STRING);

        foreach ($files as $file) {
            yield basename($file, '.casc') => [$file];
        }
    }

    #[DataProvider('cases')]
    public function testACaseLoadsTheWayTheReferenceLoadsIt(string $file): void
    {
        $base = substr($file, 0, -strlen('.casc'));
        $override = "{$base}.php.json";
        $expectedFile = is_file($override) ? $override : "{$base}.expected.json";
        $expected = json_decode((string) file_get_contents($expectedFile), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($expected);

        $actual = Conformance::run($file);
        // A JSON round trip gives the actual result the same shape the
        // expected one has, so one normalization serves both.
        $actual = json_decode(json_encode($actual, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($actual);

        if (array_key_exists('error', $expected)) {
            // An error is compared by stage only: messages are prose,
            // and each implementation words its own.
            self::assertSame(['error' => $expected['error']], ['error' => $actual['error'] ?? null], json_encode($actual) ?: '');

            return;
        }

        self::assertSame(self::normalize($expected), self::normalize($actual));
    }

    /**
     * Sorts every map's pairs by key (and every JSON object's members).
     * Nothing else is normalized: a `dateTime` was once compared only as
     * the same instant, while this port's offset date-times dropped the
     * precision the reference keeps (`…00.120Z`); `CooperDateTime` keeps
     * it, and the rendering is compared exactly.
     */
    private static function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_keys($value) === ['map'] && is_array($value['map'])) {
            $pairs = array_map(static fn (array $pair): array => [$pair[0], self::normalize($pair[1])], $value['map']);
            usort($pairs, static fn (array $a, array $b): int => strcmp((string) $a[0], (string) $b[0]));

            return ['map' => $pairs];
        }

        $value = array_map(self::normalize(...), $value);
        // A JSON object's members have no order (`{"secret", "redacted"}`).
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}

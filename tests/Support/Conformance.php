<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Tests\Support;

use JOetjen\Cooper\ConformanceAdapter;
use JOetjen\Cooper\ConformanceModule;
use JOetjen\Cooper\CooperError;
use JOetjen\Cooper\Node\MapNode;
use JOetjen\Cooper\Value\CooperAtom;
use JOetjen\Cooper\Value\CooperBytes;
use JOetjen\Cooper\Value\CooperDate;
use JOetjen\Cooper\Value\CooperDateTime;
use JOetjen\Cooper\Value\CooperDuration;
use JOetjen\Cooper\Value\CooperInteger;
use JOetjen\Cooper\Value\CooperIPv4;
use JOetjen\Cooper\Value\CooperIPv6;
use JOetjen\Cooper\Value\CooperLocalDateTime;
use JOetjen\Cooper\Value\CooperSecret;
use JOetjen\Cooper\Value\CooperTime;
use JOetjen\Cooper\Value\CooperTuple;

/**
 * The shared conformance corpus (`tests/conformance/cases`, the same
 * cases every Cooper implementation runs): the load options each case
 * runs with, and the implementation-neutral JSON rendering a result is
 * compared in. Both MUST stay identical to the reference's oracle
 * (`node-cooper/scripts/oracle.exs`, run against `cooper`). The options
 * are `ConformanceAdapter`'s, the Cooper Conformance Suite's standard;
 * a module renders by the name CASC wrote, as the oracle renders it.
 */
final class Conformance
{
    private function __construct()
    {
    }

    /**
     * One case's outcome: `['ok' => canon]` or `['error' => stage]`,
     * loaded with the suite's standard options -- the conformance
     * adapter's own, so the corpus and the suite load alike.
     *
     * @return array<string, mixed>
     */
    public static function run(string $file): array
    {
        try {
            return ['ok' => self::canon(ConformanceAdapter::load($file))];
        } catch (CooperError $e) {
            return ['error' => $e->stage, 'message' => $e->getMessage()];
        }
    }

    public static function canon(mixed $value): mixed
    {
        return match (true) {
            $value === null, is_bool($value), is_string($value) => $value,
            is_int($value) => ['int' => (string) $value],
            $value instanceof CooperInteger => ['int' => $value->digits],
            is_float($value) && is_infinite($value) => ['float' => $value > 0 ? 'inf' : '-inf'],
            is_float($value) => ['float' => $value],
            $value instanceof CooperAtom => ['atom' => $value->name],
            $value instanceof ConformanceModule => ['module' => $value->writtenName()],
            $value instanceof CooperSecret => ['secret' => self::canon($value->reveal()), 'redacted' => $value->redacted],
            $value instanceof CooperTuple => ['tuple' => array_map(self::canon(...), $value->items)],
            $value instanceof CooperDuration => ['duration' => (string) $value->nanoseconds],
            $value instanceof CooperBytes => ['bytes' => (string) $value->bytes],
            $value instanceof CooperIPv4 => ['ipv4' => (string) $value],
            $value instanceof CooperIPv6 => ['ipv6' => (string) $value],
            $value instanceof CooperDate => ['date' => (string) $value],
            $value instanceof CooperTime => ['time' => (string) $value],
            $value instanceof CooperLocalDateTime => ['localDateTime' => $value->toIso8601()],
            $value instanceof CooperDateTime => ['dateTime' => $value->toIso8601()],
            $value instanceof MapNode => ['map' => self::pairs($value)],
            is_array($value) => array_map(self::canon(...), array_values($value)),
            default => ['unknown' => get_debug_type($value)],
        };
    }

    /**
     * @return list<array{0: string, 1: mixed}>
     */
    private static function pairs(MapNode $map): array
    {
        $keys = $map->keys();
        sort($keys, SORT_STRING);

        return array_map(static fn (string $key): array => [$key, self::canon($map->get($key))], $keys);
    }
}

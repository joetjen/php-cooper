<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Tests\Support;

use JOetjen\Cooper\CooperError;
use JOetjen\Cooper\Dotenv;
use JOetjen\Cooper\Node\MapNode;
use JOetjen\Cooper\Pipeline\Grammar;
use JOetjen\Cooper\Pipeline\Resolver;
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
 * (`node-cooper/scripts/oracle.exs`, run against `cooper`).
 */
final class Conformance
{
    public const ENV = [
        'REGION' => 'eu-west',
        'PORT' => '9090',
        'DEBUG' => 'true',
        'EMPTY' => '',
        'LIST' => 'a, b;c',
        'SCHEME' => 'HTTPS://',
        'PADDED' => '  padded  ',
        'TOKEN_1' => 'tok-one',
        'TOKEN_2' => 'tok-two',
        'FEATURE' => 'on',
        'WHICH' => 'dev',
        'FORMATTER' => 'Foo.Bar',
        'COOPER_ENV' => 'conformance',
    ];

    private const MEM = [
        'base' => "#@version = 1\nfrom_mem = true\nshared = \"mem\"\n",
        'vars' => "#@version = 1\n@mem_var = \"from-mem\"\n",
    ];

    private function __construct()
    {
    }

    /**
     * One case's outcome: `['ok' => canon]` or `['error' => stage]`.
     *
     * @return array<string, mixed>
     */
    public static function run(string $file): array
    {
        $dir = dirname($file);
        $source = (string) file_get_contents($file);

        try {
            $env = Dotenv::env(['dotenv' => false, 'env' => self::ENV]);
            $tree = Grammar::runTree($source, [
                'root' => $dir,
                'file' => $file,
                'env' => $env,
                'importSchemes' => [
                    'mem' => static fn (string $rest): string => self::MEM[$rest] ?? throw new \RuntimeException('enoent'),
                ],
            ]);
            [$resolved] = Resolver::resolve($tree->tree, [
                'vars' => $tree->vars,
                'env' => $env,
                'raw' => true,
                'resolvers' => [
                    'echo' => static fn (string $payload): string => $payload,
                    'fail' => static fn (string $payload): never => throw new \RuntimeException("refused {$payload}"),
                ],
                'tags' => [
                    'twice' => static function (mixed $arg): int|string {
                        if (is_int($arg)) {
                            return $arg * 2;
                        }
                        if (is_string($arg)) {
                            return $arg . $arg;
                        }

                        throw new \RuntimeException('cannot double');
                    },
                ],
            ]);

            return ['ok' => self::canon($resolved)];
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

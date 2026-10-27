<?php

declare(strict_types=1);

namespace JOetjen\Cooper;

use JOetjen\Cooper\Node\MapNode;
use JOetjen\Cooper\Pipeline\Display;
use JOetjen\Cooper\Pipeline\Grammar;
use JOetjen\Cooper\Pipeline\Options;
use JOetjen\Cooper\Pipeline\Paths;
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
 * This implementation's adapter for the Cooper Conformance Suite: a
 * JSON request in, a JSON answer out (`php bin/cooper-conformance
 * REQUEST ANSWER`). The PHP twin of the Elixir reference's
 * `Cooper.Conformance`, with the same behaviour and the same JSON.
 *
 * The suite (`libs_prx/cooper_conformance` in the Praxis repository,
 * run by its `assize` library) holds every CASC implementation to the
 * same cases. Each case asks for one operation, `load`, on a document
 * file; this answers with the loaded configuration in the suite's typed
 * JSON, or with the category (CASC.md §9.5) of the error that refused
 * it -- the `CooperError`'s stage.
 *
 * ## What every adapter loads with
 *
 * The suite fixes the environment and the extension points, so a case
 * can use them and every implementation sees the same: the variables in
 * `ENV`, no `.env` file, the document's own directory as the root, the
 * resolvers `echo` (answers its payload) and `fail` (refuses), the tag
 * `twice` (doubles an integer, repeats a string) and the import scheme
 * `mem` (`mem://base`, `mem://vars`). `!module` is the built-in one,
 * its result marked so the answer can name the module (see
 * `ConformanceModule`), with the mapping in `MODULES`.
 *
 * ## Values
 *
 * Every value travels in the suite's typed JSON (see `encode()`):
 * `{"integer": "42"}`, `{"float": "2.0"}`, `{"keyword": "info"}`,
 * `{"tuple": [...]}`, `{"map": [[key, value], ...]}`, arrays, and
 * strings, booleans and `null` as they are; a float's text is the one
 * Elixir's `Float.to_string/1` writes (`2.0`, `1.0e3`), and a map's
 * pairs are sorted by key. What CASC has and Praxis
 * notation has no literal for is a tuple tagged with a namespaced keyword
 * -- `casc/duration`, `casc/bytes`, `casc/secret`, `casc/module`,
 * `casc/float`, `casc/ipv4`, `casc/ipv6`, `casc/date`, `casc/time`,
 * `casc/local-date-time`, `casc/date-time` -- which no CASC atom can be,
 * so a tag never reads as a tuple a document wrote.
 */
final class ConformanceAdapter
{
    /** The environment every case is loaded with, the suite's fixed standard. */
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

    /**
     * The application's `modules` mapping every adapter holds: a mapped
     * module reads in a string as what it maps to (CASC.md §7), `crypto`.
     */
    private const MODULES = ['Crypto' => 'crypto'];

    /** What the `mem` import scheme serves, by the rest of its URI. */
    private const MEM = [
        'base' => "#@version = 1\nfrom_mem = true\nshared = \"mem\"\n",
        'vars' => "#@version = 1\n@mem_var = \"from-mem\"\n",
    ];

    private function __construct()
    {
    }

    /**
     * Reads the request at `$requestPath` and writes the answer to
     * `$answerPath`. A case that fails is an answer, not a failure of
     * the run.
     *
     * @param string $requestPath the JSON request file
     * @param string $answerPath where the JSON answer is written
     * @throws \RuntimeException when the request cannot be read or decoded, or the answer cannot be written
     * @throws \InvalidArgumentException when the request holds no list of cases
     */
    public static function run(string $requestPath, string $answerPath): void
    {
        $text = @file_get_contents($requestPath);
        if ($text === false) {
            throw new \RuntimeException("cannot read the request {$requestPath}");
        }
        try {
            $request = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("the request {$requestPath} is not JSON: {$e->getMessage()}", 0, $e);
        }
        if (!is_array($request)) {
            throw new \InvalidArgumentException("the request {$requestPath} is not a JSON object");
        }

        try {
            $json = json_encode(self::answer($request), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $e) {
            throw new \RuntimeException("cannot encode the answer: {$e->getMessage()}", 0, $e);
        }
        if (@file_put_contents($answerPath, $json) === false) {
            throw new \RuntimeException("cannot write the answer {$answerPath}");
        }
    }

    /**
     * The answer to a decoded request: one result per case, in order.
     * A result is `["id" => ..., "result" => typed value]` or
     * `["id" => ..., "error" => category]`; an unknown operation and a
     * crash are errors too (`"unknown operation: ..."`,
     * `"crash: <message>"`), so one case never costs the others theirs.
     *
     * @param array<mixed> $request the decoded request, its cases under the key `cases`
     * @return array{results: list<array{id: mixed, result?: mixed, error?: string}>} the answer
     * @throws \InvalidArgumentException when the request holds no list of cases
     */
    public static function answer(array $request): array
    {
        $cases = $request['cases'] ?? null;
        if (!is_array($cases) || !array_is_list($cases)) {
            throw new \InvalidArgumentException('the request holds no list of cases');
        }

        return ['results' => array_map(self::result(...), $cases)];
    }

    /**
     * Loads `$document` with the suite's standard options, through the
     * whole pipeline but without a cache, and gives back the resolved
     * value as the pipeline holds it -- a map still a `MapNode`, so an
     * empty map stays distinguishable from an empty list -- with every
     * module a `ConformanceModule`.
     *
     * @param string $document the CASC file
     * @return mixed the resolved configuration, a `MapNode`
     * @throws CooperError when the document is refused
     */
    public static function load(string $document): mixed
    {
        $opts = Options::from(self::options(dirname($document)));
        $absolute = Paths::expand($document);
        $source = Grammar::read($document, $absolute);
        $env = Dotenv::fromOptions($opts);
        $tree = Grammar::runTree($source, $opts->grammar($env, $opts->root, $absolute));
        [$resolved] = Resolver::resolve($tree->tree, [
            'vars' => $tree->vars,
            'env' => $env,
            'resolvers' => $opts->resolvers,
            'tags' => $opts->tags,
            'modules' => $opts->modules,
            'raw' => true,
        ]);

        return $resolved;
    }

    /**
     * `$value`, as this implementation holds a loaded configuration, in
     * the suite's typed JSON (as a PHP value `json_encode()` writes as
     * that JSON).
     *
     * @param mixed $value a value `load()` gives back, or any part of one
     * @return mixed the typed JSON
     * @throws \InvalidArgumentException for a value the suite has no type for
     */
    public static function encode(mixed $value): mixed
    {
        return match (true) {
            $value === null, is_bool($value), is_string($value) => $value,
            is_int($value) => ['integer' => (string) $value],
            $value instanceof CooperInteger => ['integer' => $value->digits],
            is_float($value) && is_infinite($value) => self::tagged('casc/float', $value > 0 ? 'inf' : '-inf'),
            // Elixir's `Float.to_string/1`, which the reference answers with.
            is_float($value) && !is_nan($value) => ['float' => Display::float($value)],
            $value instanceof CooperAtom => ['keyword' => $value->name],
            $value instanceof ConformanceModule => self::tagged('casc/module', $value->writtenName()),
            is_array($value) => array_map(self::encode(...), array_values($value)),
            $value instanceof CooperDuration => self::tagged('casc/duration', self::encode($value->nanoseconds)),
            $value instanceof CooperBytes => self::tagged('casc/bytes', self::encode($value->bytes)),
            $value instanceof CooperSecret => $value->redacted === null
                ? self::tagged('casc/secret', self::encode($value->reveal()))
                : self::tagged('casc/secret', self::encode($value->reveal()), $value->redacted),
            $value instanceof CooperIPv4 => self::tagged('casc/ipv4', (string) $value),
            $value instanceof CooperIPv6 => self::tagged('casc/ipv6', (string) $value),
            $value instanceof CooperDate => self::tagged('casc/date', (string) $value),
            $value instanceof CooperTime => self::tagged('casc/time', (string) $value),
            $value instanceof CooperLocalDateTime => self::tagged('casc/local-date-time', $value->toIso8601()),
            $value instanceof CooperDateTime => self::tagged('casc/date-time', $value->toIso8601()),
            $value instanceof CooperTuple => ['tuple' => array_map(self::encode(...), $value->items)],
            $value instanceof MapNode => ['map' => self::pairs($value)],
            default => throw new \InvalidArgumentException('the suite has no type for ' . get_debug_type($value)),
        };
    }

    /**
     * One case's result. A `CooperError` is its stage; anything else
     * thrown is this implementation's failure on this case, answered as
     * a crash: the suite reports it as a disagreement rather than losing
     * every other case's answer with it.
     *
     * @return array{id: mixed, result?: mixed, error?: string}
     */
    private static function result(mixed $case): array
    {
        $case = is_array($case) ? $case : [];
        $id = $case['id'] ?? null;
        $operation = $case['operation'] ?? null;

        if ($operation !== 'load' || !array_key_exists('document', $case)) {
            return ['id' => $id, 'error' => 'unknown operation: ' . self::inspect($operation)];
        }

        try {
            $document = $case['document'];
            if (!is_string($document)) {
                throw new \InvalidArgumentException('the document is not a path: ' . self::inspect($document));
            }

            return ['id' => $id, 'result' => self::encode(self::load($document))];
        } catch (CooperError $e) {
            return ['id' => $id, 'error' => $e->stage];
        } catch (\Throwable $e) {
            return ['id' => $id, 'error' => 'crash: ' . $e->getMessage()];
        }
    }

    /**
     * The load options every case runs with (see the class documentation).
     *
     * @return array<string, mixed>
     */
    private static function options(string $root): array
    {
        return [
            'env' => self::ENV,
            'dotenv' => false,
            'cache' => false,
            'root' => $root,
            'resolvers' => [
                'echo' => static fn (string $payload): string => $payload,
                'fail' => static fn (string $payload): never => throw new \RuntimeException("refused {$payload}"),
            ],
            'tags' => [
                'twice' => static function (mixed $argument): int|string {
                    if (is_int($argument)) {
                        return $argument * 2;
                    }
                    if (is_string($argument)) {
                        return $argument . $argument;
                    }

                    throw new \RuntimeException('cannot double ' . get_debug_type($argument));
                },
                // The built-in `!module`, marked so the answer can name the
                // module rather than show a class-string no string differs from;
                // a name `MODULES` maps is what it maps to, as in a load.
                'module' => static function (mixed $argument): mixed {
                    $module = Resolver::tagModule($argument);
                    assert(is_string($module));
                    $written = new ConformanceModule($module);

                    return self::MODULES[$written->writtenName()] ?? $written;
                },
            ],
            'importSchemes' => [
                'mem' => static fn (string $rest): string => self::MEM[$rest] ?? throw new \RuntimeException('enoent'),
            ],
        ];
    }

    /**
     * A tuple tagged with a namespaced keyword: `{"tuple": [{"keyword": tag}, ...items]}`.
     *
     * @return array{tuple: list<mixed>}
     */
    private static function tagged(string $tag, mixed ...$items): array
    {
        return ['tuple' => [['keyword' => $tag], ...array_values($items)]];
    }

    /**
     * A map's pairs, `[key, typed value]`, sorted by key byte for byte.
     *
     * @return list<array{0: string, 1: mixed}>
     */
    private static function pairs(MapNode $map): array
    {
        $keys = $map->keys();
        sort($keys, SORT_STRING);

        return array_map(static fn (string $key): array => [$key, self::encode($map->get($key))], $keys);
    }

    /**
     * A request's value as an error message shows it, the way Elixir's
     * `inspect/1` does for what a JSON request can hold: `"dance"`, `nil`.
     */
    private static function inspect(mixed $value): string
    {
        return $value === null ? 'nil' : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

<?php

declare(strict_types=1);

namespace JOetjen\Cooper;

use JOetjen\Cooper\Pipeline\Grammar;
use JOetjen\Cooper\Pipeline\Options;
use JOetjen\Cooper\Pipeline\Paths;
use JOetjen\Cooper\Pipeline\Resolver;
use JOetjen\Cooper\Pipeline\TreeResult;

/**
 * Loads CASC config files (see `casc/CASC.md`) into native PHP values.
 *
 * `loadFile()`/`loadString()` (and `loadFileTraced()`, which also
 * reports what the load depended on) run the whole pipeline: parse, evaluate
 * (loops and imports expanded in place), merge (secret-wrapping
 * included), and resolve every `@{...}`/`${...}`/`%{...}`/`!{...}`/
 * `!Name(...)` reference. `loadFile()` caches everything before the
 * resolve step (see `Cache`).
 *
 * ## The result
 *
 * A block/map is an associative `array` (string keys -- PHP turns a
 * numeric-looking one into an `int` key), a list is a list `array`.
 * Everything a PHP array cannot represent faithfully gets a value class
 * under `JOetjen\Cooper\Value`: `CooperTuple` (tuples are never lists,
 * CASC.md §6.11), `CooperAtom` (`level = info`), `CooperDuration`,
 * `CooperBytes`, `CooperIPv4`/`CooperIPv6`, `CooperDate`/`CooperTime`/
 * `CooperLocalDateTime`, and `CooperSecret` around every `*key` value.
 * An offset date-time is a `CooperDateTime` in UTC; `inf` is `INF`.
 *
 * ## Options
 *
 * Both methods take the same options array:
 *
 *  - `env` (`array<string, string>`) -- an override layer for `${...}`,
 *    on top of `.env` files and the real environment (see `Dotenv`).
 *  - `dotenv` (`bool`), `dotenvEnv` (`?string`), `dotenvFiles`
 *    (`list<string>`), `dotenvDir` (`string`, default: the project
 *    root -- the Composer root package's directory, else the working
 *    directory), `dotenvOverride` (`bool`) -- `.env` file loading;
 *    see `Dotenv` for the layering rules.
 *  - `root` (`string`) -- the directory a bare `import` resolves against.
 *    `loadFile()` defaults it to the file's own directory,
 *    `loadString()` to the working directory.
 *  - `resolvers` (`array<string, callable(string): mixed>`) -- one per
 *    `!{name:...}` resolver (CASC.md §7.4). Return the value, or throw.
 *  - `tags` (`array<string, callable(mixed): mixed>`) -- `!Name(...)`
 *    tags beyond the built-in `int`, `float`, `bool`, `duration`,
 *    `bytes`, `trim`, `downcase`, `upcase`, `module` (§7.5). A tag
 *    given as a `LiteralTag` takes only a string literal written in the
 *    document, and refuses the load otherwise (see `LiteralTag`).
 *  - `modules` (`array<string, mixed>`) -- what `!module("Name")` gives
 *    back (§7.5), by the name exactly as written (case-sensitive, after
 *    trimming); typically a class-string. A name it does not hold is
 *    translated by convention: `Acme.Payments` -> `"Acme\Payments"`.
 *    Either way the class is never loaded or checked. The name itself
 *    must be dot-separated PascalCase, mapped or not.
 *  - `importSchemes` (`array<string, callable(string): string>`) -- one
 *    per `import "scheme://..."` scheme (§9.3), returning CASC source.
 *  - `cache` (`bool`, default `true`) -- `loadFile()` only.
 *  - `envValue` (`callable(EnvReference, \Closure(): mixed): mixed`) --
 *    decides what a `${NAME}` written as a value resolves to, for a
 *    host that resolves the environment later than the load (a
 *    framework keeping it a runtime placeholder). It receives the
 *    reference, described by an `EnvReference`, and a closure giving
 *    the value Cooper would have resolved; what it returns stands in
 *    the reference's place. A `${NAME}` that shapes the document -- a
 *    `${?NAME}` guard, an import path, an interpolated key -- never
 *    reaches it. A name it answers for without calling the closure was
 *    never read, and is not in a `LoadTrace`'s `$env`. Without it, every
 *    `${NAME}` resolves from the environment, as it always has.
 *
 * Unregistered resolvers, tags, and schemes are always load-time errors
 * naming the offender (§9.4).
 *
 * ## Errors
 *
 * Every failure throws `CooperError`, whose `$stage` says which part of
 * the pipeline failed. Malformed options throw `\InvalidArgumentException`.
 */
final class Cooper
{
    private function __construct()
    {
    }

    /**
     * Reads and loads the CASC file at `$path`. Bare imports resolve
     * relative to the file's own directory, and an import cycle back to
     * the file itself is caught on the first repeat.
     *
     * Cached by default: the pre-resolve tree is reused as long as the
     * file and everything it imports are unchanged on disk, and the
     * environment variables that shaped it still hold the same values
     * (see `Cache`). `${...}` values are always re-read.
     *
     * @param string $path the CASC file, relative to the working directory or absolute
     * @param array<string, mixed> $options see the class documentation
     * @return array<array-key, mixed> the loaded config
     * @throws CooperError when loading fails
     * @throws \InvalidArgumentException on malformed options
     */
    public static function loadFile(string $path, array $options = []): array
    {
        return self::loadFileTraced($path, $options)->config;
    }

    /**
     * `loadFile()`, together with what the load depended on: every file
     * it read, every import's expansion, and every environment variable it read, with the value it
     * saw (see `LoadTrace`). The config is the same one `loadFile()`
     * returns, cached the same way.
     *
     * For a caller that keeps the result where `Cache` cannot see it --
     * a compiled file, a shared store -- and must tell for itself when
     * that copy has gone stale.
     *
     * @param string $path the CASC file, relative to the working directory or absolute
     * @param array<string, mixed> $options see the class documentation
     * @return LoadTrace the config and what it depended on
     * @throws CooperError when loading fails
     * @throws \InvalidArgumentException on malformed options
     */
    public static function loadFileTraced(string $path, array $options = []): LoadTrace
    {
        $opts = Options::from($options);
        $absolute = Paths::expand($path);
        $source = Grammar::read($path, $absolute);
        $root = $opts->root ?? dirname($absolute);
        $env = Dotenv::fromOptions($opts);

        $load = static fn (): TreeResult => Grammar::runTree($source, $opts->grammar($env, $root, $absolute));

        $result = $opts->cache
            ? Cache::fetch($absolute, $root, $env) ?? Cache::populate($absolute, $root, $env, $load)
            : $load();
        [$config, $names] = self::resolve($result, $opts, $env);

        // Shape names come from parsing (a cached tree remembers them);
        // value names from this resolution. A guard is frequently read
        // nowhere else, so either list alone would miss names.
        $read = [];
        foreach (array_merge($result->shapeEnvNames, $names) as $name) {
            $value = $env[$name] ?? '';
            $read[$name] = $value === '' ? null : $value;
        }

        return new LoadTrace($config, $result->loadedFiles, $read, $result->loadedGlobs);
    }

    /**
     * Loads `$source` -- a complete CASC file's text, version header
     * included -- through the same pipeline as `loadFile()`, without
     * caching (there is no file to key a cache on). Bare imports need a
     * real `root`, unless they go through `importSchemes`.
     *
     * @param string $source the CASC text
     * @param array<string, mixed> $options see the class documentation
     * @return array<array-key, mixed> the loaded config
     * @throws CooperError when loading fails
     * @throws \InvalidArgumentException on malformed options
     */
    public static function loadString(string $source, array $options = []): array
    {
        $opts = Options::from($options);
        $env = Dotenv::fromOptions($opts);

        return self::resolve(Grammar::runTree($source, $opts->grammar($env)), $opts, $env)[0];
    }

    /**
     * @param array<string, string> $env
     * @return array{0: array<array-key, mixed>, 1: list<string>} the config, and every `${NAME}` resolution read
     */
    private static function resolve(TreeResult $result, Options $opts, array $env): array
    {
        [$resolved, $names] = Resolver::resolve($result->tree, [
            'vars' => $result->vars,
            'env' => $env,
            'resolvers' => $opts->resolvers,
            'tags' => $opts->tags,
            'modules' => $opts->modules,
            'envValue' => $opts->envValue,
        ]);
        assert(is_array($resolved));

        return [$resolved, $names];
    }
}

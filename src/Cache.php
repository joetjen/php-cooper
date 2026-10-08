<?php

declare(strict_types=1);

namespace JOetjen\Cooper;

use JOetjen\Cooper\Pipeline\Paths;
use JOetjen\Cooper\Pipeline\TreeResult;

/**
 * Backs `Cooper::loadFile()`'s default caching -- the Elixir original's
 * `Cooper.Cache`. It caches the *pre-resolve* tree (parse, imports,
 * loops, merge) per entry file and root, and reuses it as long as every
 * file that contributed -- the entry file and every file it
 * transitively imports -- still has the modification time it had when
 * cached, and every import pattern (`import "parts/*.casc"`, braces
 * too) still matches the files it matched then -- a file added where an
 * import looks, or removed from there, is a change like an edited one.
 * `${...}` resolution is never cached: it re-runs against the current
 * environment on every load, hit or miss.
 *
 * The cache lives in process memory: within one request for a classic
 * PHP-FPM setup, across requests for a long-running worker (RoadRunner,
 * Swoole, FrankenPHP worker mode, a queue consumer, a CLI daemon).
 *
 * ## Environment-shaped trees
 *
 * Some environment reads decide the tree's *shape* rather than a value
 * in it: a `${?NAME}` guard, `${NAME}` in an import path, `${NAME}` in
 * an interpolated key. A cached entry remembers those values and is
 * dropped -- and re-parsed -- on the next load that sees any of them
 * changed, firing a `CacheEvent::ENV_CHANGED` event. This replaces the
 * Elixir original's background polling (`watch_env`), which PHP has no
 * process to run; checking synchronously on each load is also exact,
 * where polling is up to one interval stale.
 *
 * A `scheme://` import has no file to fingerprint; its content is only
 * re-read when something file-backed in the same load changes.
 *
 * ## Events
 *
 * `listen()` registers a callback receiving a `CacheEvent` -- the
 * counterpart of the Elixir original's telemetry events.
 */
final class Cache
{
    /** @var array<string, array{path: string, root: string, fingerprint: array<string, int|null>, globs: list<ImportGlob>, shapeEnv: array<string, string|null>, result: TreeResult}> */
    private static array $entries = [];

    /** @var array<int, callable(CacheEvent): void> */
    private static array $listeners = [];

    private static int $nextListenerId = 1;

    private function __construct()
    {
    }

    /**
     * The cached pre-resolve tree for `$path`/`$root`, or `null` on a
     * miss: no entry, a contributing file changed, an import now matches
     * different files, or a tree-shaping
     * environment variable changed (which also drops the entry and fires
     * `CacheEvent::ENV_CHANGED`).
     *
     * @param string $path the entry file's absolute path
     * @param string $root the root its bare imports resolve against
     * @param array<string, string> $env the environment this load sees
     * @return TreeResult|null the cached tree, or null on a miss
     */
    public static function fetch(string $path, string $root, array $env): ?TreeResult
    {
        $key = self::key($path, $root);
        $entry = self::$entries[$key] ?? null;
        if ($entry === null || !self::fingerprintValid($entry['fingerprint'], $entry['globs'])) {
            return null;
        }

        $changed = [];
        foreach ($entry['shapeEnv'] as $name => $value) {
            if (($env[$name] ?? null) !== $value) {
                $changed[] = (string) $name;
            }
        }
        if ($changed !== []) {
            unset(self::$entries[$key]);
            self::emit(new CacheEvent(CacheEvent::ENV_CHANGED, $path, $root, $changed, microtime(true)));

            return null;
        }

        return $entry['result'];
    }

    /**
     * Populates (or refreshes) the entry for `$path`/`$root` by calling
     * `$loader`, firing `CacheEvent::FILE_CHANGED` when this refreshes an
     * entry whose files changed. A loader that throws caches nothing.
     *
     * @param string $path the entry file's absolute path
     * @param string $root the root its bare imports resolve against
     * @param array<string, string> $env the environment the load used
     * @param callable(): TreeResult $loader produces the tree
     * @return TreeResult the freshly loaded tree
     */
    public static function populate(string $path, string $root, array $env, callable $loader): TreeResult
    {
        $key = self::key($path, $root);
        $previous = self::$entries[$key] ?? null;
        $result = $loader();

        $fingerprint = [];
        foreach ($result->loadedFiles as $file) {
            $fingerprint[$file] = self::mtime($file);
        }
        $shapeEnv = [];
        foreach ($result->shapeEnvNames as $name) {
            $shapeEnv[$name] = $env[$name] ?? null;
        }

        self::$entries[$key] = [
            'path' => $path,
            'root' => $root,
            'fingerprint' => $fingerprint,
            'globs' => $result->loadedGlobs,
            'shapeEnv' => $shapeEnv,
            'result' => $result,
        ];

        if ($previous !== null) {
            // Only files are named: one an import gained or lost already
            // appears in, or disappears from, the files read.
            $changed = [];
            foreach (array_keys($previous['fingerprint'] + $fingerprint) as $file) {
                if (($previous['fingerprint'][$file] ?? null) !== ($fingerprint[$file] ?? null)) {
                    $changed[] = (string) $file;
                }
            }
            if ($changed !== []) {
                sort($changed, SORT_STRING);
                self::emit(new CacheEvent(CacheEvent::FILE_CHANGED, $path, $root, $changed, microtime(true)));
            }
        }

        return $result;
    }

    /**
     * Removes every cached entry for `$path` (under any root).
     *
     * @param string $path the entry file, relative to the working directory or absolute
     */
    public static function invalidate(string $path): void
    {
        $absolute = Paths::expand($path);
        foreach (self::$entries as $key => $entry) {
            if ($entry['path'] === $absolute) {
                unset(self::$entries[$key]);
            }
        }
    }

    /**
     * Removes every cached entry.
     */
    public static function clear(): void
    {
        self::$entries = [];
    }

    /**
     * Registers a listener for cache events.
     *
     * @param callable(CacheEvent): void $listener called synchronously, during the load that detects the change
     * @return int an id for `unlisten()`
     */
    public static function listen(callable $listener): int
    {
        $id = self::$nextListenerId++;
        self::$listeners[$id] = $listener;

        return $id;
    }

    /**
     * Removes a listener registered with `listen()`.
     *
     * @param int $id the id `listen()` returned
     */
    public static function unlisten(int $id): void
    {
        unset(self::$listeners[$id]);
    }

    private static function key(string $path, string $root): string
    {
        return $root . "\0" . $path;
    }

    /**
     * Both halves of a fingerprint are compared against disk: a file
     * edited or deleted changes its mtime, and a file added where an
     * import looks -- or removed from there -- changes what the pattern
     * expands to.
     *
     * @param array<string, int|null> $fingerprint
     * @param list<ImportGlob> $globs
     */
    private static function fingerprintValid(array $fingerprint, array $globs): bool
    {
        foreach ($fingerprint as $file => $mtime) {
            if (self::mtime((string) $file) !== $mtime) {
                return false;
            }
        }
        foreach ($globs as $glob) {
            if ($glob->changed()) {
                return false;
            }
        }

        return true;
    }

    private static function mtime(string $file): ?int
    {
        clearstatcache(true, $file);
        $mtime = @filemtime($file);

        return $mtime === false ? null : $mtime;
    }

    private static function emit(CacheEvent $event): void
    {
        foreach (self::$listeners as $listener) {
            $listener($event);
        }
    }
}

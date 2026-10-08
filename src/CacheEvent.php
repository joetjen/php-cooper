<?php

declare(strict_types=1);

namespace JOetjen\Cooper;

/**
 * What a `Cache::listen()` listener receives -- the PHP counterpart of
 * the Elixir original's `[:cooper, :cache, :file_changed]` and
 * `[:cooper, :cache, :env_changed]` telemetry events.
 *
 *  - `FILE_CHANGED`: a previously cached entry was found stale because a
 *    contributing file's modification time changed, and has just been
 *    re-parsed. `$changed` lists those files. Never fired for an
 *    entry's first population.
 *  - `ENV_CHANGED`: an environment variable that shaped a cached tree
 *    (a `${?NAME}` guard, `${NAME}` in an import path or an interpolated
 *    key) has a different value now, so the entry was dropped and the
 *    file re-parsed. `$changed` lists those names.
 */
final class CacheEvent
{
    public const FILE_CHANGED = 'file_changed';
    public const ENV_CHANGED = 'env_changed';

    /**
     * @param string $type `FILE_CHANGED` or `ENV_CHANGED`
     * @param string $path the cached entry file's absolute path
     * @param string $root the root its bare imports resolve against
     * @param list<string> $changed the changed files, or the changed variable names
     * @param float $time when it happened, as a Unix timestamp with microseconds
     */
    public function __construct(
        public readonly string $type,
        public readonly string $path,
        public readonly string $root,
        public readonly array $changed,
        public readonly float $time,
    ) {
    }
}

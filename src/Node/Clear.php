<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Node;

/**
 * "Delete everything under `$path` first" -- emitted once, ahead of a
 * `~key { ... }` block's own leaves (CASC.md §8.4: `~server { port =
 * 9090 }` must drop `host` too, not just set `port`). Elixir: the
 * `{:clear, path}` entry.
 *
 * @internal unresolved pipeline data
 */
final class Clear
{
    /**
     * @param list<string|InterpText> $path
     */
    public function __construct(public readonly array $path)
    {
    }
}

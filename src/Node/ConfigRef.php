<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Node;

/**
 * An unresolved `%{path}` config reference (CASC.md §7.3; Elixir:
 * `Cooper.Ref.Config`) -- lazy, resolved against the fully merged final
 * tree, cycle-checked there. A path segment is an `InterpText` for a
 * built key (`%{tokens."supervisor-@{id}"}`).
 *
 * @internal unresolved pipeline data
 */
final class ConfigRef
{
    /**
     * @param list<string|InterpText> $path
     * @param list<Filter> $filters
     */
    public function __construct(
        public readonly array $path,
        public readonly ?int $index = null,
        public readonly ?Suffix $suffix = null,
        public readonly array $filters = [],
    ) {
    }
}

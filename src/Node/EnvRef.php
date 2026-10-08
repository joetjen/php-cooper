<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Node;

/**
 * An unresolved `${NAME}` environment reference (CASC.md §7.2; Elixir:
 * `Cooper.Ref.Env`) -- always a string on success, a list for the
 * `${NAME[]}` form. `$name` is an `InterpText` for a built name.
 *
 * @internal unresolved pipeline data
 */
final class EnvRef
{
    /**
     * @param list<Filter> $filters
     */
    public function __construct(
        public readonly string|InterpText $name,
        public readonly ?int $index = null,
        public readonly bool $list = false,
        public readonly ?Suffix $suffix = null,
        public readonly array $filters = [],
    ) {
    }
}

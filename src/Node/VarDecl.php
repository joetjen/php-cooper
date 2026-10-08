<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Node;

/**
 * A parsed `@name = value` / `@*name = value` variable declaration
 * (CASC.md §5.2; Elixir: `Cooper.VarDecl`). Visibility across imports is
 * `Loader`/`Resolver` territory -- this only carries what the grammar
 * can determine locally.
 *
 * @internal unresolved pipeline data
 */
final class VarDecl
{
    public function __construct(
        public readonly string $name,
        public readonly mixed $value,
        public readonly bool $public,
    ) {
    }
}

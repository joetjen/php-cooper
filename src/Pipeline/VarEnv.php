<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Pipeline;

/**
 * A load's variable environment (CASC.md §5.2): `$public` -- every
 * public `@name`, shared by every file in the load -- and
 * `$privateByScope` -- each file's own `@*name` declarations, keyed by
 * that file's scope (see `Scope`), consulted only for a reference
 * stamped with the same scope.
 *
 * @internal
 */
final class VarEnv
{
    /**
     * @param array<string, mixed> $public
     * @param array<string, array<string, mixed>> $privateByScope
     */
    public function __construct(
        public readonly array $public = [],
        public readonly array $privateByScope = [],
    ) {
    }
}

<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Node;

/**
 * An unresolved `@{name}` variable reference (CASC.md §7.1; Elixir:
 * `Cooper.Ref.Var`). `$name` is an `InterpText` for a built name
 * (`@{"@{which}"}`, CASC.md §7.2).
 *
 * `$scope` is the identity of the file the reference was *written in*,
 * stamped by `Scope::stamp()` once that file finishes parsing. It is
 * what lets a single resolve pass over the flattened, spliced-together
 * tree still honour per-file private (`@*name`) visibility.
 *
 * `$bound` is `[value]` for a reference to a `for` loop binding that
 * also carries an index, a suffix, or filters (`@{x | upcase}`): the
 * iteration's value is attached to the reference, which then resolves
 * like any other -- rather than being replaced by the bare value, which
 * dropped what it carried. See `Loop`.
 *
 * @internal unresolved pipeline data
 */
final class VarRef
{
    /**
     * @param list<Filter> $filters
     * @param array{0: mixed}|null $bound a loop binding's value, boxed so that nil can be bound
     */
    public function __construct(
        public readonly string|InterpText $name,
        public readonly ?int $index = null,
        public readonly ?Suffix $suffix = null,
        public readonly array $filters = [],
        public readonly ?string $scope = null,
        public readonly ?array $bound = null,
    ) {
    }
}

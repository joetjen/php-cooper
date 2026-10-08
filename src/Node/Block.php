<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Node;

/**
 * A block written as a list or tuple element (`[{ path = "^/admin" }]`,
 * CASC.md §6.10) -- Elixir: `Cooper.Block` as an element. It keeps its
 * flattened entries until resolution, where `Resolver` resolves their
 * keys (which may interpolate, and may need the finished tree) and
 * merges them into a map the way a document's are. Merging it any
 * earlier would have to resolve those keys before the variables and
 * loop bindings they name are settled.
 *
 * @internal unresolved pipeline data
 */
final class Block
{
    /**
     * @param list<Op|VarDecl|Clear> $entries
     */
    public function __construct(public readonly array $entries)
    {
    }

    /**
     * The same block with `$fn` applied to every entry -- how `Scope`
     * stamps it and `Loop` substitutes into it.
     *
     * @param callable(Op|VarDecl|Clear): (Op|VarDecl|Clear) $fn
     */
    public function map(callable $fn): self
    {
        return new self(array_map($fn, $this->entries));
    }
}

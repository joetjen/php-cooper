<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Node;

/**
 * One flattened leaf assignment (Elixir: `Cooper.Op`): every assignment
 * or block statement -- regardless of surface form, dotted path, or
 * nested block -- flattens into a list of these before merge ever runs,
 * so `Merge` only ever deep-merges paths, never re-discovers which parts
 * of two differently-shaped literals mean the same key.
 *
 * A path segment is a plain string, or an `InterpText` for an
 * interpolated double-quoted key (`"domain-@{idx}"`) that `Merge`
 * resolves to a string before folding.
 *
 * @internal unresolved pipeline data -- seen only when calling a pipeline stage directly
 */
final class Op
{
    public const MERGE = 'merge';
    public const REPLACE = 'replace';
    public const APPEND = 'append';
    public const REMOVE = 'remove';
    public const DELETE = 'delete';

    /**
     * @param list<string|InterpText> $path
     * @param string $sigil one of the class constants
     */
    public function __construct(
        public readonly array $path,
        public readonly string $sigil,
        public readonly mixed $value,
        public readonly bool $secret = false,
    ) {
    }
}

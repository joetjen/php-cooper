<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Node;

/**
 * A double-quoted string's content once it is found to contain at least
 * one reference (Elixir: `Cooper.Interp.Text`) -- an ordered list of
 * literal runs and unresolved reference nodes. A double-quoted string
 * with no references stays a plain PHP string.
 *
 * @internal unresolved pipeline data
 */
final class InterpText
{
    /**
     * @param list<mixed> $segments literal strings and unresolved reference nodes
     */
    public function __construct(public readonly array $segments)
    {
    }
}

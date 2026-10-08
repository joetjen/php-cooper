<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Node;

/**
 * One `| name` or `| name: "arg"` filter clause on a reference (CASC.md
 * §7.2).
 *
 * @internal unresolved pipeline data
 */
final class Filter
{
    public function __construct(
        public readonly string $name,
        public readonly string|InterpText|null $argument = null,
    ) {
    }
}

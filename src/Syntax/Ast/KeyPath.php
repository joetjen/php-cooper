<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Syntax\Ast;

/**
 * A dotted key path (CASC.md §5.4).
 *
 * @internal
 */
final class KeyPath
{
    public function __construct(
        /** @var list<KeySegment> */
        public readonly array $segments,
    ) {
    }
}

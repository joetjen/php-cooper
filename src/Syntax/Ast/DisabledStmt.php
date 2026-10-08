<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Syntax\Ast;

/**
 * A `#`-disabled statement (CASC.md §5.6) -- parsed, never evaluated.
 *
 * @internal
 */
final class DisabledStmt implements Stmt
{
    public function __construct(
        public readonly Stmt $statement,
    ) {
    }
}

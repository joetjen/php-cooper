<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Syntax\Ast;

/**
 * A `{ ... }` block (CASC.md §5.4).
 *
 * @internal
 */
final class BlockExpr
{
    public function __construct(
        /** @var list<Stmt> */
        public readonly array $statements,
    ) {
    }
}

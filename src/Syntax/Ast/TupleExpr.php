<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Syntax\Ast;

/**
 * A `( ... )` tuple (CASC.md §6.11).
 *
 * @internal
 */
final class TupleExpr implements Expr
{
    public function __construct(
        /** @var list<Expr|BlockExpr> an element may be a block -- a map (CASC.md §6.10) */
        public readonly array $items,
    ) {
    }
}

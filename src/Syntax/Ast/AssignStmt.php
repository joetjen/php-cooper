<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Syntax\Ast;

/**
 * An assignment or block statement with an optional `~`/`+`/`-` merge sigil (CASC.md §5.3-§5.4, §5.7).
 *
 * @internal
 */
final class AssignStmt implements Stmt
{
    public function __construct(
        public readonly ?string $sigil,
        public readonly KeyPath $keyPath,
        public readonly Expr|BlockExpr $rhs,
    ) {
    }
}

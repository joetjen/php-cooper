<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Syntax\Ast;

use JOetjen\Cooper\Syntax\Token;

/**
 * `@name = value` / `@*name = value` (CASC.md §5.2).
 *
 * @internal
 */
final class VarDeclStmt implements Stmt
{
    public function __construct(
        public readonly bool $private,
        public readonly Token $name,
        public readonly Expr $value,
    ) {
    }
}

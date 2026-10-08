<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Syntax\Ast;

use JOetjen\Cooper\Syntax\Token;

/**
 * A `!Name(argument)` tagged value (CASC.md §7.5).
 *
 * @internal
 */
final class TaggedExpr implements Expr
{
    public function __construct(
        public readonly Token $name,
        public readonly Expr $argument,
    ) {
    }
}

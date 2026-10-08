<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Syntax\Ast;

use JOetjen\Cooper\Syntax\Token;

/**
 * A literal-value token, converted to its PHP value only when evaluated.
 *
 * @internal
 */
final class LiteralExpr implements Expr
{
    public function __construct(
        public readonly Token $token,
    ) {
    }
}

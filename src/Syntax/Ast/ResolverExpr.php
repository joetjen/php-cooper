<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Syntax\Ast;

use JOetjen\Cooper\Syntax\Token;

/**
 * A `!{resolver:payload}` reference (CASC.md §7.4), raw text kept whole.
 *
 * @internal
 */
final class ResolverExpr implements Expr
{
    public function __construct(
        public readonly Token $token,
    ) {
    }
}

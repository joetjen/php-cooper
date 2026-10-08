<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Syntax\Ast;

use JOetjen\Cooper\Syntax\Token;

/**
 * A bare (`info`) or colon-prefixed (`:info`, `:inf`) atom (CASC.md §6.4).
 * `:nil`/`:true`/`:false` parse as one too, and evaluate to the values.
 *
 * @internal
 */
final class AtomExpr implements Expr
{
    public function __construct(
        public readonly Token $token,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Syntax\Ast;

use JOetjen\Cooper\Syntax\Token;

/**
 * A bare `@{name...}` variable reference (CASC.md §7.1).
 *
 * @internal
 */
final class AtRefExpr implements Expr
{
    public function __construct(
        public readonly Token $name,
        public readonly ?Token $index,
        public readonly ?SuffixExpr $suffix,
        /** @var list<FilterExpr> */
        public readonly array $filters,
    ) {
    }
}

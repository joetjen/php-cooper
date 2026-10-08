<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Syntax\Ast;

use JOetjen\Cooper\Syntax\Token;

/**
 * A bare `%{path...}` config reference (CASC.md §7.3).
 *
 * @internal
 */
final class ConfigRefExpr implements Expr
{
    public function __construct(
        public readonly KeyPath $path,
        public readonly ?Token $index,
        public readonly ?SuffixExpr $suffix,
        /** @var list<FilterExpr> */
        public readonly array $filters,
    ) {
    }
}

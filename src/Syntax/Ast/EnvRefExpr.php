<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Syntax\Ast;

use JOetjen\Cooper\Syntax\Token;

/**
 * A bare `${NAME...}` environment reference (CASC.md §7.2). `$bracket` without `$index` is the `${NAME[]}` list form.
 *
 * @internal
 */
final class EnvRefExpr implements Expr
{
    public function __construct(
        public readonly Token $name,
        public readonly bool $bracket,
        public readonly ?Token $index,
        public readonly ?SuffixExpr $suffix,
        /** @var list<FilterExpr> */
        public readonly array $filters,
    ) {
    }
}

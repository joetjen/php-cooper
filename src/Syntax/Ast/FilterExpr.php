<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Syntax\Ast;

use JOetjen\Cooper\Syntax\Token;

/**
 * One `| filter` or `| filter: "arg"` clause (CASC.md §7.2).
 *
 * @internal
 */
final class FilterExpr
{
    public function __construct(
        public readonly Token $name,
        public readonly ?Token $argument,
    ) {
    }
}

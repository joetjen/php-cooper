<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Syntax\Ast;

use JOetjen\Cooper\Syntax\Token;

/**
 * A reference suffix: `:default`, `:+alt`, or `:?"message"` (CASC.md §7.2).
 *
 * @internal
 */
final class SuffixExpr
{
    public function __construct(
        public readonly string $kind,
        public readonly Expr|Token $value,
    ) {
    }
}

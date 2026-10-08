<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Syntax\Ast;

use JOetjen\Cooper\Syntax\Token;

/**
 * One `for` binding: `@name` (index) or `@name in <iterable>` (element), CASC.md §5.5.
 *
 * @internal
 */
final class Binding
{
    public function __construct(
        public readonly Token $name,
        public readonly ?Expr $iterable,
    ) {
    }
}

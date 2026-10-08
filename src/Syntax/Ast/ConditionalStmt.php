<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Syntax\Ast;

use JOetjen\Cooper\Syntax\Token;

/**
 * A statement guarded by `${?NAME}` (CASC.md §7.2).
 *
 * @internal
 */
final class ConditionalStmt implements Stmt
{
    public function __construct(
        public readonly Token $name,
        public readonly Stmt $statement,
    ) {
    }
}

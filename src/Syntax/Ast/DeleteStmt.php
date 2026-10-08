<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Syntax\Ast;

/**
 * A bare `-key.path` delete (CASC.md §5.7).
 *
 * @internal
 */
final class DeleteStmt implements Stmt
{
    public function __construct(
        public readonly KeyPath $keyPath,
    ) {
    }
}

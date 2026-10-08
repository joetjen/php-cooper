<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Syntax\Ast;

use JOetjen\Cooper\Syntax\Token;

/**
 * `import "path"` (CASC.md §5.1).
 *
 * @internal
 */
final class ImportStmt implements Stmt
{
    public function __construct(
        public readonly Token $path,
    ) {
    }
}

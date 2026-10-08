<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Syntax\Ast;

/**
 * A whole parsed CASC file: its version header and statements.
 *
 * @internal
 */
final class FileNode
{
    public function __construct(
        /** @var list<int> */
        public readonly array $version,
        /** @var list<Stmt> */
        public readonly array $statements,
    ) {
    }
}

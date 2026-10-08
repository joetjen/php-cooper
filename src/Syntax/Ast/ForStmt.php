<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Syntax\Ast;

/**
 * `for <bindings> [from <template>] as <dest> { ... }` (CASC.md §5.5).
 *
 * @internal
 */
final class ForStmt implements Stmt
{
    public function __construct(
        /** @var list<Binding> */
        public readonly array $bindings,
        public readonly ?KeyPath $template,
        public readonly KeyPath $dest,
        public readonly BlockExpr $body,
    ) {
    }
}

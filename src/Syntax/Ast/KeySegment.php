<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Syntax\Ast;

use JOetjen\Cooper\Syntax\Token;

/**
 * One key-path segment: an identifier, or a double-/single-quoted string, optionally `*`-marked secret (CASC.md §4.2-§4.3).
 *
 * @internal
 */
final class KeySegment
{
    public function __construct(
        public readonly bool $secret,
        public readonly Token $token,
    ) {
    }
}

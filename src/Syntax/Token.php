<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Syntax;

/**
 * One lexed token, with its 1-based source position. `$endLine` is the
 * line its last character sits on -- different from `$line` only for a
 * string spanning several lines.
 *
 * @internal
 */
final class Token
{
    public function __construct(
        public readonly TokenType $type,
        public readonly string $text,
        public readonly int $line,
        public readonly int $column,
        public readonly int $endLine,
    ) {
    }

    public function is(TokenType $type): bool
    {
        return $this->type === $type;
    }

    /**
     * Whether this is an identifier spelled exactly `$word` -- how the
     * contextual keywords (`for`, `in`, `from`, `as`, `import`,
     * `version`) are recognized, since CASC.md §4.1 deliberately keeps
     * them ordinary identifiers everywhere else.
     */
    public function isWord(string $word): bool
    {
        return $this->type === TokenType::Ident && $this->text === $word;
    }

    public function describe(): string
    {
        return $this->type === TokenType::Eof ? 'end of input' : json_encode($this->text, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '';
    }
}

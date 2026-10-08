<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Syntax;

/**
 * Every token kind `Lexer` produces -- the named tokens of
 * `casc.aether` (the Elixir original's grammar file), the punctuation
 * literals its rules use, and end-of-input.
 *
 * @internal
 */
enum TokenType: string
{
    case NilKw = 'NIL_KW';
    case TrueKw = 'TRUE_KW';
    case FalseKw = 'FALSE_KW';
    case InfKw = 'INF_KW';
    case Ident = 'IDENT';
    case Date = 'DATE';
    case Time = 'TIME';
    case DateTime = 'DATETIME';
    case IPv4 = 'IPV4';
    case IPv6 = 'IPV6';
    case Duration = 'DURATION';
    case Bytes = 'BYTES';
    case Integer = 'INTEGER';
    case Float = 'FLOAT';
    case TripleString = 'TRIPLE_STRING';
    case DqString = 'DQ_STRING';
    case SqString = 'SQ_STRING';
    case ResolverRefRaw = 'RESOLVER_REF_RAW';

    case EnvGuardOpen = '${?';
    case EnvOpen = '${';
    case AtOpen = '@{';
    case ConfigOpen = '%{';
    case LBrace = '{';
    case RBrace = '}';
    case LBracket = '[';
    case RBracket = ']';
    case LParen = '(';
    case RParen = ')';
    case Equals = '=';
    case Hash = '#';
    case At = '@';
    case Bang = '!';
    case Colon = ':';
    case Question = '?';
    case Pipe = '|';
    case Comma = ',';
    case Dot = '.';
    case Star = '*';
    case Tilde = '~';
    case Plus = '+';
    case Minus = '-';

    case Eof = 'EOF';

    /**
     * Whether this is one of the literal-value tokens CASC.md §6 defines
     * (everything `value` accepts besides lists, tuples, references, and
     * atoms).
     */
    public function isLiteral(): bool
    {
        return match ($this) {
            self::NilKw, self::TrueKw, self::FalseKw, self::InfKw,
            self::DateTime, self::Date, self::Time, self::IPv6, self::IPv4,
            self::Duration, self::Bytes, self::Float, self::Integer,
            self::TripleString, self::DqString, self::SqString => true,
            default => false,
        };
    }
}

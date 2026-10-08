<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Syntax;

use JOetjen\Cooper\CooperError;

/**
 * Tokenizes CASC source, reproducing the Elixir original's
 * `casc.aether` token set and its tokenizer's rules: trivia (whitespace
 * and `#` comments) is skipped between tokens, and at each position
 * every token kind competes -- the longest match wins, ties going to
 * the kind declared first (keywords before identifiers, ...). That
 * "maximal munch" race is what makes `info` an atom but `inf` infinity,
 * `1.2.3.4` an address rather than a float, and `1h30m` a duration.
 *
 * The patterns use possessive quantifiers to match PEG semantics
 * (greedy, never backtracking) rather than ordinary regex backtracking.
 *
 * One deliberate difference: `+inf`/`-inf`/`inf` only count as the
 * infinity keyword when no identifier character follows. Without that,
 * `+info = [...]` lexes as `+inf` followed by `o` and fails to parse
 * at all in the reference's 0.4.0 (since fixed), even though it is an ordinary append
 * to a key named `info` (CASC.md §5.7).
 *
 * `#` is a comment only when the next character is not a letter, `@`,
 * or `*` (CASC.md §3.2) -- otherwise it is the version header's or a
 * disabled statement's own `#` token.
 *
 * @internal
 */
final class Lexer
{
    private const HEX_GROUP = '[0-9A-Fa-f]{1,4}+';
    private const DURATION_UNIT = '(?:ns|us|µs|ms|d|h|m|s)';

    /**
     * Literal punctuation, longest first so a prefix never shadows a
     * longer spelling (`${?` before `${` before `$`-less `{`).
     *
     * @var list<string>
     */
    private const PUNCTUATION = [
        '${?', '${', '@{', '%{',
        '{', '}', '[', ']', '(', ')', '=', '#', '@', '!', ':', '?', '|', ',', '.', '*', '~', '+', '-',
    ];

    /** @var list<array{TokenType, string}>|null */
    private static ?array $patterns = null;

    private int $pos = 0;
    private int $line = 1;
    private int $lineStart = 0;

    private function __construct(private readonly string $src, private readonly ?string $file)
    {
    }

    /**
     * @return list<Token> every token in `$src`, ending with an `Eof` token
     * @throws CooperError (stage `lexer`) on input no token matches
     */
    public static function tokenize(string $src, ?string $file = null): array
    {
        return (new self($src, $file))->run();
    }

    /**
     * @return list<Token>
     */
    private function run(): array
    {
        $tokens = [];
        $length = strlen($this->src);
        while (true) {
            $this->skipTrivia();
            if ($this->pos >= $length) {
                $tokens[] = new Token(TokenType::Eof, '', $this->line, $this->column($this->pos), $this->line);

                return $tokens;
            }

            [$type, $text] = $this->match();
            $line = $this->line;
            $column = $this->column($this->pos);
            $this->advance(strlen($text));
            $tokens[] = new Token($type, $text, $line, $column, $this->line);
        }
    }

    /**
     * @return array{TokenType, string}
     */
    private function match(): array
    {
        $best = null;
        $bestLength = 0;

        foreach (self::patterns() as [$type, $pattern]) {
            if (preg_match($pattern, $this->src, $m, 0, $this->pos) === 1 && strlen($m[0]) > $bestLength) {
                $best = [$type, $m[0]];
                $bestLength = strlen($m[0]);
            }
        }

        $resolver = self::scanResolverRef($this->src, $this->pos);
        if ($resolver !== null && strlen($resolver) > $bestLength) {
            $best = [TokenType::ResolverRefRaw, $resolver];
            $bestLength = strlen($resolver);
        }

        foreach (self::PUNCTUATION as $literal) {
            if (strlen($literal) > $bestLength && substr_compare($this->src, $literal, $this->pos, strlen($literal)) === 0) {
                $best = [TokenType::from($literal), $literal];
                $bestLength = strlen($literal);
                break;
            }
        }

        if ($best === null) {
            $char = mb_substr(substr($this->src, $this->pos, 4), 0, 1);
            throw new CooperError(
                'unexpected character ' . json_encode($char, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                CooperError::STAGE_LEXER,
                $this->line,
                $this->column($this->pos),
                $this->file,
            );
        }

        return $best;
    }

    /**
     * Scans a `!{name:payload}` resolver reference with genuinely
     * unbounded brace nesting in its payload (CASC.md §7.4: "everything
     * to the matching closing `}` is the payload verbatim,
     * brace-balanced") -- the Elixir original's `@native` token
     * `Cooper.Native.ResolverRef`. Shared with `InterpParser`.
     *
     * @return string|null the whole `!{...}` span, or null when there is none at `$pos`
     */
    public static function scanResolverRef(string $src, int $pos): ?string
    {
        if (substr_compare($src, '!{', $pos, 2) !== 0) {
            return null;
        }
        $depth = 1;
        $length = strlen($src);
        for ($i = $pos + 2; $i < $length; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $pos, $i - $pos + 1);
                }
            }
        }

        return null;
    }

    private function skipTrivia(): void
    {
        $length = strlen($this->src);
        while ($this->pos < $length) {
            $char = $this->src[$this->pos];
            if ($char === ' ' || $char === "\t" || $char === "\r" || $char === "\n" || $char === "\f" || $char === "\v") {
                $this->advance(1);
                continue;
            }
            if ($char === '#' && !$this->hashIsToken()) {
                $end = strpos($this->src, "\n", $this->pos);
                $this->advance(($end === false ? $length : $end) - $this->pos);
                continue;
            }
            break;
        }
    }

    private function hashIsToken(): bool
    {
        $next = $this->src[$this->pos + 1] ?? '';

        return $next === '@' || $next === '*' || ctype_alpha($next);
    }

    private function advance(int $bytes): void
    {
        $chunk = substr($this->src, $this->pos, $bytes);
        $newlines = substr_count($chunk, "\n");
        if ($newlines > 0) {
            $this->line += $newlines;
            $this->lineStart = $this->pos + (int) strrpos($chunk, "\n") + 1;
        }
        $this->pos += $bytes;
    }

    private function column(int $offset): int
    {
        return mb_strlen(substr($this->src, $this->lineStart, $offset - $this->lineStart)) + 1;
    }

    /**
     * Named tokens in the Elixir grammar's own declaration order -- the
     * tie-break order for two equally long matches.
     *
     * @return list<array{TokenType, string}>
     */
    private static function patterns(): array
    {
        if (self::$patterns !== null) {
            return self::$patterns;
        }

        $hex = self::HEX_GROUP;
        $unit = self::DURATION_UNIT;
        $digits = '\d++';
        $separated = '\d[\d_]*+';

        return self::$patterns = [
            [TokenType::NilKw, '/\Gnil/'],
            [TokenType::TrueKw, '/\Gtrue/'],
            [TokenType::FalseKw, '/\Gfalse/'],
            [TokenType::InfKw, '/\G[+-]?inf(?![A-Za-z0-9_+\-?!])/'],
            [TokenType::Ident, '/\G[A-Za-z][A-Za-z0-9_+\-]*+[?!]?/'],
            [TokenType::Date, '/\G\d{4}-\d{2}-\d{2}/'],
            [TokenType::Time, "/\\G\\d{2}:\\d{2}:\\d{2}(?:\\.{$digits})?+/"],
            [TokenType::DateTime, "/\\G\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.{$digits})?+(?:Z|[+-]\\d{2}:\\d{2})?+/"],
            [TokenType::IPv4, '/\G\d{1,3}+\.\d{1,3}+\.\d{1,3}+\.\d{1,3}+(?:\/\d{1,3}+)?+/'],
            [TokenType::IPv6, "/\\G(?:{$hex}(?::{$hex}){7}|(?:{$hex}(?::{$hex})*+)?+::(?:{$hex}(?::{$hex})*+)?+)(?:\\/\\d{1,3}+)?+/"],
            // `_` separators in every component (CASC.md §6.8), so
            // `1_000ms` is a duration rather than INTEGER(`1_000`) and a
            // stray `ms`.
            [TokenType::Duration, "/\\G{$separated}(?:\\.{$separated})?+{$unit}(?:{$separated}{$unit})*+/"],
            [TokenType::Bytes, "/\\G{$digits}(?:\\.{$digits})?+(?i:PiB|TiB|GiB|MiB|KiB|PB|TB|GB|MB|kB|B)/"],
            [TokenType::Integer, '/\G[+-]?(?:0x[0-9A-Fa-f][0-9A-Fa-f_]*+|0o[0-7][0-7_]*+|0b[01][01_]*+|\d[\d_]*+)/'],
            [TokenType::Float, '/\G[+-]?\d[\d_]*+(?:\.\d[\d_]*+(?:[eE][+-]?\d++)?+|[eE][+-]?\d++)/'],
            [TokenType::TripleString, '/\G"""(?:(?!""").)*+"""/s'],
            [TokenType::DqString, '/\G"(?:\\\\.|[^"])*+"/s'],
            [TokenType::SqString, "/\\G'[^']*+'/"],
        ];
    }
}

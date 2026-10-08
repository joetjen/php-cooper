<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Syntax;

use JOetjen\Cooper\CooperError;
use JOetjen\Cooper\Node\ConfigRef;
use JOetjen\Cooper\Node\EnvRef;
use JOetjen\Cooper\Node\Filter;
use JOetjen\Cooper\Node\InterpText;
use JOetjen\Cooper\Node\ResolverRef;
use JOetjen\Cooper\Node\Suffix;
use JOetjen\Cooper\Node\TaggedRef;
use JOetjen\Cooper\Node\VarRef;
use JOetjen\Cooper\Value\CooperAtom;

/**
 * Splits a double-quoted string's (already escape-processed) content
 * into literal runs and reference nodes (CASC.md §7) -- the Elixir
 * original's `casc_interp.aether` grammar plus `Cooper.InterpActions`.
 *
 * Each reference form is one whole raw span (`@{` through the first
 * `}`, `!Name(` through the first `)`, `!{` through its *matching*
 * `}`), parsed afterwards by hand. That has two consequences this port
 * keeps for parity:
 *
 *  - a reference's name is not validated here (it simply fails to
 *    resolve later), and a built name (`${"..."}`) is not possible
 *    inside a string -- the span would end at the nested `}`;
 *  - a `:default`/`:+alt` value uses a reduced grammar -- nil, booleans,
 *    integers, floats, quoted strings, flat lists, and otherwise an
 *    atom -- rather than the full value grammar a bare reference gets.
 *
 * @internal
 */
final class InterpParser
{
    private const ESCAPES = ['n' => "\n", 'r' => "\r", 't' => "\t", '"' => '"', '\\' => '\\'];

    private function __construct()
    {
    }

    /**
     * Parses `$text` into a plain string (no references at all, the
     * common case) or an `InterpText`.
     *
     * @throws CooperError (stage `parser`) on an unterminated reference, (stage `action`) on a malformed one
     */
    public static function parse(string $text, ?Token $at = null, ?string $file = null): string|InterpText
    {
        $segments = self::segments($text, $at, $file);
        if ($segments === []) {
            return '';
        }
        if (count($segments) === 1 && is_string($segments[0])) {
            return $segments[0];
        }

        return new InterpText($segments);
    }

    /**
     * @return list<mixed>
     */
    private static function segments(string $text, ?Token $at, ?string $file): array
    {
        $segments = [];
        $pos = 0;
        $length = strlen($text);

        while ($pos < $length) {
            $raw = self::matchReference($text, $pos);
            if ($raw !== null) {
                $segments[] = self::reference($raw, $at, $file);
                $pos += strlen($raw);
                continue;
            }

            $chunkEnd = $pos;
            while ($chunkEnd < $length && !self::startsReference($text, $chunkEnd)) {
                $chunkEnd++;
            }
            if ($chunkEnd === $pos) {
                throw new CooperError(
                    'unterminated reference in string: ' . json_encode(substr($text, $pos), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    CooperError::STAGE_PARSER,
                    $at?->line,
                    $at?->column,
                    $file,
                );
            }
            $segments[] = substr($text, $pos, $chunkEnd - $pos);
            $pos = $chunkEnd;
        }

        return $segments;
    }

    /**
     * The longest reference span starting at `$pos`, if any.
     */
    private static function matchReference(string $text, int $pos): ?string
    {
        $candidates = [];
        foreach (['@{', '${', '%{'] as $opener) {
            if (substr_compare($text, $opener, $pos, 2) === 0) {
                $close = strpos($text, '}', $pos + 2);
                if ($close !== false) {
                    $candidates[] = substr($text, $pos, $close - $pos + 1);
                }
            }
        }
        $resolver = Lexer::scanResolverRef($text, $pos);
        if ($resolver !== null) {
            $candidates[] = $resolver;
        }
        if (preg_match('/\G![A-Za-z][A-Za-z0-9_+\-]*+[?!]?\([^)]*+\)/', $text, $m, 0, $pos) === 1) {
            $candidates[] = $m[0];
        }

        $best = null;
        foreach ($candidates as $candidate) {
            if ($best === null || strlen($candidate) > strlen($best)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * Whether a reference *opener* starts at `$pos` -- where a literal
     * text run has to stop, terminated or not.
     */
    private static function startsReference(string $text, int $pos): bool
    {
        foreach (['@{', '${', '%{', '!{'] as $opener) {
            if (substr_compare($text, $opener, $pos, 2) === 0) {
                return true;
            }
        }

        return preg_match('/\G![A-Za-z][A-Za-z0-9_+\-]*+[?!]?\(/', $text, $m, 0, $pos) === 1;
    }

    private static function reference(string $raw, ?Token $at, ?string $file): mixed
    {
        $opener = substr($raw, 0, 2);
        if ($opener === '!{') {
            return self::resolverRef($raw, $at, $file);
        }
        if ($raw[0] === '!') {
            $open = (int) strpos($raw, '(');

            return new TaggedRef(substr($raw, 1, $open - 1), self::defaultValue(substr($raw, $open + 1, -1)));
        }

        [$body, $filters] = self::splitFilters(substr($raw, 2, -1));

        if ($opener === '${') {
            [$nameAndBracket, $suffix] = self::splitSuffix($body);
            if (preg_match('/\A([^\[]+)\[(\d*)\]\z/', $nameAndBracket, $m) === 1) {
                return $m[2] === ''
                    ? new EnvRef($m[1], null, true, $suffix, $filters)
                    : new EnvRef($m[1], (int) $m[2], false, $suffix, $filters);
            }

            return new EnvRef($nameAndBracket, null, false, $suffix, $filters);
        }

        [$nameAndIndex, $suffix] = self::splitSuffix($body);
        $index = null;
        $name = $nameAndIndex;
        if (preg_match('/\A([^\[]+)\[(\d+)\]\z/', $nameAndIndex, $m) === 1) {
            $name = $m[1];
            $index = (int) $m[2];
        }

        return $opener === '@{'
            ? new VarRef($name, $index, $suffix, $filters)
            : new ConfigRef(explode('.', $name), $index, $suffix, $filters);
    }

    /**
     * Splits `!{name:payload}` on its first `:` (CASC.md §7.4). Shared
     * with `Evaluator` for the bare form.
     *
     * @throws CooperError (stage `action`) when there is no `:` at all
     */
    public static function resolverRef(string $raw, ?Token $at = null, ?string $file = null): ResolverRef
    {
        $parts = explode(':', substr($raw, 2, -1), 2);
        if (count($parts) !== 2) {
            throw new CooperError(
                "resolver reference missing ':' separator: " . json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                CooperError::STAGE_ACTION,
                $at?->line,
                $at?->column,
                $file,
            );
        }

        return new ResolverRef($parts[0], $parts[1]);
    }

    /**
     * Splits the filter chain off a reference body. Must run before the
     * suffix split (`| trim_suffix: "://"` has its own `:`), and is
     * quote-aware, since a default may contain a pipe (`${X:"a|b"}`).
     *
     * @return array{0: string, 1: list<Filter>}
     */
    private static function splitFilters(string $inner): array
    {
        $parts = [];
        $current = '';
        $quote = null;
        $length = strlen($inner);
        for ($i = 0; $i < $length; $i++) {
            $char = $inner[$i];
            if ($quote === null && ($char === '"' || $char === "'")) {
                $quote = $char;
            } elseif ($quote !== null && $char === $quote) {
                $quote = null;
            } elseif ($quote === null && $char === '|') {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $parts[] = $current;

        if (count($parts) === 1) {
            return [$parts[0], []];
        }

        $body = rtrim(array_shift($parts));
        $filters = array_map(static function (string $text): Filter {
            $pieces = explode(':', trim($text), 2);
            if (count($pieces) === 1) {
                return new Filter(trim($pieces[0]));
            }

            return new Filter(trim($pieces[0]), self::stripFilterQuotes(trim($pieces[1])));
        }, $parts);

        return [$body, $filters];
    }

    private static function stripFilterQuotes(string $text): string
    {
        if (strlen($text) > 1 && $text[0] === "'" && str_ends_with($text, "'")) {
            return substr($text, 1, -1);
        }

        return self::stripQuotes($text);
    }

    /**
     * The first `:` separates the name from the suffix -- a name never
     * contains one, while a quoted default may.
     *
     * @return array{0: string, 1: Suffix|null}
     */
    private static function splitSuffix(string $inner): array
    {
        $parts = explode(':', $inner, 2);
        if (count($parts) === 1) {
            return [$inner, null];
        }
        $suffix = $parts[1];
        if (str_starts_with($suffix, '?')) {
            return [$parts[0], new Suffix(Suffix::REQUIRED, self::stripQuotes(trim(substr($suffix, 1))))];
        }
        if (str_starts_with($suffix, '+')) {
            return [$parts[0], new Suffix(Suffix::SUBSTITUTE, self::defaultValue(substr($suffix, 1)))];
        }

        return [$parts[0], new Suffix(Suffix::DEFAULT, self::defaultValue($suffix))];
    }

    private static function defaultValue(string $text): mixed
    {
        $text = trim($text);

        return match (true) {
            $text === 'nil' => null,
            $text === 'true' => true,
            $text === 'false' => false,
            preg_match('/\A[+-]?\d+\z/', $text) === 1 => Literals::integer($text),
            preg_match('/\A[+-]?\d+\.\d+\z/', $text) === 1 => (float) $text,
            str_starts_with($text, '"') => self::stripQuotes($text),
            strlen($text) > 1 && str_starts_with($text, "'") && str_ends_with($text, "'") => substr($text, 1, -1),
            str_starts_with($text, '[') && str_ends_with($text, ']') => self::defaultList(substr($text, 1, -1)),
            default => CooperAtom::of($text),
        };
    }

    /**
     * @return list<mixed>
     */
    private static function defaultList(string $inner): array
    {
        if ($inner === '') {
            return [];
        }
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|\'[^\']*\'|[^,]+/', $inner, $matches);
        $items = array_values(array_filter(
            array_map('trim', $matches[0]),
            static fn (string $item): bool => $item !== '',
        ));

        return array_map(self::defaultValue(...), $items);
    }

    private static function stripQuotes(string $text): string
    {
        if (preg_match('/\A"((?:[^"\\\\]|\\\\.)*)"\z/s', $text, $m) !== 1) {
            return $text;
        }

        return (string) preg_replace_callback(
            '/\\\\(.)/s',
            static fn (array $e): string => self::ESCAPES[$e[1]] ?? $e[1],
            $m[1],
        );
    }
}

<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Pipeline;

use JOetjen\Cooper\CooperError;
use JOetjen\Cooper\Node\Block;
use JOetjen\Cooper\Node\Clear;
use JOetjen\Cooper\Node\ConfigRef;
use JOetjen\Cooper\Node\EnvRef;
use JOetjen\Cooper\Node\Filter;
use JOetjen\Cooper\Node\InterpText;
use JOetjen\Cooper\Node\Op;
use JOetjen\Cooper\Node\Suffix;
use JOetjen\Cooper\Node\TaggedRef;
use JOetjen\Cooper\Node\VarDecl;
use JOetjen\Cooper\Node\VarRef;
use JOetjen\Cooper\Value\CooperSecret;
use JOetjen\Cooper\Value\CooperTuple;

/**
 * Expands a `for` statement (CASC.md §5.5) into the entries it
 * generates -- the Elixir original's `Cooper.Loop`.
 *
 * Expansion runs before merge and before general reference resolution,
 * yet needs each element binding's actual list to know how many
 * iterations there are. So an iterable is resolved eagerly, but only
 * against the current file's own variables declared so far (or a list
 * literal); a `%{...}` reference, which only exists after merge, is
 * refused outright.
 *
 * Each iteration substitutes its bindings (`@idx`, `@domain`, ...) --
 * and only those -- into the destination path and the body's keys and
 * values; every other reference is left for `Resolver`. Iteration over
 * several element bindings is parallel (zipped), never a cartesian
 * product, and their lists must be the same length.
 *
 * Places the reference (as of its 0.4.0) did otherwise, since fixed there
 * and followed here:
 *
 *  - a binding also substitutes into a `%{...}` path segment, a
 *    reference's default, and a filter argument (CASC.md §7.2's own
 *    `%{tokens."supervisor-@{id}"}` example inside a loop), not only
 *    into plain values and keys;
 *  - a key that still interpolates something other than a binding is
 *    left for `Merge` to resolve, rather than being an error;
 *  - a `~key { ... }` inside the body clears under the generated
 *    destination, not at the top-level `key`;
 *  - a reference to a binding keeps its index, suffix, and filters
 *    (`@{x | upcase}`), the value bound onto it (`VarRef::$bound`);
 *  - a key segment built from a binding follows the interpolated-key
 *    rules (non-empty, no `.`);
 *  - an iterable's variable is looked up with private shadowing, and
 *    must be named, not built.
 *
 * @internal
 */
final class Loop
{
    private function __construct()
    {
    }

    /**
     * @param list<array{0: 'index', 1: string}|array{0: 'element', 1: string, 2: mixed}> $bindings
     * @param list<string|InterpText>|null $template the `from` template path, if any
     * @param list<string|InterpText> $dest the destination path
     * @param list<Op|VarDecl|Clear> $body the loop body's entries
     * @param array<string, array{0: mixed, 1: bool}> $vars the file's variables declared so far
     * @return list<Op|VarDecl|Clear>
     * @throws CooperError (stage `loop`) on invalid bindings or iterables
     */
    public static function expand(array $bindings, ?array $template, array $dest, bool $destSecret, array $body, array $vars): array
    {
        $elements = array_values(array_filter($bindings, static fn (array $b): bool => $b[0] === 'element'));
        $indexes = array_values(array_filter($bindings, static fn (array $b): bool => $b[0] === 'index'));
        if ($elements === []) {
            throw self::error('a `for` loop needs at least one element binding (`@name in ...`) -- index-only is not allowed');
        }
        if (count($indexes) > 1) {
            throw self::error('a `for` loop allows at most one index binding (`@name`)');
        }

        $lists = [];
        foreach ($elements as $binding) {
            /** @var array{0: 'element', 1: string, 2: mixed} $binding */
            $lists[] = [$binding[1], self::iterable($binding[2], $vars)];
        }

        $lengths = array_unique(array_map(static fn (array $l): int => count($l[1]), $lists));
        if (count($lengths) > 1) {
            $detail = implode(', ', array_map(static fn (array $l): string => "@{{$l[0]}} (" . count($l[1]) . ')', $lists));
            throw self::error("for loop's bound lists have mismatched lengths: {$detail}");
        }
        $length = (int) reset($lengths);

        $entries = [];
        for ($i = 0; $i < $length; $i++) {
            $overlay = [];
            foreach ($lists as [$name, $list]) {
                $overlay[$name] = $list[$i];
            }
            foreach ($indexes as [, $name]) {
                $overlay[$name] = $i;
            }
            array_push($entries, ...self::iteration($overlay, $template, $dest, $destSecret, $body));
        }

        return $entries;
    }

    /**
     * @param array<string, array{0: mixed, 1: bool}> $vars
     * @return list<mixed>
     */
    private static function iterable(mixed $expr, array $vars): array
    {
        if (is_array($expr)) {
            return array_values($expr);
        }
        if ($expr instanceof VarRef && !is_string($expr->name)) {
            throw self::error('a loop iterable must name its variable directly, not build the name');
        }
        if ($expr instanceof VarRef) {
            [$found, $value] = Scope::lookup($vars, $expr->name);
            if (!$found) {
                throw self::error("loop iterable references undefined variable \"@{{$expr->name}}\"");
            }
            if (!is_array($value)) {
                throw self::error("loop iterable \"@{{$expr->name}}\" is not a list");
            }

            return array_values($value);
        }
        if ($expr instanceof ConfigRef) {
            throw self::error(
                'a `%{...}` config reference can\'t be used as a loop iterable -- it only resolves after the full tree is merged, which happens after loop expansion'
            );
        }

        throw self::error('loop iterable must be a list literal or a variable reference to one');
    }

    /**
     * @param array<string, mixed> $overlay
     * @param list<string|InterpText>|null $template
     * @param list<string|InterpText> $dest
     * @param list<Op|VarDecl|Clear> $body
     * @return list<Op|VarDecl|Clear>
     */
    private static function iteration(array $overlay, ?array $template, array $dest, bool $destSecret, array $body): array
    {
        $resolvedDest = self::path($dest, $overlay);

        $entries = [];
        if ($template !== null) {
            $entries[] = new Op($resolvedDest, Op::MERGE, new ConfigRef($template), $destSecret);
        }
        foreach ($body as $entry) {
            $entries[] = match (true) {
                $entry instanceof Op => new Op(
                    [...$resolvedDest, ...self::path($entry->path, $overlay)],
                    $entry->sigil,
                    self::substitute($entry->value, $overlay),
                    $destSecret || $entry->secret,
                ),
                $entry instanceof Clear => new Clear([...$resolvedDest, ...self::path($entry->path, $overlay)]),
                default => $entry,
            };
        }

        return $entries;
    }

    /**
     * @param list<string|InterpText> $path
     * @param array<string, mixed> $overlay
     * @return list<string|InterpText>
     */
    private static function path(array $path, array $overlay): array
    {
        return array_map(static function (string|InterpText $segment) use ($overlay): string|InterpText {
            $substituted = self::substitute($segment, $overlay);
            if ($segment instanceof InterpText && is_string($substituted)) {
                return self::checkKey($substituted);
            }

            return is_string($substituted) || $substituted instanceof InterpText
                ? $substituted
                : Display::toString($substituted);
        }, $path);
    }

    /**
     * An interpolated segment the bindings turned into text follows the
     * rules every interpolated key does (CASC.md §4.2): non-empty, and no
     * `.`, which would split it. Taking the text as it came, as the
     * reference once did, let `out."@{x}"` over `"a.b"` build a key the
     * same text outside a loop is refused for. A segment still holding
     * another reference is checked by `Merge` once it resolves.
     *
     * @throws CooperError (stage `resolve`, as for any interpolated key)
     */
    private static function checkKey(string $key): string
    {
        if ($key === '') {
            throw new CooperError('interpolated key resolved to an empty string', CooperError::STAGE_RESOLVE);
        }
        if (str_contains($key, '.')) {
            throw new CooperError(
                'interpolated key resolved to ' . Display::inspect($key) . ', which would split into more than one path segment',
                CooperError::STAGE_RESOLVE,
            );
        }

        return $key;
    }

    /**
     * Replaces every reference to a loop binding inside `$value`.
     *
     * @param array<string, mixed> $overlay
     */
    private static function substitute(mixed $value, array $overlay): mixed
    {
        return match (true) {
            $value instanceof VarRef => self::varRef($value, $overlay),
            $value instanceof EnvRef => new EnvRef(
                self::substitute($value->name, $overlay),
                $value->index,
                $value->list,
                self::suffix($value->suffix, $overlay),
                self::filters($value->filters, $overlay),
            ),
            $value instanceof ConfigRef => new ConfigRef(
                self::path($value->path, $overlay),
                $value->index,
                self::suffix($value->suffix, $overlay),
                self::filters($value->filters, $overlay),
            ),
            $value instanceof InterpText => self::text($value, $overlay),
            $value instanceof TaggedRef => $value->withArg(self::substitute($value->arg, $overlay)),
            $value instanceof CooperTuple => new CooperTuple(array_map(static fn (mixed $v): mixed => self::substitute($v, $overlay), $value->items)),
            // A block written as a list element (CASC.md §6.10): the
            // bindings reach its keys -- under the same key rules as any
            // other -- and its values.
            $value instanceof Block => $value->map(static fn (Op|VarDecl|Clear $entry): Op|VarDecl|Clear => match (true) {
                $entry instanceof Op => new Op(self::path($entry->path, $overlay), $entry->sigil, self::substitute($entry->value, $overlay), $entry->secret),
                $entry instanceof Clear => new Clear(self::path($entry->path, $overlay)),
                default => $entry,
            }),
            is_array($value) => array_map(static fn (mixed $v): mixed => self::substitute($v, $overlay), $value),
            default => $value,
        };
    }

    /**
     * A bare `@{x}` becomes the iteration's value. One carrying an index,
     * a suffix, or filters (`@{x | upcase}`, `@{x[0]}`, `@{x:default}`)
     * keeps them: the value is bound onto the reference, which `Resolver`
     * then resolves normally. Replacing it with the bare value, as the
     * reference once did, silently dropped the filter, index, or suffix.
     *
     * @param array<string, mixed> $overlay
     */
    private static function varRef(VarRef $ref, array $overlay): mixed
    {
        $bound = $ref->bound === null && is_string($ref->name) && array_key_exists($ref->name, $overlay);
        if ($bound && $ref->index === null && $ref->suffix === null && $ref->filters === []) {
            return $overlay[$ref->name];
        }

        return new VarRef(
            self::substitute($ref->name, $overlay),
            $ref->index,
            self::suffix($ref->suffix, $overlay),
            self::filters($ref->filters, $overlay),
            $ref->scope,
            $bound ? [$overlay[(string) $ref->name]] : $ref->bound,
        );
    }

    /**
     * @param array<string, mixed> $overlay
     */
    private static function text(InterpText $text, array $overlay): string|InterpText
    {
        $segments = [];
        foreach ($text->segments as $segment) {
            $substituted = self::substitute($segment, $overlay);
            // A plain value joins the surrounding text now; a secret or a
            // still-unresolved reference stays a segment of its own for
            // `Resolver` (which redacts a secret portion in place).
            $segments[] = !$substituted instanceof CooperSecret && Display::displayable($substituted)
                ? Display::toString($substituted)
                : $substituted;
        }

        $allText = array_reduce($segments, static fn (bool $carry, mixed $s): bool => $carry && is_string($s), true);
        if ($allText) {
            return implode('', $segments);
        }

        return new InterpText($segments);
    }

    /**
     * @param array<string, mixed> $overlay
     */
    private static function suffix(?Suffix $suffix, array $overlay): ?Suffix
    {
        return $suffix?->withValue(self::substitute($suffix->value, $overlay));
    }

    /**
     * @param list<Filter> $filters
     * @param array<string, mixed> $overlay
     * @return list<Filter>
     */
    private static function filters(array $filters, array $overlay): array
    {
        return array_map(static function (Filter $filter) use ($overlay): Filter {
            if (!$filter->argument instanceof InterpText) {
                return $filter;
            }
            $argument = self::text($filter->argument, $overlay);

            return new Filter($filter->name, $argument);
        }, $filters);
    }

    private static function error(string $message): CooperError
    {
        return new CooperError($message, CooperError::STAGE_LOOP);
    }
}

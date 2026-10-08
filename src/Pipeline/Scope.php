<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Pipeline;

use JOetjen\Cooper\Node\Block;
use JOetjen\Cooper\Node\Clear;
use JOetjen\Cooper\Node\ConfigRef;
use JOetjen\Cooper\Node\EnvRef;
use JOetjen\Cooper\Node\Filter;
use JOetjen\Cooper\Node\InterpText;
use JOetjen\Cooper\Node\MapNode;
use JOetjen\Cooper\Node\Op;
use JOetjen\Cooper\Node\Suffix;
use JOetjen\Cooper\Node\TaggedRef;
use JOetjen\Cooper\Node\VarDecl;
use JOetjen\Cooper\Node\VarRef;
use JOetjen\Cooper\Value\CooperTuple;

/**
 * Per-file variable visibility (CASC.md §5.2).
 *
 * A load flattens an arbitrarily deep import tree into one entry list,
 * resolved in one pass -- but visibility is a property of the *file a
 * reference was written in*: a public `@name` is shared by the whole
 * load, a private `@*name` only by its own file. Flattening destroys
 * that file boundary, so it is recorded first: `stamp()` marks every
 * `VarRef` in a freshly evaluated file with that file's scope (its
 * absolute path, or `"(source)"` for a string loaded without one), and
 * `Resolver` consults that scope's private declarations before the
 * shared public ones.
 *
 * Stamping only fills a reference that has no scope yet, so entries
 * spliced in from an already-stamped import keep the scope of the file
 * that really wrote them.
 *
 * @internal
 */
final class Scope
{
    public const ANONYMOUS = '(source)';

    private function __construct()
    {
    }

    /**
     * The scope identity of `$file` -- its expanded path, or the
     * anonymous scope for a source loaded without one. A `scheme://`
     * label is used verbatim.
     */
    public static function id(?string $file, bool $verbatim = false): string
    {
        if ($file === null) {
            return self::ANONYMOUS;
        }

        return $verbatim ? $file : Paths::expand($file);
    }

    /**
     * Stamps every not-yet-stamped `VarRef` reachable from `$value`.
     */
    public static function stamp(mixed $value, string $scope): mixed
    {
        return match (true) {
            $value instanceof VarRef => $value->scope !== null ? $value : new VarRef(
                self::stamp($value->name, $scope),
                $value->index,
                self::stampSuffix($value->suffix, $scope),
                self::stampFilters($value->filters, $scope),
                $scope,
                $value->bound === null ? null : [self::stamp($value->bound[0], $scope)],
            ),
            $value instanceof InterpText => new InterpText(self::stampList($value->segments, $scope)),
            $value instanceof Op => new Op(self::stampList($value->path, $scope), $value->sigil, self::stamp($value->value, $scope), $value->secret),
            $value instanceof VarDecl => new VarDecl($value->name, self::stamp($value->value, $scope), $value->public),
            $value instanceof Clear => new Clear(self::stampList($value->path, $scope)),
            $value instanceof TaggedRef => $value->withArg(self::stamp($value->arg, $scope)),
            $value instanceof EnvRef => new EnvRef(
                self::stamp($value->name, $scope),
                $value->index,
                $value->list,
                self::stampSuffix($value->suffix, $scope),
                self::stampFilters($value->filters, $scope),
            ),
            $value instanceof ConfigRef => new ConfigRef(
                self::stampList($value->path, $scope),
                $value->index,
                self::stampSuffix($value->suffix, $scope),
                self::stampFilters($value->filters, $scope),
            ),
            $value instanceof CooperTuple => new CooperTuple(self::stampList($value->items, $scope)),
            $value instanceof MapNode => $value->map(static fn (mixed $item): mixed => self::stamp($item, $scope)),
            // A block written as a list element (CASC.md §6.10) keeps its
            // entries until it is resolved, so its keys and values are
            // stamped where they sit.
            $value instanceof Block => $value->map(static fn (Op|VarDecl|Clear $entry): Op|VarDecl|Clear => self::stamp($entry, $scope)),
            is_array($value) => array_map(static fn (mixed $item): mixed => self::stamp($item, $scope), $value),
            default => $value,
        };
    }

    /**
     * The key a private declaration `@*name` is kept under in a file's
     * `vars` -- its own, rather than `name`, so it cannot overwrite a
     * public variable of the same name an import brought in. Inside its
     * file a private declaration *shadows* a public one (CASC.md §5.2);
     * it does not remove it, and overwriting it, as this once did (and the
     * reference did), left the imported file's own `@{name}` undefined.
     * `*` never starts a variable name, so the key cannot collide.
     */
    public static function privateKey(string $name): string
    {
        return "*{$name}";
    }

    /**
     * Splits a file's `vars` (name => [value, public?], a private
     * declaration keyed by `privateKey()`) into the public environment
     * that crosses file boundaries and the private one that does not,
     * both keyed by plain name.
     *
     * @param array<string, array{0: mixed, 1: bool}> $vars
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function split(array $vars): array
    {
        $public = [];
        $private = [];
        foreach ($vars as $key => [$value, $isPublic]) {
            if ($isPublic) {
                $public[$key] = $value;
            } else {
                $private[substr((string) $key, 1)] = $value;
            }
        }

        return [$public, $private];
    }

    /**
     * The value `$name` has in a file's `vars` so far -- its private
     * declaration if it has one, otherwise the public one (CASC.md
     * §5.2's shadowing, as `Loop` needs it for an iterable).
     *
     * @param array<string, array{0: mixed, 1: bool}> $vars
     * @return array{0: bool, 1: mixed} [found, value]
     */
    public static function lookup(array $vars, string $name): array
    {
        $entry = $vars[self::privateKey($name)] ?? $vars[$name] ?? null;

        return $entry === null ? [false, null] : [true, $entry[0]];
    }

    /**
     * @template T
     * @param list<T> $items
     * @return list<T>
     */
    private static function stampList(array $items, string $scope): array
    {
        /** @var list<T> */
        return array_map(static fn (mixed $item): mixed => self::stamp($item, $scope), $items);
    }

    private static function stampSuffix(?Suffix $suffix, string $scope): ?Suffix
    {
        return $suffix?->withValue(self::stamp($suffix->value, $scope));
    }

    /**
     * @param list<Filter> $filters
     * @return list<Filter>
     */
    private static function stampFilters(array $filters, string $scope): array
    {
        return array_map(
            static fn (Filter $filter): Filter => $filter->argument instanceof InterpText
                ? new Filter($filter->name, new InterpText(self::stampList($filter->argument->segments, $scope)))
                : $filter,
            $filters,
        );
    }
}

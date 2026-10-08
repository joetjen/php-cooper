<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Pipeline;

use JOetjen\Cooper\CooperError;
use JOetjen\Cooper\Node\Absent;
use JOetjen\Cooper\Node\Block;
use JOetjen\Cooper\Node\Clear;
use JOetjen\Cooper\Node\ConfigRef;
use JOetjen\Cooper\Node\EnvRef;
use JOetjen\Cooper\Node\InterpText;
use JOetjen\Cooper\Node\Layered;
use JOetjen\Cooper\Node\ListEdit;
use JOetjen\Cooper\Node\MapNode;
use JOetjen\Cooper\Node\Op;
use JOetjen\Cooper\Node\ResolverRef;
use JOetjen\Cooper\Node\Suffix;
use JOetjen\Cooper\Node\TaggedRef;
use JOetjen\Cooper\Node\VarDecl;
use JOetjen\Cooper\Node\VarRef;
use JOetjen\Cooper\Value\CooperSecret;
use JOetjen\Cooper\Value\CooperTuple;
use JOetjen\Cooper\Values;

/**
 * Folds the flat entry list into the (almost) final tree -- CASC.md §8
 * and §5.7, the Elixir original's `Cooper.Merge`. "Almost": leaves may
 * still be unresolved references or `Layered` values for `Resolver`.
 *
 * Each `Op`'s sigil decides how it combines with what is already at its
 * path:
 *
 *  - merge / replace -- set the leaf; deep-merge of maps (§8.1) emerges
 *    from folding ops at different sub-paths. A `~` block's whole
 *    subtree is dropped first by the `Clear` entry `Evaluator` emits.
 *  - append / remove (`+key`/`-key`, §8.4) -- list-only: concatenate, or
 *    drop elements strictly equal to any given one; a list operand
 *    stands for its elements, anything else (`nil` included) is one
 *    element. Against a tuple (§8.3) that is an error, as is `~`
 *    clearing a path that holds a tuple. An edit whose list or operand
 *    is still a reference, or whose list a `for ... from` template
 *    supplies, waits for `Resolver` as a `ListEdit`.
 *  - delete (`-key.path`) -- remove the path, any type; under a `for
 *    ... from` template, an `Absent` marker.
 *
 * Every path whose *final* write was secret (§4.3; a later op's flag
 * wins) is wrapped in `CooperSecret` here, before resolution, so the
 * secrecy travels with the value through every later `%{...}` copy or
 * `for ... from` template.
 *
 * An interpolated key segment (`"region-@{name}" = ...`, CASC.md §4.2)
 * is resolved to a string before folding, through `$resolveKey`. The
 * reference once never resolved one outside a `for` loop, leaving the
 * unresolved reference itself as the map key.
 *
 * @internal
 */
final class Merge
{
    /** @var array<string, list<string>> secret path key => path */
    private array $secrets = [];

    /**
     * The empty maps `Clear` left in place, by object id -- held here so
     * an id cannot be reused by a later map while assembly runs.
     *
     * @var array<int, MapNode>
     */
    private array $placeholders = [];

    /**
     * @param (callable(InterpText): string)|null $resolveKey
     */
    private function __construct(private readonly mixed $resolveKey)
    {
    }

    /**
     * @param list<Op|VarDecl|Clear> $entries
     * @param (callable(InterpText): string)|null $resolveKey resolves an interpolated key segment
     * @throws CooperError (stage `merge`) on a sigil misused against a tuple or non-list
     */
    public static function assemble(array $entries, ?callable $resolveKey = null): MapNode
    {
        $merge = new self($resolveKey);
        $tree = new MapNode();
        foreach ($entries as $entry) {
            $tree = $merge->apply($entry, $tree);
        }

        $wrapped = $merge->wrapSecrets($merge->prune($tree), []);
        assert($wrapped instanceof MapNode);

        return $wrapped;
    }

    private function apply(Op|VarDecl|Clear $entry, MapNode $tree): MapNode
    {
        if ($entry instanceof VarDecl) {
            return $tree;
        }

        $path = $this->resolvePath($entry->path);

        if ($entry instanceof Clear) {
            if (!self::get($tree, $path, $existing)) {
                return $tree;
            }
            if ($existing instanceof CooperTuple) {
                throw self::tupleError($path, '~');
            }
            // An empty map in place, rather than a delete, keeps the
            // replaced key where it was in the (ordered) result; one that
            // nothing writes into again is pruned at the end, matching a
            // delete exactly.
            $placeholder = new MapNode();
            $this->placeholders[spl_object_id($placeholder)] = $placeholder;

            return self::put($tree, $path, $placeholder);
        }

        // An empty block (`w {}`, CASC.md §5.4) is an empty map, and blocks
        // deep-merge: written over a map already there it adds nothing, so
        // the map stays as it is. Only where there is none -- or something
        // that is not a map -- does it become one.
        if ($entry->sigil === Op::MERGE && $entry->value instanceof MapNode && $entry->value->isEmpty()) {
            $this->track($path, $entry->secret);

            return self::get($tree, $path, $existing) && $existing instanceof MapNode ? $tree : self::put($tree, $path, new MapNode());
        }

        switch ($entry->sigil) {
            case Op::MERGE:
            case Op::REPLACE:
                $this->track($path, $entry->secret);

                return self::put($tree, $path, $entry->value);

            case Op::APPEND:
            case Op::REMOVE:
                return $this->listEdit($entry, $path, $tree);

            default: // Op::DELETE
                $length = count($path);
                foreach ($this->secrets as $key => $secretPath) {
                    if (array_slice($secretPath, 0, $length) === $path) {
                        unset($this->secrets[$key]);
                    }
                }

                // Under a `for ... from` base the key may come from the
                // template, so its absence is recorded rather than
                // deleting nothing.
                return self::lazyAt($tree, $path) === null ? self::delete($tree, $path) : self::put($tree, $path, new Absent());
        }
    }

    /**
     * `+key`/`-key` (CASC.md §8.4). Three cases:
     *
     *  - the path runs into a `for ... from` template's lazy base before
     *    it ends: the list is the template's, so the edit waits for it as
     *    a `ListEdit` reading the template's own value;
     *  - the list or the operand is still a reference (`+tags =
     *    @{more}`, declared later): the edit waits for both, as a
     *    `ListEdit`;
     *  - both are known: applied here, element-wise.
     *
     * @param list<string> $path
     */
    private function listEdit(Op $op, array $path, MapNode $tree): MapNode
    {
        $mark = $op->sigil === Op::APPEND ? '+' : '-';
        $lazy = self::lazyAt($tree, $path);
        if ($lazy instanceof ConfigRef) {
            $this->track($path, $op->secret);

            return self::put($tree, $path, new ListEdit($lazy, $op->sigil, $op->value, $path));
        }

        $found = $lazy !== null ? true : self::get($tree, $path, $base);
        if ($lazy !== null) {
            $base = $lazy[0];
        }
        if (!$found) {
            if ($op->sigil === Op::REMOVE) {
                return $tree;
            }
            $this->track($path, $op->secret);

            return self::put($tree, $path, $op->value);
        }
        if ($base instanceof CooperTuple) {
            throw self::tupleError($path, $mark);
        }

        $deferred = (self::unresolved($base) && !is_array($base))
            || self::unresolved($op->value)
            || ($op->sigil === Op::REMOVE && (self::containsUnresolved($base) || self::containsUnresolved($op->value)));
        if ($deferred) {
            $this->track($path, $op->secret);

            return self::put($tree, $path, new ListEdit($base, $op->sigil, $op->value, $path));
        }
        if (!is_array($base)) {
            throw self::notAListError($path, $mark);
        }

        $this->track($path, $op->secret);

        return self::put($tree, $path, self::edit($op->sigil, $base, $op->value));
    }

    /**
     * Applies a `+`/`-` edit to a known list -- here, or in `Resolver`
     * once a deferred one's sides resolve. Removal is strict
     * (`Values::equals()`): `1` and `1.0` are different elements.
     *
     * @internal
     * @param array<mixed> $base
     * @return list<mixed>
     */
    public static function edit(string $sigil, array $base, mixed $operand): array
    {
        $items = self::items($operand);
        if ($sigil === Op::APPEND) {
            return [...array_values($base), ...$items];
        }

        return array_values(array_filter(
            $base,
            static function (mixed $item) use ($items): bool {
                foreach ($items as $candidate) {
                    if (Values::equals($item, $candidate)) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }

    /**
     * The elements a `+`/`-` operand stands for: a list is its own
     * elements, anything else -- `nil` included -- is one element
     * (CASC.md §8.4). Reading `nil` as no elements, as this once did
     * (after the reference's `List.wrap/1`), made `+a = nil` append
     * nothing where every other scalar is appended as itself.
     *
     * @return list<mixed>
     */
    private static function items(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [$value];
    }

    /**
     * Whether `$value` is a node only `Resolver` can turn into a value.
     */
    private static function unresolved(mixed $value): bool
    {
        return $value instanceof VarRef
            || $value instanceof EnvRef
            || $value instanceof ConfigRef
            || $value instanceof ResolverRef
            || $value instanceof TaggedRef
            || $value instanceof InterpText
            || $value instanceof Layered
            || $value instanceof ListEdit
            || $value instanceof Block;
    }

    /**
     * `unresolved()`, anywhere inside a list, tuple, or map -- what a
     * removal must wait for, since it compares elements. An element block
     * (CASC.md §6.10) is unresolved, so `-l = [{ a = 1 }]` waits until it
     * is a map that can be compared.
     */
    private static function containsUnresolved(mixed $value): bool
    {
        $items = match (true) {
            is_array($value) => $value,
            $value instanceof CooperTuple => $value->items,
            $value instanceof MapNode => $value->entries(),
            default => null,
        };
        if ($items === null) {
            return self::unresolved($value);
        }
        foreach ($items as $item) {
            if (self::containsUnresolved($item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Where `$path` runs into a lazy `for ... from` base before reaching
     * its end: the `ConfigRef` that will supply the value once the
     * template resolves (defaulting to `Absent` where the template lacks
     * the key) -- or `[value]` when an override already written under
     * that base is at the path. `null` when the path never touches a
     * lazy base.
     *
     * @param list<string> $path
     * @return ConfigRef|array{0: mixed}|null
     */
    private static function lazyAt(mixed $node, array $path): ConfigRef|array|null
    {
        if ($path === []) {
            return null;
        }
        if ($node instanceof ConfigRef) {
            return new ConfigRef([...$node->path, ...$path], suffix: new Suffix(Suffix::DEFAULT, new Absent()));
        }
        if ($node instanceof Layered) {
            if (self::get($node->overrides, $path, $found)) {
                return [$found];
            }

            return new ConfigRef([...$node->base->path, ...$path], suffix: new Suffix(Suffix::DEFAULT, new Absent()));
        }
        if ($node instanceof MapNode && $node->has($path[0])) {
            return self::lazyAt($node->get($path[0]), array_slice($path, 1));
        }

        return null;
    }

    /**
     * @param list<string|InterpText> $path
     * @return list<string>
     */
    private function resolvePath(array $path): array
    {
        return array_map(function (string|InterpText $segment): string {
            if (is_string($segment)) {
                return $segment;
            }
            if ($this->resolveKey === null) {
                throw new CooperError('an interpolated key needs a variable environment to resolve against', CooperError::STAGE_MERGE);
            }

            return ($this->resolveKey)($segment);
        }, $path);
    }

    /**
     * @param list<string> $path
     */
    private function track(array $path, bool $secret): void
    {
        $key = implode("\0", $path);
        if ($secret) {
            $this->secrets[$key] = $path;
        } else {
            unset($this->secrets[$key]);
        }
    }

    /**
     * Removes every `Clear` placeholder nothing was written into.
     */
    private function prune(mixed $value): mixed
    {
        if ($value instanceof Layered) {
            $overrides = $this->prune($value->overrides);
            assert($overrides instanceof MapNode);

            return new Layered($value->base, $overrides);
        }
        if (!$value instanceof MapNode || $this->placeholders === []) {
            return $value;
        }
        foreach ($value->entries() as $key => $item) {
            $key = (string) $key;
            if ($item instanceof MapNode && ($this->placeholders[spl_object_id($item)] ?? null) === $item) {
                $value = $value->without($key);
            } else {
                $value = $value->with($key, $this->prune($item));
            }
        }

        return $value;
    }

    /**
     * Wraps every secret path's value, descending only through maps: a
     * secret path is always a key chain, and once a path is secret its
     * whole value -- list, tuple, nested map -- is wrapped as one unit.
     *
     * @param list<string> $path
     */
    private function wrapSecrets(mixed $value, array $path): mixed
    {
        if ($path !== [] && isset($this->secrets[implode("\0", $path)])) {
            return new CooperSecret($value);
        }
        if ($value instanceof MapNode) {
            return $value->map(fn (mixed $item, string $key): mixed => $this->wrapSecrets($item, [...$path, $key]));
        }

        return $value;
    }

    /**
     * Looks `$path` up through maps only.
     *
     * @param list<string> $path
     */
    private static function get(mixed $tree, array $path, mixed &$found): bool
    {
        foreach ($path as $segment) {
            if (!$tree instanceof MapNode || !$tree->has($segment)) {
                return false;
            }
            $tree = $tree->get($segment);
        }
        $found = $tree;

        return true;
    }

    /**
     * @param list<string> $path non-empty
     */
    private static function put(MapNode $tree, array $path, mixed $value): MapNode
    {
        $segment = $path[0];
        if (count($path) === 1) {
            return $tree->with($segment, $value);
        }

        return $tree->with($segment, self::descend($tree->has($segment) ? $tree->get($segment) : null, array_slice($path, 1), $value));
    }

    /**
     * What sits at a segment on the way to writing something deeper: a
     * map keeps building; a lazy `ConfigRef` base (or a `Layered` that
     * already has one) is wrapped rather than clobbered, since
     * `Resolver` still needs that base; anything else is replaced.
     *
     * @param list<string> $rest non-empty
     */
    private static function descend(mixed $existing, array $rest, mixed $value): MapNode|Layered
    {
        return match (true) {
            $existing instanceof ConfigRef => new Layered($existing, self::put(new MapNode(), $rest, $value)),
            $existing instanceof Layered => new Layered($existing->base, self::put($existing->overrides, $rest, $value)),
            $existing instanceof MapNode => self::put($existing, $rest, $value),
            default => self::put(new MapNode(), $rest, $value),
        };
    }

    /**
     * @param list<string> $path
     */
    private static function delete(MapNode $tree, array $path): MapNode
    {
        if ($path === []) {
            return new MapNode();
        }
        $segment = $path[0];
        if (count($path) === 1) {
            return $tree->without($segment);
        }
        $child = $tree->get($segment);
        if (!$tree->has($segment) || !$child instanceof MapNode) {
            return $tree;
        }

        return $tree->with($segment, self::delete($child, array_slice($path, 1)));
    }

    /**
     * @param list<string> $path
     */
    private static function tupleError(array $path, string $sigil): CooperError
    {
        return new CooperError(
            "\"{$sigil}" . implode('.', $path) . '" targets a tuple -- tuples are never merged, only replaced wholesale (CASC.md §8.3)',
            CooperError::STAGE_MERGE,
        );
    }

    /**
     * @param list<string> $path
     */
    private static function notAListError(array $path, string $sigil): CooperError
    {
        return new CooperError(
            "\"{$sigil}" . implode('.', $path) . '" needs a list at that path, found something else',
            CooperError::STAGE_MERGE,
        );
    }
}

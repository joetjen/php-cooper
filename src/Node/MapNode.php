<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Node;

/**
 * A CASC block/map (CASC.md §5.4) while it is still moving through the
 * pipeline. Immutable -- every change returns a new node.
 *
 * Why not a bare PHP `array`, which is what the final result uses: a
 * map whose keys happen to be `"0"`, `"1"`, ... is indistinguishable
 * from a list once PHP coerces those keys to integers, and an empty map
 * is indistinguishable from an empty list. Merge semantics differ
 * between the two (maps deep-merge, lists replace and accept `+`/`-`),
 * so inside the pipeline a map has its own type and only becomes an
 * `array` in `Native::convert()` at the very end.
 *
 * Keys are always strings; PHP's own integer-key coercion is undone on
 * the way out of `entries()`/`keys()`.
 *
 * @internal unresolved pipeline data
 */
final class MapNode
{
    /**
     * @param array<array-key, mixed> $items
     */
    public function __construct(private readonly array $items = [])
    {
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->items);
    }

    public function get(string $key): mixed
    {
        return $this->items[$key] ?? null;
    }

    public function with(string $key, mixed $value): self
    {
        $items = $this->items;
        $items[$key] = $value;

        return new self($items);
    }

    public function without(string $key): self
    {
        $items = $this->items;
        unset($items[$key]);

        return new self($items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * @return array<string, mixed> key => value, insertion-ordered (PHP may coerce numeric keys back to int on use)
     */
    public function entries(): array
    {
        return $this->items;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map('strval', array_keys($this->items));
    }

    /**
     * Applies `$fn` to every value, keeping keys.
     *
     * @param callable(mixed, string): mixed $fn
     */
    public function map(callable $fn): self
    {
        $items = [];
        foreach ($this->items as $key => $value) {
            $items[$key] = $fn($value, (string) $key);
        }

        return new self($items);
    }
}

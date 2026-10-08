<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Value;

use JOetjen\Cooper\Values;

/**
 * A CASC tuple (CASC.md §6.11) -- `office_location = (52.5200, 13.4050)`.
 *
 * The spec requires a genuine tuple type all the way through to the
 * returned value ("an implementation must carry a real tuple type
 * through parsing, merging, and the final returned value"), and a PHP
 * `array` could not be told apart from a list again. Read-only:
 * array-style reads, `count()`, and `foreach` work, writes throw.
 *
 * @implements \ArrayAccess<int, mixed>
 * @implements \IteratorAggregate<int, mixed>
 */
final class CooperTuple implements \ArrayAccess, \Countable, \IteratorAggregate, \JsonSerializable
{
    /** @var list<mixed> */
    public readonly array $items;

    /**
     * @param array<mixed> $items the tuple's elements, in order (keys are discarded)
     */
    public function __construct(array $items)
    {
        $this->items = array_values($items);
    }

    /**
     * The tuple's elements as a plain list.
     *
     * @return list<mixed> the elements, in order
     */
    public function toArray(): array
    {
        return $this->items;
    }

    /**
     * Whether `$other` is a tuple of the same arity with equal elements.
     *
     * @param mixed $other the value to compare against
     * @return bool true when both tuples hold equal elements in the same order
     */
    public function equals(mixed $other): bool
    {
        return $other instanceof self && Values::equals($this->items, $other->items);
    }

    /**
     * @param mixed $offset the element index
     * @return bool whether that index exists
     */
    public function offsetExists(mixed $offset): bool
    {
        return is_int($offset) && array_key_exists($offset, $this->items);
    }

    /**
     * @param mixed $offset the element index
     * @return mixed the element at that index
     * @throws \OutOfRangeException if the index does not exist
     */
    public function offsetGet(mixed $offset): mixed
    {
        if (!$this->offsetExists($offset)) {
            throw new \OutOfRangeException('tuple index out of range: ' . var_export($offset, true));
        }

        return $this->items[$offset];
    }

    /**
     * @param mixed $offset ignored
     * @param mixed $value ignored
     * @throws \LogicException always -- tuples are immutable
     */
    public function offsetSet(mixed $offset, mixed $value): never
    {
        throw new \LogicException('a CooperTuple is immutable');
    }

    /**
     * @param mixed $offset ignored
     * @throws \LogicException always -- tuples are immutable
     */
    public function offsetUnset(mixed $offset): never
    {
        throw new \LogicException('a CooperTuple is immutable');
    }

    /**
     * @return int the tuple's arity
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * @return \ArrayIterator<int, mixed> an iterator over the elements
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    /**
     * Encodes as a JSON array of the elements.
     *
     * @return list<mixed> the elements, in order
     */
    public function jsonSerialize(): array
    {
        return $this->items;
    }
}

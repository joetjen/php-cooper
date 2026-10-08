<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Value;

/**
 * A CASC atom (CASC.md §6.4) -- `level = info`, `level = :info`.
 *
 * PHP has no atom type, and a plain string would erase the difference
 * between `level = info` and `level = "info"` that the format itself
 * draws. Instances are interned: `CooperAtom::of('info')` always returns
 * the same object, so `$config['level'] === CooperAtom::of('info')`
 * works the way an Elixir `config.level == :info` does.
 *
 * `:true`, `:false`, and `:nil` are not atoms but the values `true`,
 * `false`, and `null` (CASC.md §6.4 as amended): on the Elixir
 * original's runtime those atoms *are* the values, and a document must
 * mean the same thing everywhere. `:inf` is an ordinary atom.
 *
 * Interning only holds within one process; an atom that went through
 * `serialize()`/`unserialize()` is a distinct object, so compare with
 * `equals()` (or `->name`) if a value may have been serialized.
 */
final class CooperAtom implements \Stringable, \JsonSerializable
{
    /** @var array<string, CooperAtom> */
    private static array $interned = [];

    private function __construct(public readonly string $name)
    {
    }

    /**
     * Returns the (interned) atom named `$name`.
     *
     * @param string $name the atom's name, without any leading `:`
     * @return self the one instance for that name
     */
    public static function of(string $name): self
    {
        return self::$interned[$name] ??= new self($name);
    }

    /**
     * Whether `$other` is an atom with the same name.
     *
     * @param mixed $other the value to compare against
     * @return bool true for an atom of the same name
     */
    public function equals(mixed $other): bool
    {
        return $other instanceof self && $other->name === $this->name;
    }

    /**
     * The atom's name, without a leading `:` -- the same text an
     * interpolated `"@{level}"` produces.
     *
     * @return string the atom's name
     */
    public function __toString(): string
    {
        return $this->name;
    }

    /**
     * Encodes as the atom's name.
     *
     * @return string the atom's name
     */
    public function jsonSerialize(): string
    {
        return $this->name;
    }
}

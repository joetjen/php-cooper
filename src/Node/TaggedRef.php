<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Node;

/**
 * An unresolved `!Name(arg)` tagged value (CASC.md §7.5; Elixir:
 * `Cooper.Ref.Tagged`). `$arg` is an already evaluated value, possibly
 * itself an unresolved reference.
 *
 * `$literal` records whether the argument was written as a string
 * literal -- quoted, not interpolating -- which a `LiteralTag` requires.
 * It is decided where the source is still at hand (`Evaluator`), because
 * by resolution a loop variable may have been substituted into a plain
 * string indistinguishable from one written out. `$file`/`$line`/`$column`
 * locate the tag, for a `LiteralTag`'s errors.
 *
 * @internal unresolved pipeline data
 */
final class TaggedRef
{
    public function __construct(
        public readonly string $name,
        public readonly mixed $arg,
        public readonly bool $literal = false,
        public readonly ?string $file = null,
        public readonly ?int $line = null,
        public readonly ?int $column = null,
    ) {
    }

    /**
     * A copy with `$arg` in place of the argument. It stays literal only
     * while the argument is the very value it was: a substitution that
     * changes it (a loop variable bound in) makes it something not
     * written in the document.
     */
    public function withArg(mixed $arg): self
    {
        return new self($this->name, $arg, $this->literal && $arg === $this->arg, $this->file, $this->line, $this->column);
    }
}

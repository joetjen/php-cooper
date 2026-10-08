<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Node;

/**
 * A reference's `:default`, `:+alt`, or `:?"message"` suffix (CASC.md
 * §7.2) -- one rule shared by `@{}`, `${}`, and `%{}`.
 *
 * @internal unresolved pipeline data
 */
final class Suffix
{
    public const DEFAULT = 'default';
    public const SUBSTITUTE = 'substitute';
    public const REQUIRED = 'required';

    /**
     * @param string $kind one of the class constants
     * @param mixed $value the default/alternate value, or the required-message (a string or `InterpText`)
     */
    public function __construct(
        public readonly string $kind,
        public readonly mixed $value,
    ) {
    }

    public function withValue(mixed $value): self
    {
        return new self($this->kind, $value);
    }
}

<?php

declare(strict_types=1);

namespace JOetjen\Cooper;

/**
 * One `${NAME}` written as a value, as the `envValue` load option sees
 * it (see `Cooper`): the name it reads and everything written around it.
 *
 * It exists for a host that resolves the environment later than the
 * load -- a framework compiling configuration into a cache, which must
 * keep `${DB_PASSWORD}` a runtime placeholder rather than bake the
 * value the build machine happened to have. The option sees every
 * `${...}` resolution would read a value from, and nothing else: a
 * `${?NAME}` guard, a `${NAME}` in an import path or an interpolated
 * key decide the document's *shape*, and are always read at load time.
 *
 * `$tag` is set when the reference is written directly as a tag's
 * argument (`!int(${PORT})`): the option answers for the tagged value
 * as a whole, so a placeholder need not survive a tag that cannot read
 * it. A tag around anything else -- a string holding a reference
 * (`!int("${PORT}")`), a variable -- is applied to what the option gave
 * back, as to any other value.
 */
final class EnvReference
{
    /** `${NAME:value}` -- the value when `NAME` is unset or empty. */
    public const DEFAULT = 'default';

    /** `${NAME:+value}` -- the value when `NAME` is set and not empty. */
    public const SUBSTITUTE = 'substitute';

    /** `${NAME:?"message"}` -- an error saying the message when `NAME` is unset or empty. */
    public const REQUIRED = 'required';

    /**
     * @param string $name the variable, a built name (`${"TOKEN_@{id}"}`) already resolved
     * @param bool $list the `${NAME[]}` form: the value split into a list
     * @param int|null $index the `${NAME[i]}` form's index
     * @param string|null $suffix `DEFAULT`, `SUBSTITUTE`, `REQUIRED`, or `null` for none
     * @param list<string> $filters the `| name` filters, in order
     * @param string|null $tag the tag written directly around the reference, without its `!`
     * @param \Closure(): mixed $suffixValue resolves the suffix's value
     */
    public function __construct(
        public readonly string $name,
        public readonly bool $list,
        public readonly ?int $index,
        public readonly ?string $suffix,
        public readonly array $filters,
        public readonly ?string $tag,
        private readonly \Closure $suffixValue,
    ) {
    }

    /**
     * The suffix's value, resolved as any other value is -- the default,
     * the substitute, or the required-message -- or `null` without one.
     * Resolved only when asked for: an unused default that references
     * something undefined is no error, as it is none in a normal load.
     *
     * @return mixed the resolved value
     * @throws CooperError (stage `resolve`) when the value does not resolve
     */
    public function suffixValue(): mixed
    {
        return ($this->suffixValue)();
    }
}

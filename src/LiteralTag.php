<?php

declare(strict_types=1);

namespace JOetjen\Cooper;

/**
 * A consumer tag that takes **only a string literal written in the
 * document**, registered under the `tags` option like any other:
 *
 * ```php
 * Cooper::loadFile($path, ['tags' => [
 *     'php' => new LiteralTag(fn (string $code): mixed => run($code)),
 * ]]);
 * ```
 *
 * `!php("...")`, `!php('...')` and `!php("""...""")` reach the
 * function. Anything else written as the argument -- `${...}`, `@{...}`,
 * `%{...}`, `!{...}`, another tag, a string that interpolates, a number,
 * a list -- makes the load fail before the function is called, naming
 * the tag, the key and the file. So does the tag interpolated into a
 * string (`"a !php(\"x\") b"`). A loop variable or a variable holding a
 * literal is refused too: the rule is about what is written in the
 * argument's place, not about what it turns out to hold.
 *
 * It exists for a tag whose argument must never come from anywhere but
 * the file -- a framework integration's tag that runs its argument as
 * code, where a value from the environment, a resolver or another file's
 * variable must not be able to become code. That is host-specific
 * integration behaviour, not CASC's: a plain callable tag takes any
 * argument, as it always has.
 *
 * A literal tag that throws also fails the load naming the key and the
 * file, since what it ran was written there.
 */
final class LiteralTag
{
    /** @var \Closure(string): mixed */
    private readonly \Closure $fn;

    /**
     * @param callable(string): mixed $fn the tag's function, given the literal string; return the value, or throw
     */
    public function __construct(callable $fn)
    {
        $this->fn = \Closure::fromCallable($fn);
    }

    /**
     * Applies the tag. Only Cooper calls this, once it has checked the
     * argument was a literal string.
     *
     * @param mixed $argument the literal string
     * @return mixed what the function returns
     * @throws \InvalidArgumentException when `$argument` is not a string
     */
    public function __invoke(mixed $argument): mixed
    {
        if (!is_string($argument)) {
            throw new \InvalidArgumentException('a literal tag takes a string, got ' . get_debug_type($argument));
        }

        return ($this->fn)($argument);
    }
}

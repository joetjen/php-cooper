<?php

declare(strict_types=1);

namespace JOetjen\Cooper;

/**
 * A module built by `!module(...)` (CASC.md §7.5) while the conformance
 * adapter loads a document, so the answer can tell it from a string.
 *
 * An ordinary load gives a module back as a class-string
 * (`"Acme\Payments"`), which nothing distinguishes from any other
 * string. The Cooper Conformance Suite compares a module by the name
 * CASC wrote (`Acme.Payments`), so `ConformanceAdapter` registers its own
 * `!module` tag -- the built-in one, validation and errors included,
 * with its result wrapped in this marker -- and `ConformanceAdapter::encode()`
 * translates the class-string back into the written name.
 *
 * Used by the adapter only; never part of a result `Cooper` returns.
 *
 * @internal
 */
final class ConformanceModule implements \Stringable
{
    /**
     * @param string $classString the class-string the built-in `!module` gave back (`"Foo\Bar"`)
     */
    public function __construct(public readonly string $classString)
    {
    }

    /**
     * The module's name as CASC writes it: the class-string with every
     * namespace separator turned back into a dot (`"Foo\Bar"` ->
     * `"Foo.Bar"`). Exact, because the built-in tag's convention only
     * ever turns a dot into a backslash and keeps every segment as written.
     *
     * @return string the dot-separated PascalCase name
     */
    public function writtenName(): string
    {
        return str_replace('\\', '.', $this->classString);
    }

    /**
     * The written name, so a string the module is interpolated into reads
     * as CASC.md §7 says it does in every implementation (`x-Foo.Bar`),
     * as it does in an ordinary load.
     *
     * @return string the dot-separated PascalCase name
     */
    public function __toString(): string
    {
        return $this->writtenName();
    }
}

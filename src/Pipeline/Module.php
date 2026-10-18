<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Pipeline;

/**
 * A module built by the built-in `!module(...)` (CASC.md §7.5), as the
 * resolver holds it until the load is done.
 *
 * A load gives a module back as a class-string (`"Acme\Payments"`), but
 * CASC.md §7 says a module interpolated into a string reads as the name
 * the document wrote (`Acme.Payments`) in every implementation. A
 * class-string is no different from any other string, so the resolver
 * keeps this marker instead while it works: `Display::toString()` reads
 * it as the written name, and `Native::convert()` turns it into the
 * class-string the caller gets. A tag, a filter or a list edit given one
 * sees the class-string, as it always has (see `Resolver::plain()`).
 *
 * A module the application mapped through its `modules` option is never
 * one of these: it is the value it maps to, and reads as that value does.
 *
 * @internal
 */
final class Module
{
    /**
     * @param string $writtenName the name as the document wrote it, trimmed (`"Acme.Payments"`)
     */
    public function __construct(public readonly string $writtenName)
    {
    }

    /**
     * The class-string a load gives back for this module: every dot
     * becomes a namespace separator, every segment kept as written
     * (`"Acme.Payments"` -> `"Acme\Payments"`).
     *
     * @return string the class-string
     */
    public function classString(): string
    {
        return str_replace('.', '\\', $this->writtenName);
    }
}

<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Node;

/**
 * A path whose value is a lazy base (a `for` loop's `from <template>`,
 * CASC.md §5.5, resolved against the *final* merged tree) with literal
 * statements layered on top as overrides (Elixir:
 * `Cooper.Merge.Layered`). `Merge` builds one whenever a later op writes
 * underneath a path holding a `ConfigRef`; `Resolver` resolves the base
 * and deep-merges `$overrides` on top.
 *
 * @internal unresolved pipeline data
 */
final class Layered
{
    public function __construct(
        public readonly ConfigRef $base,
        public readonly MapNode $overrides,
    ) {
    }
}

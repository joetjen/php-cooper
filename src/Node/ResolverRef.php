<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Node;

/**
 * An unresolved `!{resolver:payload}` call (CASC.md §7.4; Elixir:
 * `Cooper.Ref.Resolver`). `$payload` is carried verbatim -- giving it
 * meaning is the consumer-registered resolver's job.
 *
 * @internal unresolved pipeline data
 */
final class ResolverRef
{
    public function __construct(
        public readonly string $name,
        public readonly string $payload,
    ) {
    }
}

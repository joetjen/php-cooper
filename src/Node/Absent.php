<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Node;

/**
 * Marks a key removed from what a lazy `for ... from` template supplies
 * (CASC.md §5.5) -- `-key` in the loop body, or a list edit of a key the
 * template turns out not to have (Elixir: `Cooper.Merge.Absent`). Only
 * ever appears inside a `Layered`'s overrides; resolving the `Layered`
 * drops the key.
 *
 * Without it, a `-key` in such a loop body was a no-op: the key lives in
 * the template, not in the tree the delete looked in.
 *
 * @internal unresolved pipeline data
 */
final class Absent
{
}

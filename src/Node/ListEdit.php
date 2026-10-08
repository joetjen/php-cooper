<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Node;

/**
 * A `+key`/`-key` list edit (CASC.md §8.4) whose base or operand is not
 * known until resolution -- `+tags = @{more}`, an edit of a list that is
 * itself still a reference, or an edit of a key a `for ... from`
 * template supplies (Elixir: `Cooper.Merge.ListEdit`). `Merge` leaves
 * one at the path; `Resolver` resolves both sides and applies the edit
 * element-wise.
 *
 * Applying the edit at merge time instead appended an unresolved
 * reference as one element (`["a", ["b", "c"]]` for `tags = ["a"]`
 * then `+tags = @{more}`) and ignored a template's list entirely.
 *
 * `$base` is a `ConfigRef` defaulting to `Absent` when it reads from a
 * `for ... from` template, so a template without the key is
 * distinguishable from one whose key is `nil`. `$path` is only for
 * error messages.
 *
 * @internal unresolved pipeline data
 */
final class ListEdit
{
    /**
     * @param string $op `Op::APPEND` or `Op::REMOVE`
     * @param list<string> $path
     */
    public function __construct(
        public readonly mixed $base,
        public readonly string $op,
        public readonly mixed $operand,
        public readonly array $path,
    ) {
    }
}

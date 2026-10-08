<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Tests\Pipeline;

use JOetjen\Cooper\Tests\Support\CooperTestCase;

/**
 * Property-style (hand-rolled, per AGENTS.md): CASC.md §5.4 says dotted
 * paths, nested blocks, and explicit `= { ... }` are "the same tree,
 * not just similar". For random trees, every rendering -- and any
 * interleaving of a dotted rendering's statements -- loads identically,
 * and matches the tree it was rendered from.
 */
final class SurfaceFormPropertyTest extends CooperTestCase
{
    public function testEverySurfaceFormLoadsTheSameTree(): void
    {
        mt_srand(5454);
        for ($run = 0; $run < 200; $run++) {
            $tree = self::randomTree(3);
            if ($tree === []) {
                continue;
            }

            $dotted = self::dotted($tree, []);
            shuffle($dotted);
            $forms = [
                'dotted' => implode("\n", $dotted),
                'nested' => self::nested($tree, false),
                'explicit' => self::nested($tree, true),
                // Commas separate statements only inside a block, as in
                // CASC.md §8.4's `server { host = ..., port = ... }`.
                'inline' => self::inline($tree, "\n"),
            ];

            $expected = self::normalize($tree);
            foreach ($forms as $name => $source) {
                self::assertSame($expected, self::normalize(self::load($source)), "{$name} form:\n{$source}");
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function randomTree(int $depth): array
    {
        $tree = [];
        $keys = mt_rand(1, 4);
        for ($i = 0; $i < $keys; $i++) {
            $key = 'k' . mt_rand(0, 5) . (mt_rand(0, 3) === 0 ? '-x' : '');
            $tree[$key] = $depth > 0 && mt_rand(0, 1) === 0 ? self::randomTree($depth - 1) : self::randomScalar();
        }

        return array_filter($tree, static fn (mixed $v): bool => $v !== []);
    }

    private static function randomScalar(): int|string|bool
    {
        return match (mt_rand(0, 2)) {
            0 => mt_rand(-1000, 1000),
            1 => 's' . mt_rand(0, 99),
            default => (bool) mt_rand(0, 1),
        };
    }

    /**
     * @param array<string, mixed> $tree
     * @param list<string> $prefix
     * @return list<string>
     */
    private static function dotted(array $tree, array $prefix): array
    {
        $lines = [];
        foreach ($tree as $key => $value) {
            $path = [...$prefix, (string) $key];
            if (is_array($value)) {
                array_push($lines, ...self::dotted($value, $path));
            } else {
                $lines[] = implode('.', $path) . ' = ' . self::literal($value);
            }
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $tree
     */
    private static function nested(array $tree, bool $explicit): string
    {
        $parts = [];
        foreach ($tree as $key => $value) {
            $parts[] = is_array($value)
                ? $key . ($explicit ? ' = {' : ' {') . "\n" . self::nested($value, $explicit) . "\n}"
                : "{$key} " . self::literal($value);
        }

        return implode("\n", $parts);
    }

    /**
     * @param array<string, mixed> $tree
     */
    private static function inline(array $tree, string $separator = ', '): string
    {
        $parts = [];
        foreach ($tree as $key => $value) {
            $parts[] = is_array($value) ? "{$key} { " . self::inline($value) . ' }' : "{$key} = " . self::literal($value);
        }

        return implode($separator, $parts);
    }

    private static function literal(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => '"' . $value . '"',
            default => (string) $value,
        };
    }

    private static function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        ksort($value);

        return array_map(self::normalize(...), $value);
    }
}

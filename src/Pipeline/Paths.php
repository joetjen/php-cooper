<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Pipeline;

/**
 * Filesystem path helpers mirroring the Elixir `Path` functions the
 * original relies on: `expand()` (absolute and normalized, symlinks
 * left alone), `join()`, and `wildcard()` -- `*`, `?`, `[...]`, and
 * `**` (zero or more directories) glob matching, with matches in
 * lexicographic order and dot-files only matched when the pattern
 * spells the dot out.
 *
 * @internal
 */
final class Paths
{
    private function __construct()
    {
    }

    /**
     * `$path` made absolute (against `$base`, default the working
     * directory) with `.`/`..` segments and duplicate slashes removed.
     */
    public static function expand(string $path, ?string $base = null): string
    {
        if (!str_starts_with($path, '/')) {
            $path = rtrim($base ?? (string) getcwd(), '/') . '/' . $path;
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return '/' . implode('/', $segments);
    }

    /**
     * Joins two path parts with exactly one `/` between them -- an
     * absolute `$right` is still appended, as Elixir's `Path.join/2`
     * does.
     */
    public static function join(string $left, string $right): string
    {
        if ($left === '') {
            return $right;
        }

        return rtrim($left, '/') . '/' . ltrim($right, '/');
    }

    /**
     * Every file `$pattern` (braces and wildcards, CASC.md §5.1) matches
     * under `$root`: absolute, de-duplicated, sorted. Shared by `Loader`
     * and `ImportGlob`, which expands a recorded import again to tell
     * whether its matches changed -- so both must expand alike.
     *
     * @return list<string>
     */
    public static function expandImport(string $pattern, string $root): array
    {
        $files = [];
        foreach (self::expandBraces($pattern) as $expanded) {
            foreach (self::wildcard(self::join($root, $expanded)) as $match) {
                $files[] = self::expand($match);
            }
        }
        $files = array_values(array_unique($files));
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * Expands `{a,b}` brace groups (recursively, so several groups in
     * one pattern all expand), left to right.
     *
     * @return list<string>
     */
    public static function expandBraces(string $pattern): array
    {
        if (preg_match('/\A(.*?)\{([^{}]+)\}(.*)\z/s', $pattern, $m) !== 1) {
            return [$pattern];
        }

        $expanded = [];
        foreach (explode(',', $m[2]) as $alternative) {
            array_push($expanded, ...self::expandBraces($m[1] . $alternative . $m[3]));
        }

        return $expanded;
    }

    /**
     * Every existing file matching the absolute glob `$pattern`, sorted.
     *
     * @return list<string>
     */
    public static function wildcard(string $pattern): array
    {
        $segments = explode('/', $pattern);
        $fixed = [];
        while ($segments !== [] && !self::hasWildcard($segments[0])) {
            $fixed[] = array_shift($segments);
        }
        $base = implode('/', $fixed);
        if ($segments === []) {
            return file_exists($base) ? [$base] : [];
        }
        if ($base === '') {
            $base = '/';
        }
        if (!is_dir($base)) {
            return [];
        }

        $wildPart = implode('/', $segments);
        $matchDot = str_starts_with($wildPart, '.') || str_contains($wildPart, '/.');
        $regex = '#\A' . self::toRegex($wildPart) . '\z#s';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO),
                static fn (\SplFileInfo $file): bool => $matchDot || !str_starts_with($file->getFilename(), '.'),
            ),
        );

        $matches = [];
        $prefixLength = strlen(rtrim($base, '/')) + 1;
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $relative = substr($file->getPathname(), $prefixLength);
            if (preg_match($regex, $relative) === 1) {
                $matches[] = $file->getPathname();
            }
        }
        sort($matches, SORT_STRING);

        return $matches;
    }

    private static function hasWildcard(string $segment): bool
    {
        return strpbrk($segment, '*?[') !== false;
    }

    private static function toRegex(string $glob): string
    {
        $regex = '';
        $length = strlen($glob);
        for ($i = 0; $i < $length; $i++) {
            $char = $glob[$i];
            if ($char === '*' && ($glob[$i + 1] ?? '') === '*') {
                $i++;
                if (($glob[$i + 1] ?? '') === '/') {
                    // `**/` -- zero or more whole directories.
                    $i++;
                    $regex .= '(?:[^/]*/)*';
                } else {
                    $regex .= '.*';
                }
            } elseif ($char === '*') {
                $regex .= '[^/]*';
            } elseif ($char === '?') {
                $regex .= '[^/]';
            } elseif ($char === '[') {
                $close = strpos($glob, ']', $i + 1);
                if ($close === false) {
                    $regex .= '\[';
                } else {
                    $class = substr($glob, $i + 1, $close - $i - 1);
                    $regex .= '[' . (str_starts_with($class, '!') ? '^' . substr($class, 1) : $class) . ']';
                    $i = $close;
                }
            } else {
                $regex .= preg_quote($char, '#');
            }
        }

        return $regex;
    }
}

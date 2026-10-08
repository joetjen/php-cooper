<?php

declare(strict_types=1);

namespace JOetjen\Cooper;

use JOetjen\Cooper\Pipeline\Paths;

/**
 * One filesystem import's expansion: the `$pattern` it named (braces and
 * wildcards, CASC.md §5.1, after `${NAME}` interpolation), the `$root`
 * it was expanded under, and the `$files` it matched then.
 *
 * It exists because the files a load read cannot show a file added
 * where a glob import looks -- that file was never read. Only the
 * pattern, expanded again, can: `Cache` does so on every hit, and a
 * caller keeping a loaded config elsewhere (see `LoadTrace`) can do the
 * same with `changed()`.
 */
final class ImportGlob
{
    /**
     * @param string $root the directory the pattern was expanded under
     * @param string $pattern the import's path, as the document gave it
     * @param list<string> $files what it matched: absolute, de-duplicated, sorted
     */
    public function __construct(
        public readonly string $root,
        public readonly string $pattern,
        public readonly array $files,
    ) {
    }

    /**
     * What the pattern matches on disk now, by the same expansion the
     * loader uses.
     *
     * @return list<string> absolute, de-duplicated, sorted
     */
    public function expand(): array
    {
        return Paths::expandImport($this->pattern, $this->root);
    }

    /**
     * Whether the pattern now matches a different set of files: one
     * added where it looks, or one removed from there.
     */
    public function changed(): bool
    {
        return $this->expand() !== $this->files;
    }
}

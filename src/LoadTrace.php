<?php

declare(strict_types=1);

namespace JOetjen\Cooper;

/**
 * What `Cooper::loadFileTraced()` returns: the loaded config, together
 * with everything that load depended on.
 *
 * It exists for a caller that keeps a loaded config somewhere `Cache`
 * cannot see -- a compiled PHP file, a shared store -- and so must
 * decide for itself when that copy has gone stale. The answer is the
 * same one `Cache` gives itself: the copy holds while every file in
 * `$files` keeps its modification time, no import in `$globs` has
 * `changed()` -- a file added where `import "parts/*.casc"` looks is in
 * no list of files read -- and every name in `$env` still has the value
 * recorded there.
 *
 * `$env` holds every environment variable the document read in any way:
 * an ordinary `${NAME}` value, a `${?NAME}` guard, `${NAME}` in an
 * import path or an interpolated key. A name read while unset or empty
 * is recorded as `null` -- unset and empty read the same (CASC.md §7.2).
 * The values are as resolution saw them, after `.env` layering and the
 * `COOPER_ENV` fallback (see `Dotenv`), and may well be secrets: treat
 * this object as carefully as the config itself.
 *
 * A `scheme://` import has no file to fingerprint, and a `!{...}`
 * resolver's answer has no name to compare; neither appears here.
 */
final class LoadTrace
{
    /**
     * @param array<array-key, mixed> $config the loaded config, exactly as `Cooper::loadFile()` returns it
     * @param list<string> $files every real file the load read: the entry file and everything it imported, as absolute paths
     * @param array<string, string|null> $env every environment variable the document read => the value it saw, `null` for unset or empty
     * @param list<ImportGlob> $globs every filesystem import's expansion: its root, its pattern, and the files it matched
     */
    public function __construct(
        public readonly array $config,
        public readonly array $files,
        public readonly array $env,
        public readonly array $globs = [],
    ) {
    }
}

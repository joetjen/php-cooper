<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Pipeline;

use JOetjen\Cooper\ImportGlob;
use JOetjen\Cooper\Node\MapNode;

/**
 * Everything a load produces before reference resolution: the merged
 * (still partly unresolved) tree, the variable environment to resolve
 * it against, every real file that contributed and every filesystem
 * import's expansion (both for cache fingerprinting), and every environment variable whose value shaped
 * the tree itself -- `${?NAME}` guards, `${NAME}` in an import path,
 * and `${NAME}` in an interpolated key -- which a cached tree goes
 * stale on.
 *
 * @internal
 */
final class TreeResult
{
    /**
     * @param list<string> $loadedFiles
     * @param list<string> $shapeEnvNames
     * @param list<ImportGlob> $loadedGlobs
     */
    public function __construct(
        public readonly MapNode $tree,
        public readonly VarEnv $vars,
        public readonly array $loadedFiles,
        public readonly array $shapeEnvNames,
        public readonly array $loadedGlobs = [],
    ) {
    }
}

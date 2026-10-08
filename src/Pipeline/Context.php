<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Pipeline;

use JOetjen\Cooper\ImportGlob;

/**
 * Per-file evaluation state, threaded through `Evaluator` in source
 * order (the Elixir original's `ctx` map). Mutable: a file's
 * declarations and imports update it as they are evaluated, so later
 * statements -- a `for` loop's iterable, for one -- see them.
 *
 * Each imported file gets a fresh context of its own (`forImport()`);
 * `Loader` folds the parts that cross file boundaries back into the
 * importer's afterwards.
 *
 * @internal
 */
final class Context
{
    /**
     * name => [value, public?]; a private declaration is keyed by
     * `Scope::privateKey()`, so it never overwrites a public one.
     *
     * @var array<string, array{0: mixed, 1: bool}>
     */
    public array $vars = [];

    /** @var array<string, array<string, mixed>> scope => (name => value), from imported files */
    public array $privateVars = [];

    /** @var array<string, true> names whose value shaped the tree (guards, import paths) */
    public array $shapeEnvNames = [];

    /** @var array<string, ImportGlob> every filesystem import's expansion, keyed by root and pattern */
    public array $loadedGlobs = [];

    /**
     * @param array<string, callable> $importSchemes scheme => loader
     * @param array<string, true> $importing absolute paths currently being imported (cycle detection)
     * @param array<string, true> $loadedFiles every real file this load has read
     * @param array<string, string> $env the environment `${...}` reads at parse time
     */
    public function __construct(
        public readonly string $scope,
        public readonly string $root,
        public readonly ?string $file,
        public readonly array $importSchemes,
        public readonly array $importing,
        public array $loadedFiles,
        public readonly array $env,
    ) {
    }

    /**
     * The context a file imported from this one starts with: no
     * variables of its own (visibility is applied at resolve time, not
     * by seeding), its own scope, and -- for a real file -- its own
     * directory as the root its own imports resolve against.
     */
    public function forImport(string $label, ?string $file): self
    {
        return new self(
            Scope::id($file ?? $label, $file === null),
            $file === null ? $this->root : dirname($file),
            $file,
            $this->importSchemes,
            $this->importing + [($file ?? $label) => true],
            $this->loadedFiles,
            $this->env,
        );
    }
}

<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Pipeline;

use JOetjen\Cooper\CooperError;
use JOetjen\Cooper\Node\Clear;
use JOetjen\Cooper\Node\InterpText;
use JOetjen\Cooper\Node\MapNode;
use JOetjen\Cooper\Node\Op;
use JOetjen\Cooper\Node\VarDecl;
use JOetjen\Cooper\Syntax\Parser;

/**
 * Runs the pipeline up to (not including) reference resolution -- the
 * Elixir original's `Cooper.Grammar`: parse, evaluate (loops and
 * imports expanded inline), scope-stamp, merge. `Cooper` wires this to
 * `Resolver`; calling a stage directly is mostly useful for tests and
 * tooling.
 *
 * Options, for every method: `root` (the directory a bare import
 * resolves against, default the working directory), `file` (the entry
 * source's own path, for import-cycle detection and private-variable
 * scoping), `importSchemes`, and `env` (what `${?NAME}` guards and
 * import paths read while parsing; default the real environment).
 *
 * @internal
 */
final class Grammar
{
    private function __construct()
    {
    }

    /**
     * Parses and merges `$source`, without resolving references.
     *
     * @param array{root?: string, file?: string|null, importSchemes?: array<string, callable>, env?: array<string, string>} $options
     */
    public static function run(string $source, array $options = []): MapNode
    {
        return self::runTree($source, $options)->tree;
    }

    /**
     * `run()` for a file, seeding `root`/`file` from its path.
     *
     * @param array{root?: string, file?: string|null, importSchemes?: array<string, callable>, env?: array<string, string>} $options
     * @throws CooperError (stage `import`) when the file cannot be read
     */
    public static function runFile(string $path, array $options = []): MapNode
    {
        $absolute = Paths::expand($path);

        return self::run(self::read($path, $absolute), ['root' => dirname($absolute), 'file' => $absolute] + $options);
    }

    /**
     * Parses and merges `$source`, returning the tree together with the
     * variable environment it resolves against, the files that
     * contributed, and the environment names that shaped it.
     *
     * @param array{root?: string, file?: string|null, importSchemes?: array<string, callable>, env?: array<string, string>} $options
     */
    public static function runTree(string $source, array $options = []): TreeResult
    {
        $ctx = self::initialContext($options);
        $entries = self::runWithContext($source, $ctx);

        [$public, $private] = Scope::split($ctx->vars);
        $privateVars = $ctx->privateVars;
        $privateVars[$ctx->scope] = $private;
        $vars = new VarEnv($public, $privateVars);

        $keys = Resolver::forKeys($vars, $ctx->env);
        $tree = Merge::assemble($entries, static fn (InterpText $segment): string => $keys->key($segment));

        $shapeNames = array_keys($ctx->shapeEnvNames + array_fill_keys($keys->envNames(), true));

        return new TreeResult(
            $tree,
            $vars,
            array_map('strval', array_keys($ctx->loadedFiles)),
            array_map('strval', $shapeNames),
            array_values($ctx->loadedGlobs),
        );
    }

    /**
     * The flat entry list for `$source`, unmerged.
     *
     * @param array{root?: string, file?: string|null, importSchemes?: array<string, callable>, env?: array<string, string>} $options
     * @return list<Op|VarDecl|Clear>
     */
    public static function runOps(string $source, array $options = []): array
    {
        return self::runWithContext($source, self::initialContext($options));
    }

    /**
     * @param array{root?: string, file?: string|null, importSchemes?: array<string, callable>, env?: array<string, string>} $options
     */
    public static function initialContext(array $options = []): Context
    {
        $file = isset($options['file']) ? Paths::expand($options['file']) : null;
        $seen = $file === null ? [] : [$file => true];

        return new Context(
            Scope::id($file),
            $options['root'] ?? (string) getcwd(),
            $file,
            $options['importSchemes'] ?? [],
            $seen,
            $seen,
            $options['env'] ?? Resolver::systemEnv(),
        );
    }

    /**
     * Parses and evaluates one file's source against `$ctx`, then
     * attributes every `@{...}` it wrote to that file (`Scope::stamp()`)
     * before its entries can be spliced into anyone else's.
     *
     * @return list<Op|VarDecl|Clear>
     */
    public static function runWithContext(string $source, Context $ctx): array
    {
        $entries = Evaluator::evaluate(Parser::parse($source, $ctx->file), $ctx);

        /** @var list<Op|VarDecl|Clear> */
        return array_map(static fn (Op|VarDecl|Clear $entry): mixed => Scope::stamp($entry, $ctx->scope), $entries);
    }

    /**
     * Reads `$absolute`, failing with the `import`-stage error the
     * Elixir original raises for an unreadable entry file.
     *
     * @throws CooperError
     */
    public static function read(string $path, string $absolute): string
    {
        if (is_dir($absolute)) {
            throw new CooperError('could not read ' . Display::inspect($path) . ': is a directory', CooperError::STAGE_IMPORT);
        }
        $source = @file_get_contents($absolute);
        if ($source === false) {
            $reason = file_exists($absolute) ? 'permission denied' : 'no such file or directory';

            throw new CooperError('could not read ' . Display::inspect($path) . ": {$reason}", CooperError::STAGE_IMPORT);
        }

        return $source;
    }
}

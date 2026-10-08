<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Pipeline;

use JOetjen\Cooper\CooperError;
use JOetjen\Cooper\ImportGlob;
use JOetjen\Cooper\Node\Clear;
use JOetjen\Cooper\Node\EnvRef;
use JOetjen\Cooper\Node\InterpText;
use JOetjen\Cooper\Node\Op;
use JOetjen\Cooper\Node\Suffix;
use JOetjen\Cooper\Node\VarDecl;

/**
 * Resolves and loads an `import "..."` statement (CASC.md §5.1) inline,
 * at the point it is evaluated, so the variables it brings in are
 * visible to whatever follows it -- the Elixir original's
 * `Cooper.Loader`.
 *
 *  - A bare path resolves relative to the *importing* file's directory.
 *    `**`/`*` globs and `{a,b}` braces expand against the filesystem;
 *    matches load in lexicographic order.
 *  - A `scheme://` path goes to the loader registered for that scheme
 *    (`importSchemes` option, §9.3), which returns CASC source text. An
 *    unregistered scheme is an error naming it (§9.4).
 *  - A path may interpolate `${NAME}`/`${NAME:default}`, read from the
 *    environment while parsing; anything else (`%{...}` needs the
 *    finished tree) is an error, and so is an unset variable with no
 *    default.
 *
 * Every imported file is a complete CASC file, run through the whole
 * per-file pipeline before its entries reach the importer. An import
 * cycle is an error naming the chain.
 *
 * @internal
 */
final class Loader
{
    private const SCHEME = '#\A([a-zA-Z][a-zA-Z0-9+.\-]*)://(.+)\z#s';

    private function __construct()
    {
    }

    /**
     * @return list<Op|VarDecl|Clear> the imported file(s)' entries, spliced together
     * @throws CooperError (stage `import`, or whatever stage an imported file fails in)
     */
    public static function loadImport(string|InterpText $path, Context $ctx): array
    {
        if ($path instanceof InterpText) {
            $path = self::interpolate($path, $ctx);
        }

        if (preg_match(self::SCHEME, $path, $m) === 1) {
            return self::loadScheme($m[1], $m[2], $ctx);
        }

        return self::loadFilesystem($path, $ctx);
    }

    private static function interpolate(InterpText $text, Context $ctx): string
    {
        $path = '';
        foreach ($text->segments as $segment) {
            if (is_string($segment)) {
                $path .= $segment;
                continue;
            }
            if (!$segment instanceof EnvRef || !is_string($segment->name) || $segment->index !== null
                || $segment->list || $segment->filters !== []) {
                throw self::error(
                    'an import path may interpolate only "${NAME}" or "${NAME:default}", got: ' . self::describe($segment),
                    $ctx,
                );
            }

            $ctx->shapeEnvNames[$segment->name] = true;
            $value = $ctx->env[$segment->name] ?? '';
            if ($value !== '') {
                $path .= $value;
            } elseif ($segment->suffix?->kind === Suffix::DEFAULT && Display::displayable($segment->suffix->value)) {
                $path .= Display::toString($segment->suffix->value);
            } else {
                throw self::error(
                    'import path references ' . Display::inspect($segment->name) . ', which is unset and has no default',
                    $ctx,
                );
            }
        }

        return $path;
    }

    /**
     * @return list<Op|VarDecl|Clear>
     */
    private static function loadScheme(string $scheme, string $rest, Context $ctx): array
    {
        $loader = $ctx->importSchemes[$scheme] ?? null;
        if ($loader === null) {
            throw self::error('unregistered import scheme ' . Display::inspect($scheme), $ctx);
        }

        $label = "{$scheme}://{$rest}";
        if (isset($ctx->importing[$label])) {
            throw self::cycle($ctx, $label);
        }

        try {
            $source = $loader($rest);
        } catch (\Throwable $e) {
            throw self::error("{$scheme}:// loader failed for " . Display::inspect($rest) . ': ' . $e->getMessage(), $ctx, $e);
        }
        if (!is_string($source)) {
            throw self::error("{$scheme}:// loader for " . Display::inspect($rest) . ' must return CASC source text, got ' . get_debug_type($source), $ctx);
        }

        return self::loadSource($label, null, $source, $ctx);
    }

    /**
     * @return list<Op|VarDecl|Clear>
     */
    private static function loadFilesystem(string $pattern, Context $ctx): array
    {
        $files = Paths::expandImport($pattern, $ctx->root);
        // The expansion itself is recorded beside the files it found, so
        // `Cache` can expand the pattern again and see a file that now
        // matches -- or no longer does. Fingerprinting only the files
        // read, as this once did, noticed one edited or deleted, never one
        // added where a glob looks.
        $ctx->loadedGlobs[$ctx->root . "\0" . $pattern] = new ImportGlob($ctx->root, $pattern, $files);

        if ($files === []) {
            throw self::error('import ' . Display::inspect($pattern) . " matched no files (searched under {$ctx->root})", $ctx);
        }

        $entries = [];
        foreach ($files as $file) {
            if (isset($ctx->importing[$file])) {
                throw self::cycle($ctx, $file);
            }
            $source = @file_get_contents($file);
            if ($source === false || is_dir($file)) {
                $reason = is_dir($file) ? 'is a directory' : (error_get_last()['message'] ?? 'unreadable');
                throw self::error('could not read import ' . Display::inspect($file) . ": {$reason}", $ctx);
            }
            $ctx->loadedFiles[$file] = true;
            array_push($entries, ...self::loadSource($file, $file, $source, $ctx));
        }

        return $entries;
    }

    /**
     * Runs one imported source through the per-file pipeline and folds
     * what crosses the file boundary back into the importer: its public
     * variables (CASC.md §5.2 -- private ones never leave the file, but
     * travel up under its own scope so its own references still resolve),
     * the files it read, the imports it expanded, and the environment
     * names that shaped it.
     *
     * @return list<Op|VarDecl|Clear>
     */
    private static function loadSource(string $label, ?string $file, string $source, Context $ctx): array
    {
        $sub = $ctx->forImport($label, $file);

        try {
            $entries = Grammar::runWithContext($source, $sub);
        } catch (CooperError $e) {
            throw $e->withFile($file ?? $label);
        }

        [$public, $private] = Scope::split($sub->vars);
        foreach ($public as $name => $value) {
            $ctx->vars[$name] = [$value, true];
        }
        $ctx->privateVars = array_merge($ctx->privateVars, $sub->privateVars);
        if ($private !== []) {
            $ctx->privateVars[$sub->scope] = $private;
        }
        $ctx->loadedFiles += $sub->loadedFiles;
        $ctx->loadedGlobs += $sub->loadedGlobs;
        $ctx->shapeEnvNames += $sub->shapeEnvNames;

        return $entries;
    }

    private static function cycle(Context $ctx, string $file): CooperError
    {
        $chain = array_keys($ctx->importing);
        sort($chain, SORT_STRING);

        return self::error('import cycle detected: ' . implode(' -> ', $chain) . " -> {$file}", $ctx);
    }

    private static function describe(mixed $segment): string
    {
        return match (true) {
            $segment instanceof EnvRef => '${' . (is_string($segment->name) ? $segment->name : '"..."') . '...}',
            default => (new \ReflectionClass($segment))->getShortName(),
        };
    }

    private static function error(string $message, Context $ctx, ?\Throwable $previous = null): CooperError
    {
        return new CooperError($message, CooperError::STAGE_IMPORT, null, null, $ctx->file, $previous);
    }
}

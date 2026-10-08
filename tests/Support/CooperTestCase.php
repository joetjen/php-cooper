<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Tests\Support;

use JOetjen\Cooper\Cooper;
use JOetjen\Cooper\CooperError;
use JOetjen\Cooper\Node\Clear;
use JOetjen\Cooper\Node\Op;
use JOetjen\Cooper\Node\VarDecl;
use JOetjen\Cooper\Pipeline\Grammar;
use JOetjen\Cooper\Pipeline\Native;
use JOetjen\Cooper\Pipeline\Resolver;
use PHPUnit\Framework\TestCase;

/**
 * Shared helpers mirroring the Elixir suite's own `load!/value/ops`
 * helpers, so ported tests read the same. Every helper prepends the
 * mandatory version header and keeps `.env` loading off, so the
 * project's own working directory never leaks into a result.
 */
abstract class CooperTestCase extends TestCase
{
    protected const HEADER = "#@version = 1.0\n";

    /**
     * Full pipeline, through `Cooper::loadString()`.
     *
     * @param array<string, mixed> $options
     * @return array<array-key, mixed>
     */
    protected static function load(string $source, array $options = []): array
    {
        return Cooper::loadString(self::HEADER . $source, $options + ['dotenv' => false]);
    }

    /**
     * The error `load()` throws.
     *
     * @param array<string, mixed> $options
     */
    protected static function loadError(string $source, array $options = []): CooperError
    {
        try {
            self::load($source, $options);
        } catch (CooperError $e) {
            return $e;
        }
        self::fail('expected a CooperError, loaded fine');
    }

    /**
     * Parse + merge only (Elixir `Cooper.Grammar.run/2`), converted to
     * native arrays for comparison; references stay unresolved.
     *
     * @param array<string, mixed> $options
     */
    protected static function merged(string $source, array $options = []): mixed
    {
        return Native::convert(Grammar::run(self::HEADER . $source, $options + ['env' => []]));
    }

    /**
     * The error `merged()` throws.
     *
     * @param array<string, mixed> $options
     */
    protected static function mergedError(string $source, array $options = []): CooperError
    {
        try {
            self::merged($source, $options);
        } catch (CooperError $e) {
            return $e;
        }
        self::fail('expected a CooperError, ran fine');
    }

    /**
     * The merged, unresolved value of a one-line `v = <literal>`.
     */
    protected static function value(string $literal): mixed
    {
        $result = self::merged("v = {$literal}");
        assert(is_array($result));

        return $result['v'];
    }

    /**
     * The raw flattened entries (Elixir `Cooper.Grammar.run_ops/2`).
     *
     * @return list<Op|VarDecl|Clear>
     */
    protected static function ops(string $source): array
    {
        return Grammar::runOps(self::HEADER . $source, ['env' => []]);
    }

    /**
     * Parse + merge, then resolve directly through `Resolver` -- the
     * Elixir suite's `run_tree` + `Resolver.resolve` helper.
     *
     * @param array<string, mixed> $options vars/env/resolvers/tags
     */
    protected static function resolve(string $source, array $options = []): mixed
    {
        $result = Grammar::runTree(self::HEADER . $source, ['env' => $options['env'] ?? []]);
        /** @phpstan-ignore argument.type */
        [$resolved] = Resolver::resolve($result->tree, $options + ['vars' => $result->vars, 'env' => []]);

        return $resolved;
    }

    /**
     * The error `resolve()` throws.
     *
     * @param array<string, mixed> $options
     */
    protected static function resolveError(string $source, array $options = []): CooperError
    {
        try {
            self::resolve($source, $options);
        } catch (CooperError $e) {
            return $e;
        }
        self::fail('expected a CooperError, resolved fine');
    }

    protected static function fixture(string ...$parts): string
    {
        return dirname(__DIR__) . '/fixtures/' . implode('/', $parts);
    }

    /**
     * A fresh, empty scratch directory under the system temp dir,
     * removed again after the test.
     */
    protected function scratchDir(string $name): string
    {
        $dir = sys_get_temp_dir() . '/php_cooper_test_' . $name . '_' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $this->scratchDirs[] = $dir;

        return $dir;
    }

    /** @var list<string> */
    private array $scratchDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->scratchDirs as $dir) {
            self::removeTree($dir);
        }
        $this->scratchDirs = [];
        parent::tearDown();
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}

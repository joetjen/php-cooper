<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Tests;

use JOetjen\Cooper\Cache;
use JOetjen\Cooper\CacheEvent;
use JOetjen\Cooper\Cooper;
use JOetjen\Cooper\CooperError;
use JOetjen\Cooper\Node\MapNode;
use JOetjen\Cooper\Pipeline\TreeResult;
use JOetjen\Cooper\Pipeline\VarEnv;
use JOetjen\Cooper\Tests\Support\CooperTestCase;

/**
 * `Cooper::loadFile()`'s cache -- ported from the Elixir suite's
 * `cache_test.exs`. Every write pins an explicit modification time with
 * `touch()`, so two writes in one test never collide on the same
 * second.
 */
final class CacheTest extends CooperTestCase
{
    /** @var list<CacheEvent> */
    private array $events = [];
    private ?int $listener = null;

    /** @var list<string> */
    private array $putenvNames = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::clear();
        $this->listener = Cache::listen(function (CacheEvent $event): void {
            $this->events[] = $event;
        });
    }

    protected function tearDown(): void
    {
        if ($this->listener !== null) {
            Cache::unlisten($this->listener);
        }
        foreach ($this->putenvNames as $name) {
            putenv($name);
        }
        Cache::clear();
        parent::tearDown();
    }

    private static function write(string $path, int $mtime, string $content): void
    {
        file_put_contents($path, $content);
        touch($path, $mtime);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<array-key, mixed>
     */
    private static function loadFile(string $path, array $options = []): array
    {
        return Cooper::loadFile($path, $options + ['dotenv' => false]);
    }

    private function systemEnv(string $name, ?string $value): void
    {
        putenv($value === null ? $name : "{$name}={$value}");
        $this->putenvNames[] = $name;
    }

    public function testAHitReusesTheParsedTree(): void
    {
        $path = $this->scratchDir('hit') . '/c.casc';
        self::write($path, 1000, "#@version = 1.0\nname = \"first\"\n");
        self::assertSame(['name' => 'first'], self::loadFile($path));

        // Same mtime: the cache trusts the fingerprint over re-reading.
        self::write($path, 1000, "#@version = 1.0\nname = \"second\"\n");
        self::assertSame(['name' => 'first'], self::loadFile($path));
    }

    public function testEnvironmentValuesAreAlwaysFresh(): void
    {
        $path = $this->scratchDir('env') . '/c.casc';
        self::write($path, 2000, "#@version = 1.0\nvalue = \${COOPER_CACHE_TEST_VAR:\"default\"}\n");
        $this->systemEnv('COOPER_CACHE_TEST_VAR', null);

        self::assertSame(['value' => 'default'], self::loadFile($path));
        $this->systemEnv('COOPER_CACHE_TEST_VAR', 'live');
        self::assertSame(['value' => 'live'], self::loadFile($path));
    }

    public function testAnMtimeChangeInvalidates(): void
    {
        $path = $this->scratchDir('miss') . '/c.casc';
        self::write($path, 3000, "#@version = 1.0\nname = \"before\"\n");
        self::assertSame(['name' => 'before'], self::loadFile($path));

        self::write($path, 3001, "#@version = 1.0\nname = \"after\"\n");
        self::assertSame(['name' => 'after'], self::loadFile($path));
    }

    public function testAnImportedFileIsFingerprintedToo(): void
    {
        $dir = $this->scratchDir('import');
        self::write("{$dir}/base.casc", 4000, "#@version = 1.0\nshared = \"base-v1\"\n");
        self::write("{$dir}/importer.casc", 4000, "#@version = 1.0\nimport \"base.casc\"\n");
        self::assertSame(['shared' => 'base-v1'], self::loadFile("{$dir}/importer.casc"));

        self::write("{$dir}/base.casc", 4001, "#@version = 1.0\nshared = \"base-v2\"\n");
        self::assertSame(['shared' => 'base-v2'], self::loadFile("{$dir}/importer.casc"));
    }

    public function testADeletedImportSurfacesTheReadErrorNotAStaleHit(): void
    {
        $dir = $this->scratchDir('import_del');
        self::write("{$dir}/base.casc", 4100, "#@version = 1.0\nshared = \"base-v1\"\n");
        self::write("{$dir}/importer.casc", 4100, "#@version = 1.0\nimport \"base.casc\"\n");
        self::loadFile("{$dir}/importer.casc");

        unlink("{$dir}/base.casc");

        try {
            self::loadFile("{$dir}/importer.casc");
            self::fail('expected an import error');
        } catch (CooperError $e) {
            self::assertSame(CooperError::STAGE_IMPORT, $e->stage);
        }
    }

    public function testCacheFalseNeverReadsOrPopulates(): void
    {
        $path = $this->scratchDir('bypass') . '/c.casc';
        self::write($path, 5000, "#@version = 1.0\nname = \"only\"\n");

        self::assertNull(Cache::fetch($path, dirname($path), []));
        self::assertSame(['name' => 'only'], self::loadFile($path, ['cache' => false]));
        self::assertNull(Cache::fetch($path, dirname($path), []));
    }

    public function testInvalidateRemovesOnlyThatPath(): void
    {
        $dir = $this->scratchDir('inv');
        self::write("{$dir}/a.casc", 6000, "#@version = 1.0\nname = \"a\"\n");
        self::write("{$dir}/b.casc", 6000, "#@version = 1.0\nname = \"b\"\n");
        self::loadFile("{$dir}/a.casc");
        self::loadFile("{$dir}/b.casc");

        self::assertNotNull(Cache::fetch("{$dir}/a.casc", $dir, getenv()));
        Cache::invalidate("{$dir}/a.casc");
        self::assertNull(Cache::fetch("{$dir}/a.casc", $dir, getenv()));
        self::assertNotNull(Cache::fetch("{$dir}/b.casc", $dir, getenv()));
    }

    public function testClearRemovesEverything(): void
    {
        $path = $this->scratchDir('clear') . '/c.casc';
        self::write($path, 7000, "#@version = 1.0\nname = \"x\"\n");
        self::loadFile($path);

        self::assertNotNull(Cache::fetch($path, dirname($path), getenv()));
        Cache::clear();
        self::assertNull(Cache::fetch($path, dirname($path), getenv()));
    }

    public function testALoaderThatThrowsCachesNothing(): void
    {
        try {
            Cache::populate('/virtual.casc', '/', [], static fn (): TreeResult => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
        }
        self::assertNull(Cache::fetch('/virtual.casc', '/', []));

        Cache::populate('/virtual.casc', '/', [], static fn (): TreeResult => new TreeResult(new MapNode(), new VarEnv(), [], []));
        self::assertNotNull(Cache::fetch('/virtual.casc', '/', []));
    }

    public function testFileChangedFiresOnAReloadNeverOnFirstPopulation(): void
    {
        $path = $this->scratchDir('telem_file') . '/c.casc';
        self::write($path, 9000, "#@version = 1.0\nname = \"first\"\n");
        self::loadFile($path);
        self::assertSame([], $this->events);

        self::write($path, 9001, "#@version = 1.0\nname = \"second\"\n");
        self::loadFile($path);

        self::assertCount(1, $this->events);
        self::assertSame(CacheEvent::FILE_CHANGED, $this->events[0]->type);
        self::assertSame($path, $this->events[0]->path);
        self::assertSame(dirname($path), $this->events[0]->root);
        self::assertSame([$path], $this->events[0]->changed);
    }

    public function testFileChangedNamesTheChangedImport(): void
    {
        $dir = $this->scratchDir('telem_import');
        self::write("{$dir}/base.casc", 9100, "#@version = 1.0\nshared = \"v1\"\n");
        self::write("{$dir}/importer.casc", 9100, "#@version = 1.0\nimport \"base.casc\"\n");
        self::loadFile("{$dir}/importer.casc");

        self::write("{$dir}/base.casc", 9101, "#@version = 1.0\nshared = \"v2\"\n");
        self::loadFile("{$dir}/importer.casc");

        self::assertSame(["{$dir}/base.casc"], $this->events[0]->changed);
    }

    // A glob import: the fingerprint once held only the files a load
    // read, so a file added where `import "parts/*.casc"` looks was never
    // noticed and the cached tree kept loading without it.

    private function globFixture(string $import = 'parts/*.casc'): string
    {
        $dir = $this->scratchDir('glob');
        mkdir("{$dir}/parts");
        self::write("{$dir}/app.casc", 9500, "#@version = 1.0\nimport \"{$import}\"\n");
        self::write("{$dir}/parts/a.casc", 9500, "#@version = 1.0\na = 1\n");

        return $dir;
    }

    public function testAFileAddedWhereAGlobImportLooksIsReadOnTheNextLoad(): void
    {
        $dir = $this->globFixture();
        self::assertSame(['a' => 1], self::loadFile("{$dir}/app.casc"));

        self::write("{$dir}/parts/b.casc", 9500, "#@version = 1.0\nb = 2\n");

        self::assertSame(['a' => 1, 'b' => 2], self::loadFile("{$dir}/app.casc"));
    }

    public function testAFileDeletedFromWhereAGlobImportLooksIsGoneOnTheNextLoad(): void
    {
        $dir = $this->globFixture();
        self::write("{$dir}/parts/b.casc", 9500, "#@version = 1.0\nb = 2\n");
        self::assertSame(['a' => 1, 'b' => 2], self::loadFile("{$dir}/app.casc"));

        unlink("{$dir}/parts/b.casc");

        self::assertSame(['a' => 1], self::loadFile("{$dir}/app.casc"));
    }

    public function testAFileTheGlobDoesNotMatchLeavesTheEntryCached(): void
    {
        $dir = $this->globFixture();
        self::loadFile("{$dir}/app.casc");

        self::write("{$dir}/parts/notes.txt", 9500, 'not casc');
        self::loadFile("{$dir}/app.casc");

        self::assertSame([], $this->events);
    }

    public function testTheChangeNamesTheFileAddedWhereAGlobImportLooks(): void
    {
        $dir = $this->globFixture();
        self::loadFile("{$dir}/app.casc");

        self::write("{$dir}/parts/b.casc", 9500, "#@version = 1.0\nb = 2\n");
        self::loadFile("{$dir}/app.casc");

        self::assertCount(1, $this->events);
        self::assertSame(CacheEvent::FILE_CHANGED, $this->events[0]->type);
        self::assertSame(["{$dir}/parts/b.casc"], $this->events[0]->changed);
    }

    public function testABracePatternIsExpandedAgainTheSameWay(): void
    {
        $dir = $this->globFixture('parts/{a,b}.casc');
        self::assertSame(['a' => 1], self::loadFile("{$dir}/app.casc"));

        self::write("{$dir}/parts/b.casc", 9500, "#@version = 1.0\nb = 2\n");

        self::assertSame(['a' => 1, 'b' => 2], self::loadFile("{$dir}/app.casc"));
    }

    public function testAGuardDecisionRefreshesWhenItsVariableChanges(): void
    {
        // The Elixir original polls for this on a timer (`watch_env`);
        // here it is checked synchronously on every load, so it is exact.
        $path = $this->scratchDir('guard') . '/c.casc';
        self::write($path, 9600, "#@version = 1.0\n\${?COOPER_CACHE_GUARD_VAR} guarded = true\nkept = 1\n");
        $this->systemEnv('COOPER_CACHE_GUARD_VAR', null);

        self::assertSame(['kept' => 1], self::loadFile($path));
        self::assertSame([], $this->events);

        $this->systemEnv('COOPER_CACHE_GUARD_VAR', '1');
        self::assertSame(['guarded' => true, 'kept' => 1], self::loadFile($path));

        self::assertCount(1, $this->events);
        self::assertSame(CacheEvent::ENV_CHANGED, $this->events[0]->type);
        self::assertSame(['COOPER_CACHE_GUARD_VAR'], $this->events[0]->changed);
    }

    public function testTheCacheSeesTheFallbackCooperEnvAnImportPathReads(): void
    {
        // The env check compares the map `${...}` resolves against, so a
        // COOPER_ENV that came from APP_ENV is as current as a real one.
        $dir = $this->scratchDir('cooper_env');
        self::write("{$dir}/c.casc", 9620, "#@version = 1.0\nimport \"\${COOPER_ENV}.casc\"\n");
        self::write("{$dir}/staging.casc", 9620, "#@version = 1.0\nwhich = \"staging\"\n");
        self::write("{$dir}/prod.casc", 9620, "#@version = 1.0\nwhich = \"prod\"\n");

        $env = ['COOPER_ENV' => ''];
        self::assertSame(['which' => 'staging'], self::loadFile("{$dir}/c.casc", ['env' => $env + ['APP_ENV' => 'staging']]));
        self::assertSame(['which' => 'prod'], self::loadFile("{$dir}/c.casc", ['env' => $env + ['APP_ENV' => 'prod']]));

        self::assertCount(1, $this->events);
        self::assertSame(['COOPER_ENV'], $this->events[0]->changed);
    }

    public function testAChangeLandingBetweenTwoLoadsIsStillSeenSinceAHitNeverRebaselines(): void
    {
        // The baseline is the one the entry was populated with; a hit
        // compares against it and never replaces it. The reference once
        // re-baselined on every load, so a change landing between two
        // loads was never seen.
        $path = $this->scratchDir('baseline') . '/c.casc';
        self::write($path, 9650, "#@version = 1.0\n\${?COOPER_BASELINE_VAR} guarded = true\nkept = 1\n");

        self::assertSame(['kept' => 1], self::loadFile($path, ['env' => []]));
        self::assertSame(['kept' => 1], self::loadFile($path, ['env' => []]));
        self::assertSame(['guarded' => true, 'kept' => 1], self::loadFile($path, ['env' => ['COOPER_BASELINE_VAR' => 'live']]));

        self::assertCount(1, $this->events);
        self::assertSame(['COOPER_BASELINE_VAR'], $this->events[0]->changed);
    }

    public function testAnImportPathRefreshesWhenItsVariableChanges(): void
    {
        $dir = $this->scratchDir('import_env');
        self::write("{$dir}/dev.casc", 9700, "#@version = 1.0\nwhich = \"dev\"\n");
        self::write("{$dir}/prod.casc", 9700, "#@version = 1.0\nwhich = \"prod\"\n");
        self::write("{$dir}/main.casc", 9700, "#@version = 1.0\nimport \"\${COOPER_CACHE_STAGE:dev}.casc\"\n");

        self::assertSame(['which' => 'dev'], self::loadFile("{$dir}/main.casc"));
        self::assertSame(['which' => 'prod'], self::loadFile("{$dir}/main.casc", ['env' => ['COOPER_CACHE_STAGE' => 'prod']]));
        self::assertSame(['COOPER_CACHE_STAGE'], $this->events[0]->changed);
    }

    public function testTheCacheSeesADotEnvEditInDotenvDir(): void
    {
        // Each load reads the environment from the same `dotenvDir`, so a
        // guard's variable set in that `.env` is a changed shape.
        $dir = $this->scratchDir('dotenv_dir_cache');
        $path = "{$dir}/c.casc";
        self::write($path, 9900, "#@version = 1.0\n\${?COOPER_CACHE_DIR_GUARD} guarded = true\nkept = 1\n");
        $options = ['dotenv' => true, 'dotenvDir' => $dir, 'dotenvEnv' => null];
        self::assertSame(['kept' => 1], Cooper::loadFile($path, $options));

        file_put_contents("{$dir}/.env", "COOPER_CACHE_DIR_GUARD=1\n");

        self::assertSame(['guarded' => true, 'kept' => 1], Cooper::loadFile($path, $options));
        self::assertSame(CacheEvent::ENV_CHANGED, $this->events[0]->type);
        self::assertSame(['COOPER_CACHE_DIR_GUARD'], $this->events[0]->changed);
    }

    public function testAnUnrelatedVariableNeverInvalidates(): void
    {
        $path = $this->scratchDir('unrelated') . '/c.casc';
        self::write($path, 9800, "#@version = 1.0\n\${?COOPER_CACHE_TRACKED} x = 1\n");
        self::loadFile($path);

        $this->systemEnv('COOPER_CACHE_UNRELATED', 'irrelevant');
        self::loadFile($path);

        self::assertSame([], $this->events);
    }

    public function testEverySequenceOfDistinctMtimeWritesIsObserved(): void
    {
        // Property-style: whatever the sequence of distinct-mtime writes,
        // the very next load sees the newest content.
        mt_srand(99);
        $dir = $this->scratchDir('prop');
        for ($run = 0; $run < 25; $run++) {
            $path = "{$dir}/p{$run}.casc";
            $base = 100_000 + $run * 100;
            $count = mt_rand(1, 10);
            for ($i = 0; $i < $count; $i++) {
                $name = substr(str_shuffle('abcdefghijklmnopqrstuvwxyz'), 0, mt_rand(1, 8));
                self::write($path, $base + $i, "#@version = 1.0\nname = \"{$name}\"\n");
                self::assertSame(['name' => $name], self::loadFile($path));
            }
        }
    }
}

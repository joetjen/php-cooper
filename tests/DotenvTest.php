<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Tests;

use JOetjen\Cooper\Cooper;
use JOetjen\Cooper\CooperError;
use JOetjen\Cooper\Dotenv;
use JOetjen\Cooper\Tests\Support\CooperTestCase;

/**
 * `.env` layering -- ported from the Elixir suite's `dotenv_test.exs`.
 * These tests `chdir()` into a fixture and read the `.env` files there
 * through `dotenvHere()`/`loadHere()`, which pass that directory as
 * `dotenvDir` -- the default is the project root (the Composer root
 * package), which for a fixture is this repository, not the fixture.
 * The working directory is restored afterwards; real
 * environment variables are set with `putenv()` and removed again.
 * Assertions only look at `CDT_`/fixture-specific names, since the
 * machine's own environment is part of every result.
 */
final class DotenvTest extends CooperTestCase
{
    private string $originalCwd;

    /** @var list<string> */
    private array $putenvNames = [];

    /** @var array<string, string> the real environment as the test found it */
    private array $originalGetenv = [];

    /** @var array<array-key, mixed> */
    private array $originalEnvSuperglobal = [];

    /** @var array<array-key, mixed> */
    private array $originalServerSuperglobal = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalCwd = (string) getcwd();
        // `Dotenv::export()` writes the process environment; every test
        // gets back exactly the environment it started with, whatever it
        // exported.
        $this->originalGetenv = getenv();
        $this->originalEnvSuperglobal = $_ENV;
        $this->originalServerSuperglobal = $_SERVER;
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
        foreach (array_keys(array_diff_key(getenv(), $this->originalGetenv)) as $name) {
            putenv((string) $name);
        }
        foreach ($this->originalGetenv as $name => $value) {
            if (getenv($name) !== $value) {
                putenv("{$name}={$value}");
            }
        }
        $_ENV = $this->originalEnvSuperglobal;
        $_SERVER = $this->originalServerSuperglobal;
        foreach ($this->putenvNames as $name) {
            putenv($name);
        }
        $this->putenvNames = [];
        foreach ($this->removedSystemEnv as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
        $this->removedSystemEnv = [];
        parent::tearDown();
    }

    /**
     * `Dotenv::env()`, reading the `.env` files in the working directory.
     *
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    private static function dotenvHere(array $options = []): array
    {
        return Dotenv::env($options + ['dotenvDir' => (string) getcwd()]);
    }

    /**
     * `Cooper::loadString()`, reading the `.env` files in the working directory.
     *
     * @param array<string, mixed> $options
     * @return array<array-key, mixed>
     */
    private static function loadHere(string $source, array $options = []): array
    {
        return Cooper::loadString($source, $options + ['dotenvDir' => (string) getcwd()]);
    }

    // `dotenvDir`: `.env` files were read from the working directory
    // only, so an application started from anywhere but its own root
    // read none of them.

    public function testTheDefaultDirectoryIsTheComposerRootPackageEvenFromASubdirectory(): void
    {
        $root = (string) realpath(dirname(__DIR__));
        chdir(__DIR__ . '/fixtures');

        self::assertSame($root, Dotenv::projectRoot());
        // A relative file is found against the project root, not the
        // working directory.
        $env = Dotenv::env(['dotenvFiles' => ['tests/fixtures/dotenv/layering/.env.local']]);
        self::assertSame('from-local', $env['ONLY_LOCAL']);
    }

    public function testDotenvDirOverridesTheDefault(): void
    {
        $dir = $this->scratchDir('dotenv_dir');
        file_put_contents("{$dir}/.env", "CDT_DIR=from-dir\n");
        chdir(__DIR__);

        self::assertSame('from-dir', Dotenv::env(['dotenvDir' => $dir])['CDT_DIR']);
    }

    public function testARelativeDotenvFilesEntryResolvesAgainstDotenvDirAndAnAbsoluteOneIsUsedAsIs(): void
    {
        $dir = $this->scratchDir('dotenv_dir_files');
        $other = $this->scratchDir('dotenv_dir_other');
        file_put_contents("{$dir}/custom.env", "CDT_DIR=custom\n");
        file_put_contents("{$other}/abs.env", "CDT_OTHER=absolute\n");
        chdir(__DIR__);

        $env = Dotenv::env(['dotenvDir' => $dir, 'dotenvFiles' => ['custom.env', "{$other}/abs.env"]]);

        self::assertSame('custom', $env['CDT_DIR']);
        self::assertSame('absolute', $env['CDT_OTHER']);
    }

    public function testTheAppEnvDetectionReadsDotEnvFromDotenvDirToo(): void
    {
        $this->withoutSystemEnv('COOPER_ENV');
        $this->withoutSystemEnv('APP_ENV');
        $dir = $this->scratchDir('dotenv_dir_detect');
        file_put_contents("{$dir}/.env", "APP_ENV=qa\n");
        file_put_contents("{$dir}/.env.qa", "CDT_DIR=from-qa\n");
        chdir(__DIR__);

        self::assertSame('from-qa', Dotenv::env(['dotenvDir' => $dir])['CDT_DIR']);
    }

    public function testALoadHonoursDotenvDir(): void
    {
        $dir = $this->scratchDir('dotenv_dir_load');
        file_put_contents("{$dir}/.env", "CDT_DIR=from-dir\n");
        chdir(__DIR__);

        self::assertSame(['v' => 'from-dir'], Cooper::loadString("#@version = 1.0\nv = \${CDT_DIR}\n", ['dotenvDir' => $dir]));
    }

    // `.env` support is part of Cooper in every implementation. As an
    // optional package, the on-by-default file loading silently read
    // nothing in any application that had not added phpdotenv itself.

    public function testPhpdotenvIsARuntimeDependencyNotADevelopmentOne(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('vlucas/phpdotenv', $composer['require']);
        self::assertArrayNotHasKey('vlucas/phpdotenv', $composer['require-dev'] ?? []);
        self::assertArrayNotHasKey('vlucas/phpdotenv', $composer['suggest'] ?? []);
    }

    private function inFixture(string $name): void
    {
        chdir(self::fixture('dotenv', $name));
    }

    /**
     * @param array<string, string> $pairs
     */
    private function inOwnDotenv(array $pairs, string $file = '.env'): string
    {
        $dir = $this->scratchDir('dotenv');
        $lines = array_map(static fn (string $k, string $v): string => "{$k}={$v}", array_keys($pairs), $pairs);
        file_put_contents("{$dir}/{$file}", implode("\n", $lines) . "\n");
        chdir($dir);

        return $dir;
    }

    private function systemEnv(string $name, string $value): void
    {
        putenv("{$name}={$value}");
        $this->putenvNames[] = $name;
    }

    /** @var array<string, string|false> what `withoutSystemEnv()` took away, restored on tear-down */
    private array $removedSystemEnv = [];

    /**
     * Removes a real variable for this test only, restoring what the
     * machine had afterwards -- `COOPER_ENV` and `APP_ENV` may well be
     * set where the suite runs, and a fallback test must not see them.
     */
    private function withoutSystemEnv(string $name): void
    {
        if (!array_key_exists($name, $this->removedSystemEnv)) {
            $this->removedSystemEnv[$name] = getenv($name);
        }
        putenv($name);
    }

    public function testARealVariableComesThroughWhenNothingElseDefinesIt(): void
    {
        $this->systemEnv('CDT_FLOOR', 'from-system');
        $this->inFixture('empty');

        self::assertSame('from-system', self::dotenvHere()['CDT_FLOOR']);
    }

    public function testTheRealEnvironmentBeatsAFile(): void
    {
        $this->inOwnDotenv(['CDT_PRECEDENCE' => 'from-file']);
        $this->systemEnv('CDT_PRECEDENCE', 'from-system');

        self::assertSame('from-system', self::dotenvHere()['CDT_PRECEDENCE']);
    }

    public function testDotenvOverridePutsTheFilesOnTop(): void
    {
        $this->inOwnDotenv(['CDT_PRECEDENCE' => 'from-file']);
        $this->systemEnv('CDT_PRECEDENCE', 'from-system');

        self::assertSame('from-file', self::dotenvHere(['dotenvOverride' => true])['CDT_PRECEDENCE']);
    }

    public function testAnExplicitEnvOutranksEverything(): void
    {
        $this->inOwnDotenv(['CDT_PRECEDENCE' => 'from-file']);
        $this->systemEnv('CDT_PRECEDENCE', 'from-system');

        self::assertSame('from-override', self::dotenvHere(['env' => ['CDT_PRECEDENCE' => 'from-override']])['CDT_PRECEDENCE']);
    }

    public function testTheCurrentEnvironmentIsDetectedFromAppEnvInDotEnv(): void
    {
        // `APP_ENV` is COOPER_ENV's PHP fallback, the Elixir original's
        // `MIX_ENV`; a `.env` setting it names `.env.test`.
        $this->withoutSystemEnv('COOPER_ENV');
        $this->withoutSystemEnv('APP_ENV');
        $this->inFixture('auto_env');

        self::assertSame('from-detected-env', self::dotenvHere()['ONLY_ENV_TEST']);
    }

    public function testARealAppEnvWinsTheDetection(): void
    {
        $this->withoutSystemEnv('COOPER_ENV');
        $this->inFixture('layering');
        $this->systemEnv('APP_ENV', 'dev');

        self::assertSame('from-dev', self::dotenvHere()['ONLY_DEV']);
    }

    // Which `.env.<env>` file is read: the one COOPER_ENV names, the name
    // documents select `env/${COOPER_ENV}.casc` by -- so `.env.dev`,
    // `.env.staging`, `.env.test` and `.env.prod` in every Cooper. Named
    // by `APP_ENV` as it once was, Laravel's `APP_ENV=production` read
    // `.env.production` beside documents that read `prod`.

    /**
     * A scratch directory holding `$files` (name => contents), entered,
     * with the machine's own `COOPER_ENV` and `APP_ENV` taken away.
     *
     * @param array<string, string> $files
     */
    private function inEnvFiles(array $files): void
    {
        $this->withoutSystemEnv('COOPER_ENV');
        $this->withoutSystemEnv('APP_ENV');
        $dir = $this->scratchDir('dotenv_name');
        foreach ($files as $name => $contents) {
            file_put_contents("{$dir}/{$name}", $contents);
        }
        chdir($dir);
    }

    public function testTheEnvFileIsNamedByCooperEnv(): void
    {
        $this->inEnvFiles(['.env.staging' => "CDT_PICKED=staging\n"]);

        self::assertSame('staging', self::dotenvHere(['env' => ['COOPER_ENV' => 'staging']])['CDT_PICKED']);
    }

    public function testTheEnvFileIsNamedByTheSharedNameWhenFallingBackToAppEnv(): void
    {
        $this->inEnvFiles(['.env.prod' => "CDT_PICKED=prod\n", '.env.production' => "CDT_PICKED=production\n"]);

        self::assertSame('prod', self::dotenvHere(['env' => ['COOPER_ENV' => '', 'APP_ENV' => 'production']])['CDT_PICKED']);
    }

    public function testACooperEnvSetInTheBaseDotEnvNamesTheEnvFileToo(): void
    {
        $this->inEnvFiles(['.env' => "COOPER_ENV=staging\n", '.env.staging' => "CDT_PICKED=staging\n"]);

        self::assertSame('staging', self::dotenvHere()['CDT_PICKED']);
    }

    public function testAnExplicitDotenvEnvStillNamesTheEnvFile(): void
    {
        $this->inEnvFiles(['.env.qa' => "CDT_PICKED=qa\n"]);

        self::assertSame('qa', self::dotenvHere(['dotenvEnv' => 'qa', 'env' => ['COOPER_ENV' => 'prod']])['CDT_PICKED']);
    }

    public function testWithNeitherCooperEnvNorAppEnvSetTheDevFileIsRead(): void
    {
        $this->inEnvFiles(['.env.dev' => "CDT_PICKED=dev\n"]);

        self::assertSame('dev', self::dotenvHere()['CDT_PICKED']);
    }

    public function testACooperEnvSetInDotEnvLocalDoesNotNameTheEnvFileBeingReadAfterIt(): void
    {
        $this->inEnvFiles(['.env.local' => "COOPER_ENV=staging\n", '.env.staging' => "CDT_PICKED=staging\n"]);

        self::assertArrayNotHasKey('CDT_PICKED', self::dotenvHere());
    }

    public function testUnderDotenvOverrideTheBaseDotEnvOutranksTheRealCooperEnvInNamingItToo(): void
    {
        $this->inEnvFiles(['.env' => "COOPER_ENV=staging\n", '.env.staging' => "CDT_PICKED=staging\n"]);
        $this->systemEnv('COOPER_ENV', 'prod');

        self::assertSame('staging', self::dotenvHere(['dotenvOverride' => true])['CDT_PICKED']);
    }

    public function testTheFullLayerChainLaterWinning(): void
    {
        $this->systemEnv('ONLY_BASE', 'from-system');
        $this->inFixture('layering');

        $env = self::dotenvHere(['dotenvEnv' => 'dev']);
        $picked = array_intersect_key($env, array_flip(['ONLY_BASE', 'SHARED', 'DEV_AND_LOCAL', 'ONLY_LOCAL', 'ONLY_DEV']));
        ksort($picked);

        self::assertSame(
            [
                'DEV_AND_LOCAL' => 'from-local',
                'ONLY_BASE' => 'from-system',
                'ONLY_DEV' => 'from-dev',
                'ONLY_LOCAL' => 'from-local',
                'SHARED' => 'from-dev',
            ],
            $picked,
        );
    }

    public function testTheFullLayerChainUnderOverride(): void
    {
        $this->systemEnv('ONLY_BASE', 'from-system');
        $this->inFixture('layering');

        self::assertSame('from-base', self::dotenvHere(['dotenvEnv' => 'dev', 'dotenvOverride' => true])['ONLY_BASE']);
    }

    public function testAnExplicitEnvEntryWinsAndOthersFallThrough(): void
    {
        $this->inFixture('layering');
        $env = self::dotenvHere(['env' => ['SHARED' => 'from-explicit-override'], 'dotenvEnv' => 'dev']);

        self::assertSame('from-explicit-override', $env['SHARED']);
        self::assertSame('from-dev', $env['ONLY_DEV']);
    }

    public function testANullDotenvEnvReadsNoPerEnvironmentFile(): void
    {
        $this->inFixture('layering');
        $env = self::dotenvHere(['dotenvEnv' => null]);

        self::assertSame('from-base', $env['SHARED']);
        self::assertSame('from-local', $env['DEV_AND_LOCAL']);
        self::assertArrayNotHasKey('ONLY_DEV', $env);
    }

    public function testAnUnmatchedEnvironmentFileIsSimplyAbsent(): void
    {
        $this->inFixture('layering');
        $env = self::dotenvHere(['dotenvEnv' => 'prod']);

        self::assertArrayNotHasKey('ONLY_DEV', $env);
        self::assertSame('from-base', $env['SHARED']);
    }

    public function testMissingFilesAreNeverAnError(): void
    {
        $this->inFixture('empty');

        self::assertSame('1', self::dotenvHere(['env' => ['KEPT' => '1'], 'dotenvEnv' => 'dev'])['KEPT']);
    }

    public function testDotenvFalseDisablesOnlyTheFiles(): void
    {
        $this->systemEnv('CDT_FLOOR', 'from-system');
        $this->inFixture('layering');
        $env = self::dotenvHere(['dotenv' => false, 'env' => ['OVERRIDE' => 'value']]);

        self::assertSame('from-system', $env['CDT_FLOOR']);
        self::assertSame('value', $env['OVERRIDE']);
        self::assertArrayNotHasKey('ONLY_BASE', $env);
    }

    public function testDotenvFilesReplacesTheDefaultList(): void
    {
        $this->inFixture('layering');
        $env = self::dotenvHere(['dotenvFiles' => ['.env.local']]);

        self::assertSame('from-local', $env['DEV_AND_LOCAL']);
        self::assertSame('from-local', $env['ONLY_LOCAL']);
        self::assertArrayNotHasKey('ONLY_BASE', $env);
    }

    public function testAMalformedFileIsADotenvError(): void
    {
        $this->inFixture('malformed');

        try {
            self::dotenvHere();
            self::fail('expected an error');
        } catch (CooperError $e) {
            self::assertSame(CooperError::STAGE_DOTENV, $e->stage);
            self::assertStringContainsString('dotenv loading failed', $e->getMessage());
        }
    }

    public function testWiredIntoLoadString(): void
    {
        $this->inFixture('layering');
        $source = "#@version = 1.0\nshared = \${SHARED}\n";

        self::assertSame(['shared' => 'from-dev'], self::loadHere($source, ['dotenvEnv' => 'dev']));
        self::assertSame(['shared' => 'explicit'], self::loadHere($source, ['env' => ['SHARED' => 'explicit'], 'dotenvEnv' => 'dev']));
    }

    public function testPrecedenceHoldsForArbitraryLayers(): void
    {
        // Property-style (hand-rolled, per AGENTS.md): random overlapping
        // layers always resolve .env < .env.<env> < .env.local < real
        // environment < `env` option.
        mt_srand(1789);
        $keys = ['CDT_A', 'CDT_B', 'CDT_C', 'CDT_D', 'CDT_E', 'CDT_F'];
        $layer = static function () use ($keys): array {
            $layer = [];
            foreach ($keys as $key) {
                if (mt_rand(0, 2) === 0) {
                    $layer[$key] = substr(str_shuffle('abcdefghijklmnopqrstuvwxyz'), 0, mt_rand(1, 6));
                }
            }

            return $layer;
        };

        for ($run = 0; $run < 25; $run++) {
            [$base, $envFile, $local, $system, $override] = [$layer(), $layer(), $layer(), $layer(), $layer()];
            $dir = $this->scratchDir('dotenv_prop');
            foreach (['.env' => $base, '.env.proptest' => $envFile, '.env.local' => $local] as $file => $pairs) {
                file_put_contents("{$dir}/{$file}", implode("\n", array_map(static fn (string $k, string $v): string => "{$k}={$v}", array_keys($pairs), $pairs)) . "\n");
            }
            chdir($dir);
            foreach ($system as $name => $value) {
                $this->systemEnv($name, $value);
            }

            $expected = array_replace($base, $envFile, $local, $system, $override);
            $actual = self::dotenvHere(['env' => $override, 'dotenvEnv' => 'proptest']);
            ksort($expected);
            $actual = array_intersect_key($actual, array_flip($keys));
            ksort($actual);

            self::assertSame($expected, $actual);

            foreach ($this->putenvNames as $name) {
                putenv($name);
            }
            $this->putenvNames = [];
        }
    }

    // `Dotenv::export()`: a framework integration that replaces the
    // framework's own `.env` loader must leave the files' values where
    // code reading the process environment directly looks for them --
    // `$_ENV`, `$_SERVER`, `getenv()`. Only when asked: an ordinary load
    // never touches the process environment.

    /**
     * Whether `$name` is visible as `$value` in all three places code
     * reads the process environment from.
     */
    private static function assertInProcessEnvironment(string $name, string $value): void
    {
        self::assertSame($value, getenv($name), "getenv({$name})");
        self::assertSame($value, $_ENV[$name] ?? null, "\$_ENV[{$name}]");
        self::assertSame($value, $_SERVER[$name] ?? null, "\$_SERVER[{$name}]");
    }

    private static function assertNotInProcessEnvironment(string $name): void
    {
        self::assertFalse(getenv($name), "getenv({$name})");
        self::assertArrayNotHasKey($name, $_ENV);
        self::assertArrayNotHasKey($name, $_SERVER);
    }

    public function testAnOrdinaryLoadExportsNothing(): void
    {
        $this->inOwnDotenv(['CDT_EXPORT' => 'from-file']);

        self::loadHere("#@version = 1.0\nv = \${CDT_EXPORT}\n");
        self::dotenvHere();

        self::assertNotInProcessEnvironment('CDT_EXPORT');
    }

    public function testExportPutsTheFilesValuesIntoTheProcessEnvironment(): void
    {
        $this->inEnvFiles(['.env' => "CDT_EXPORT=from-base\nCDT_SHARED=from-base\n", '.env.local' => "CDT_SHARED=from-local\n"]);

        Dotenv::export(['dotenvDir' => (string) getcwd()]);

        self::assertInProcessEnvironment('CDT_EXPORT', 'from-base');
        self::assertInProcessEnvironment('CDT_SHARED', 'from-local');
    }

    public function testExportReturnsWhatItWrote(): void
    {
        $this->inEnvFiles(['.env' => "CDT_EXPORT=from-file\n"]);

        self::assertSame(
            ['CDT_EXPORT' => 'from-file', 'COOPER_ENV' => 'dev'],
            Dotenv::export(['dotenvDir' => (string) getcwd()]),
        );
    }

    public function testExportNeverOverwritesTheRealEnvironment(): void
    {
        $this->inOwnDotenv(['CDT_EXPORT' => 'from-file']);
        $this->systemEnv('CDT_EXPORT', 'from-system');

        $exported = Dotenv::export(['dotenvDir' => (string) getcwd()]);

        self::assertArrayNotHasKey('CDT_EXPORT', $exported);
        self::assertSame('from-system', getenv('CDT_EXPORT'));
    }

    public function testExportNeverOverwritesAVariableOnlyTheSuperglobalsHold(): void
    {
        // A web server's `SetEnv`/`fastcgi_param` can reach `$_SERVER`
        // without reaching `getenv()`; it is still the deployment's.
        $this->inOwnDotenv(['CDT_EXPORT' => 'from-file', 'CDT_EXPORT_ENV' => 'from-file']);
        $_SERVER['CDT_EXPORT'] = 'from-server';
        $_ENV['CDT_EXPORT_ENV'] = 'from-env';

        $exported = Dotenv::export(['dotenvDir' => (string) getcwd()]);

        self::assertArrayNotHasKey('CDT_EXPORT', $exported);
        self::assertArrayNotHasKey('CDT_EXPORT_ENV', $exported);
        self::assertSame('from-server', $_SERVER['CDT_EXPORT']);
        self::assertSame('from-env', $_ENV['CDT_EXPORT_ENV']);
        self::assertFalse(getenv('CDT_EXPORT'));
    }

    public function testExportDoesNotMistakeARequestHeaderForTheRealEnvironment(): void
    {
        // A request header `Foo: x` arrives as `$_SERVER['HTTP_FOO']`; a
        // client must not be able to keep a `.env` value from being exported.
        $this->inOwnDotenv(['HTTP_CDT_EXPORT' => 'from-file']);
        $_SERVER['HTTP_CDT_EXPORT'] = 'from-client';

        self::assertSame('from-file', Dotenv::export(['dotenvDir' => (string) getcwd()])['HTTP_CDT_EXPORT']);
        self::assertSame('from-file', getenv('HTTP_CDT_EXPORT'));
    }

    public function testExportUnderDotenvOverrideOverwritesTheRealEnvironment(): void
    {
        $this->inOwnDotenv(['CDT_EXPORT' => 'from-file']);
        $this->systemEnv('CDT_EXPORT', 'from-system');

        $exported = Dotenv::export(['dotenvDir' => (string) getcwd(), 'dotenvOverride' => true]);

        self::assertSame('from-file', $exported['CDT_EXPORT']);
        self::assertInProcessEnvironment('CDT_EXPORT', 'from-file');
    }

    public function testExportAlwaysExportsCooperEnv(): void
    {
        // The fallback a document reads `${COOPER_ENV}` by is exported
        // even though no file names it, so code outside CASC sees the
        // same environment name the configuration was loaded for.
        $this->inEnvFiles(['.env' => "APP_ENV=production\n"]);

        Dotenv::export(['dotenvDir' => (string) getcwd()]);

        self::assertInProcessEnvironment('COOPER_ENV', 'prod');
        self::assertInProcessEnvironment('APP_ENV', 'production');
    }

    public function testExportKeepsARealCooperEnv(): void
    {
        $this->inOwnDotenv(['COOPER_ENV' => 'staging']);
        $this->systemEnv('COOPER_ENV', 'prod');

        self::assertArrayNotHasKey('COOPER_ENV', Dotenv::export(['dotenvDir' => (string) getcwd()]));
        self::assertSame('prod', getenv('COOPER_ENV'));
    }

    public function testExportWithDotenvFilesTurnedOffExportsOnlyCooperEnv(): void
    {
        $this->inEnvFiles(['.env' => "CDT_EXPORT=from-file\n"]);

        self::assertSame(['COOPER_ENV' => 'dev'], Dotenv::export(['dotenvDir' => (string) getcwd(), 'dotenv' => false]));
        self::assertNotInProcessEnvironment('CDT_EXPORT');
    }

    public function testExportWritesTheValueAReferenceResolvesTo(): void
    {
        // `env` outranks the files for `${...}`; the exported value is
        // the same one, so the two never disagree.
        $this->inOwnDotenv(['CDT_EXPORT' => 'from-file']);

        Dotenv::export(['dotenvDir' => (string) getcwd(), 'env' => ['CDT_EXPORT' => 'from-option']]);

        self::assertInProcessEnvironment('CDT_EXPORT', 'from-option');
    }

    public function testExportDoesNotExportANameOnlyTheEnvOptionSets(): void
    {
        $this->inOwnDotenv(['CDT_EXPORT' => 'from-file']);

        Dotenv::export(['dotenvDir' => (string) getcwd(), 'env' => ['CDT_OPTION_ONLY' => 'x']]);

        self::assertNotInProcessEnvironment('CDT_OPTION_ONLY');
    }

    public function testExportIsIdempotent(): void
    {
        $this->inEnvFiles(['.env' => "CDT_EXPORT=from-file\n"]);
        $options = ['dotenvDir' => (string) getcwd()];

        Dotenv::export($options);
        $first = [getenv(), $_ENV, $_SERVER];
        $again = Dotenv::export($options);

        // What the first call wrote is now the real environment, so the
        // second finds nothing left to write -- and changes nothing.
        self::assertSame([], $again);
        self::assertSame($first, [getenv(), $_ENV, $_SERVER]);
    }

    public function testExportUnderDotenvOverrideIsIdempotentToo(): void
    {
        $this->inEnvFiles(['.env' => "CDT_EXPORT=from-file\n"]);
        $options = ['dotenvDir' => (string) getcwd(), 'dotenvOverride' => true];

        $once = Dotenv::export($options);
        $state = [getenv(), $_ENV, $_SERVER];

        self::assertSame($once, Dotenv::export($options));
        self::assertSame($state, [getenv(), $_ENV, $_SERVER]);
    }

    public function testALoadAfterAnExportResolvesTheSameValues(): void
    {
        $this->inEnvFiles(['.env' => "CDT_EXPORT=from-file\n"]);
        $source = "#@version = 1.0\nv = \${CDT_EXPORT}\ne = \${COOPER_ENV}\n";
        $before = self::loadHere($source);

        Dotenv::export(['dotenvDir' => (string) getcwd()]);

        self::assertSame($before, self::loadHere($source));
    }

    public function testExportRejectsMalformedOptionsLikeEveryOtherEntryPoint(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Dotenv::export(['exportEnv' => true]);
    }

    // `${COOPER_ENV}` (CASC.md §7.2): the one name a document reads the
    // current environment by in every implementation. PHP's host name for
    // it is `APP_ENV`, the convention Symfony and Laravel share.

    public function testASetCooperEnvWinsOverAppEnv(): void
    {
        $env = ['COOPER_ENV' => 'staging', 'APP_ENV' => 'prod'];

        self::assertSame(['v' => 'staging'], self::load('v = ${COOPER_ENV}', ['env' => $env]));
    }

    public function testAnEmptyCooperEnvFallsBackToAppEnv(): void
    {
        $env = ['COOPER_ENV' => '', 'APP_ENV' => 'prod'];

        self::assertSame(['v' => 'prod'], self::load('v = ${COOPER_ENV}', ['env' => $env]));
    }

    public function testAnUnsetCooperEnvFallsBackToAppEnv(): void
    {
        self::assertSame('prod', Dotenv::withCooperEnv(['APP_ENV' => 'prod'])['COOPER_ENV']);
    }

    public function testWithNeitherSetItIsDev(): void
    {
        self::assertSame('dev', Dotenv::withCooperEnv([])['COOPER_ENV']);
        self::assertSame('dev', Dotenv::withCooperEnv(['COOPER_ENV' => '', 'APP_ENV' => ''])['COOPER_ENV']);
    }

    // The environments are named dev, staging, test and prod in every
    // Cooper; the APP_ENV fallback is mapped onto them through one table,
    // the same everywhere (CASC.md §7.2), so Laravel's `local`, `testing`
    // and `production` read the same `env/dev.casc` as every other host.

    public function testAnAppEnvOfDevelopmentFallsBackAsDev(): void
    {
        self::assertSame('dev', Dotenv::withCooperEnv(['APP_ENV' => 'development'])['COOPER_ENV']);
    }

    public function testAnAppEnvOfLocalFallsBackAsDev(): void
    {
        self::assertSame('dev', Dotenv::withCooperEnv(['APP_ENV' => 'local'])['COOPER_ENV']);
    }

    public function testAnAppEnvOfTestingFallsBackAsTest(): void
    {
        self::assertSame('test', Dotenv::withCooperEnv(['APP_ENV' => 'testing'])['COOPER_ENV']);
    }

    public function testAnAppEnvOfProductionFallsBackAsProd(): void
    {
        self::assertSame('prod', Dotenv::withCooperEnv(['APP_ENV' => 'production'])['COOPER_ENV']);
    }

    public function testTheSharedNamesAndAnyOtherAppEnvFallBackUnchanged(): void
    {
        foreach (['dev', 'staging', 'test', 'prod', 'qa'] as $name) {
            self::assertSame($name, Dotenv::withCooperEnv(['APP_ENV' => $name])['COOPER_ENV']);
        }
    }

    public function testOnlyTheExactLowercaseNamesAreMapped(): void
    {
        self::assertSame('Production', Dotenv::withCooperEnv(['APP_ENV' => 'Production'])['COOPER_ENV']);
        self::assertSame('LOCAL', Dotenv::withCooperEnv(['APP_ENV' => 'LOCAL'])['COOPER_ENV']);
        self::assertSame(' testing ', Dotenv::withCooperEnv(['APP_ENV' => ' testing '])['COOPER_ENV']);
    }

    public function testARealCooperEnvIsNeverMapped(): void
    {
        foreach (['production', 'local', 'testing', 'development', ' Stage '] as $name) {
            self::assertSame($name, Dotenv::withCooperEnv(['COOPER_ENV' => $name, 'APP_ENV' => 'qa'])['COOPER_ENV']);
        }
    }

    public function testTheMappingLeavesAppEnvItselfAlone(): void
    {
        self::assertSame('local', Dotenv::withCooperEnv(['APP_ENV' => 'local'])['APP_ENV']);
    }

    public function testALoadReadsAMappedAppEnvFromADotEnvFile(): void
    {
        $this->withoutSystemEnv('COOPER_ENV');
        $this->withoutSystemEnv('APP_ENV');
        $this->inOwnDotenv(['APP_ENV' => 'production']);

        self::assertSame(['v' => 'prod'], self::loadHere("#@version = 1.0\nv = \${COOPER_ENV}\n"));
    }

    public function testTheFallbackReadsAppEnvFromTheSameLayersAsEveryOtherName(): void
    {
        // `.env` setting APP_ENV is enough: the fallback looks at the
        // finished layering, not at the real environment alone.
        $this->withoutSystemEnv('COOPER_ENV');
        $this->withoutSystemEnv('APP_ENV');
        $this->inOwnDotenv(['APP_ENV' => 'from-dotenv']);

        self::assertSame('from-dotenv', self::dotenvHere()['COOPER_ENV']);
    }

    public function testARealCooperEnvWinsOverAFileSettingAppEnv(): void
    {
        $this->inOwnDotenv(['APP_ENV' => 'from-dotenv']);
        $this->systemEnv('COOPER_ENV', 'from-system');

        self::assertSame('from-system', self::dotenvHere()['COOPER_ENV']);
    }

    public function testCooperEnvIsSetWithDotenvFilesTurnedOff(): void
    {
        $this->withoutSystemEnv('COOPER_ENV');
        $this->withoutSystemEnv('APP_ENV');

        self::assertSame('dev', self::dotenvHere(['dotenv' => false])['COOPER_ENV']);
    }

    public function testTheCooperEnvRuleHoldsForArbitraryValues(): void
    {
        // Property-style (hand-rolled, per AGENTS.md): absent, empty, and
        // arbitrary values of both names, the rule always picks a present
        // COOPER_ENV, else a present APP_ENV mapped through the shared
        // table, else "dev" -- and nothing else in the map changes.
        mt_srand(168);
        $choices = [null, '', 'dev', 'test', 'prod', 'Staging', ' padded ', 'a-b_c.1', 'local', 'testing', 'production', 'development'];
        $shared = ['development' => 'dev', 'local' => 'dev', 'testing' => 'test', 'production' => 'prod'];

        for ($i = 0; $i < 300; $i++) {
            $env = ['OTHER' => (string) mt_rand()];
            $cooper = $choices[mt_rand(0, count($choices) - 1)];
            $app = $choices[mt_rand(0, count($choices) - 1)];
            if ($cooper !== null) {
                $env['COOPER_ENV'] = $cooper;
            }
            if ($app !== null) {
                $env['APP_ENV'] = $app;
            }

            $expected = ($cooper ?? '') !== '' ? $cooper : (($app ?? '') !== '' ? ($shared[$app] ?? $app) : 'dev');
            $actual = Dotenv::withCooperEnv($env);

            self::assertSame($expected, $actual['COOPER_ENV']);
            unset($actual['COOPER_ENV'], $env['COOPER_ENV']);
            self::assertSame($env, $actual);
        }
    }
}

<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Tests;

use JOetjen\Cooper\Cooper;
use JOetjen\Cooper\Cache;
use JOetjen\Cooper\CooperError;
use JOetjen\Cooper\LoadTrace;
use JOetjen\Cooper\Tests\Support\CooperTestCase;
use JOetjen\Cooper\Value\CooperSecret;
use JOetjen\Cooper\Value\CooperTuple;

/**
 * Every CASC.md worked example loads into the documented result, with
 * secrets wrapped and tuples real tuples -- ported from the Elixir
 * suite's `cooper_test.exs`.
 */
final class CooperTest extends CooperTestCase
{
    public function testDottedPathNestedBlockEquivalence(): void
    {
        self::assertSame(['foo' => ['bar' => ['baz' => 'dronf']]], self::load('foo.bar.baz "dronf"'));
    }

    public function testConfigReferenceWorkedExample(): void
    {
        self::assertSame(
            [
                'server' => ['host' => 'api.internal', 'port' => 8080],
                'health_check' => ['url' => 'http://api.internal:8080/health'],
                'admin_email' => 'ops@example.com',
            ],
            self::load(<<<'CASC'
                server.host = "api.internal"
                server.port = 8080
                health_check.url = "http://%{server.host}:%{server.port}/health"
                admin_email = %{contact.admin:"ops@example.com"}
                CASC),
        );
    }

    public function testParallelLoopWorkedExample(): void
    {
        self::assertSame(
            ['endpoints' => [
                'domain-0' => ['url' => 'https://us-east.example.com:8443'],
                'domain-1' => ['url' => 'https://eu-west.example.com:8444'],
            ]],
            self::load(<<<'CASC'
                @domains = ["us-east.example.com", "eu-west.example.com"]
                @ports = [8443, 8444]

                for @idx, @domain in @{domains}, @port in @{ports} as endpoints."domain-@{idx}" {
                  url = "https://@{domain}:@{port}"
                }
                CASC),
        );
    }

    public function testMergeWorkedExample(): void
    {
        self::assertSame(
            ['server' => ['port' => 9090], 'tags' => ['a', 'c', 'd'], 'feature' => []],
            self::load(<<<'CASC'
                server { host = "0.0.0.0", port = 8080 }
                tags = ["a", "b", "c"]
                feature.legacy_mode = true

                ~server { port = 9090 }
                +tags = ["d"]
                -tags = ["b"]
                -feature.legacy_mode
                CASC),
        );
    }

    public function testTupleLiteralIsARealTuple(): void
    {
        $result = self::load('office_location = (52.5200, 13.4050)');

        self::assertInstanceOf(CooperTuple::class, $result['office_location']);
        self::assertSame([52.52, 13.405], $result['office_location']->items);
    }

    public function testTupleSurvivesResolution(): void
    {
        $result = self::load("@x = 1\nloc = (@{x}, 2)");

        self::assertInstanceOf(CooperTuple::class, $result['loc']);
        self::assertSame([1, 2], $result['loc']->items);
    }

    public function testSecretLeafIsWrapped(): void
    {
        $result = self::load('*password = "hunter2"');

        self::assertInstanceOf(CooperSecret::class, $result['password']);
        self::assertSame('hunter2', $result['password']->reveal());
        self::assertSame('[~~REDACTED~~]', (string) $result['password']);
    }

    public function testSecretFromEachPrefixPosition(): void
    {
        foreach (['*password = "x"' => ['password'], 'db.*password = "x"' => ['db', 'password'], '*db.password = "x"' => ['db', 'password']] as $source => $path) {
            $value = self::load($source);
            foreach ($path as $segment) {
                $value = $value[$segment];
            }
            self::assertInstanceOf(CooperSecret::class, $value, $source);
            self::assertSame('x', $value->reveal());
        }
    }

    public function testNonSecretSiblingUnderSecretParentIsNotWrapped(): void
    {
        $result = self::load('db { *password = "x", host = "localhost" }');

        self::assertInstanceOf(CooperSecret::class, $result['db']['password']);
        self::assertSame('localhost', $result['db']['host']);
    }

    public function testLaterPlainWriteOverSecretPathIsNoLongerSecret(): void
    {
        self::assertSame(['password' => 'y'], self::load("*password = \"x\"\npassword = \"y\""));
    }

    public function testConfigReferenceCopyingASecretStaysWrapped(): void
    {
        $result = self::load(<<<'CASC'
            database { *password = "hunter2", host = "db.internal" }
            connection_string = "postgres://%{database.host}?pw=%{database.password}"
            CASC);

        $connection = $result['connection_string'];
        self::assertInstanceOf(CooperSecret::class, $connection);
        self::assertSame('postgres://db.internal?pw=[~~REDACTED~~]', (string) $connection);
        self::assertSame('postgres://db.internal?pw=hunter2', $connection->reveal());
    }

    public function testLoadFileResolvesImportsRelativeToTheFile(): void
    {
        $result = Cooper::loadFile(self::fixture('imports', 'main.casc'), ['cache' => false, 'dotenv' => false]);

        self::assertSame(['host' => '0.0.0.0', 'port' => 9090], $result['server']);
        self::assertSame(['name' => 'shared'], $result['app']);
    }

    public function testLoadFileOfANonexistentFileIsAnImportError(): void
    {
        try {
            Cooper::loadFile('/does/not/exist/nope.casc');
            self::fail('expected an error');
        } catch (CooperError $e) {
            self::assertSame(CooperError::STAGE_IMPORT, $e->stage);
            self::assertStringContainsString('nope.casc', $e->getMessage());
        }
    }

    public function testUnregisteredTagFailsNamingIt(): void
    {
        $error = self::loadError('v = !uuid("x")');

        self::assertSame(CooperError::STAGE_RESOLVE, $error->stage);
        self::assertStringContainsString('uuid', $error->getMessage());
    }

    public function testRegisteredTagResolverAndImportSchemeWorkTogether(): void
    {
        $options = [
            'tags' => ['shout' => static fn (string $arg): string => strtoupper($arg) . '!'],
            'resolvers' => ['echo' => static fn (string $payload): string => $payload],
            'importSchemes' => ['mem' => static fn (string $rest): string => "#@version = 1.0\nimported = \"{$rest}\"\n"],
        ];

        self::assertSame(
            ['imported' => 'base', 'a' => 'HI!', 'b' => 'hello'],
            self::load("import \"mem://base\"\na = !shout(\"hi\")\nb = !{echo:hello}", $options),
        );
    }

    public function testUnknownOptionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown option(s): resolver');

        Cooper::loadString(self::HEADER, ['resolver' => []]);
    }

    public function testMistypedOptionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('option "env" must be an array of name => string');

        Cooper::loadString(self::HEADER, ['env' => ['PORT' => 8080]]);
    }

    public function testErrorsCarryTheSourceLocation(): void
    {
        $error = self::loadError("a = 1\nb = = 2");

        self::assertSame(CooperError::STAGE_PARSER, $error->stage);
        self::assertSame(3, $error->sourceLine);
        self::assertSame(5, $error->sourceColumn);
        self::assertStringContainsString('line 3, column 5', $error->getMessage());
    }

    // `loadFileTraced()`: the same load, together with what it depended
    // on -- for a caller that keeps the result somewhere `Cache` cannot
    // see (a compiled file, a shared store) and must know when it is stale.

    private function tracedFixture(): string
    {
        $dir = $this->scratchDir('traced');
        mkdir("{$dir}/inc");
        file_put_contents("{$dir}/main.casc", "#@version = 1.0\nimport \"inc/\${TRACE_PART}.casc\"\n\${?TRACE_GUARD} guarded = true\nhost = \${TRACE_HOST}\nmissing = \${TRACE_MISSING:fallback}\n");
        file_put_contents("{$dir}/inc/db.casc", "#@version = 1.0\ndb = \"on\"\n");

        return $dir;
    }

    public function testATracedLoadGivesTheSameConfigAsLoadFile(): void
    {
        $dir = $this->tracedFixture();
        $options = ['dotenv' => false, 'env' => ['TRACE_PART' => 'db', 'TRACE_HOST' => 'h']];

        $trace = Cooper::loadFileTraced("{$dir}/main.casc", $options);

        self::assertInstanceOf(LoadTrace::class, $trace);
        self::assertSame(Cooper::loadFile("{$dir}/main.casc", $options), $trace->config);
    }

    public function testATracedLoadListsEveryFileItReadImportsIncluded(): void
    {
        $dir = $this->tracedFixture();

        $trace = Cooper::loadFileTraced("{$dir}/main.casc", ['dotenv' => false, 'cache' => false, 'env' => ['TRACE_PART' => 'db', 'TRACE_HOST' => 'h']]);

        $files = $trace->files;
        sort($files);
        self::assertSame(["{$dir}/inc/db.casc", "{$dir}/main.casc"], $files);
    }

    public function testATracedLoadNamesEveryVariableItReadWithTheValueItSaw(): void
    {
        // A guard and an import path shape the tree rather than a value
        // in it, and an unset name was still read: all three count.
        $dir = $this->tracedFixture();

        $trace = Cooper::loadFileTraced("{$dir}/main.casc", ['dotenv' => false, 'env' => ['TRACE_PART' => 'db', 'TRACE_HOST' => 'h']]);

        $env = $trace->env;
        ksort($env);
        self::assertSame(
            ['TRACE_GUARD' => null, 'TRACE_HOST' => 'h', 'TRACE_MISSING' => null, 'TRACE_PART' => 'db'],
            array_intersect_key($env, array_flip(['TRACE_GUARD', 'TRACE_HOST', 'TRACE_MISSING', 'TRACE_PART'])),
        );
    }

    public function testACachedTracedLoadStillReportsWhatTheTreeDependedOn(): void
    {
        $dir = $this->tracedFixture();
        $options = ['dotenv' => false, 'env' => ['TRACE_PART' => 'db', 'TRACE_HOST' => 'h']];
        Cache::clear();

        Cooper::loadFile("{$dir}/main.casc", $options);
        $trace = Cooper::loadFileTraced("{$dir}/main.casc", $options);

        self::assertContains("{$dir}/inc/db.casc", $trace->files);
        self::assertArrayHasKey('TRACE_GUARD', $trace->env);
        Cache::clear();
    }

    public function testATracedLoadRecordsEachImportsExpansion(): void
    {
        // A file added where a glob looks is in no list of files read:
        // only the pattern, expanded again, can show it.
        $dir = $this->tracedFixture();

        $trace = Cooper::loadFileTraced("{$dir}/main.casc", ['dotenv' => false, 'cache' => false, 'env' => ['TRACE_PART' => 'db', 'TRACE_HOST' => 'h']]);

        self::assertCount(1, $trace->globs);
        self::assertSame($dir, $trace->globs[0]->root);
        self::assertSame('inc/db.casc', $trace->globs[0]->pattern);
        self::assertSame(["{$dir}/inc/db.casc"], $trace->globs[0]->files);
    }

    public function testARecordedImportExpansionSeesAFileAddedWhereItLooks(): void
    {
        $dir = $this->scratchDir('traced_glob');
        mkdir("{$dir}/parts");
        file_put_contents("{$dir}/app.casc", "#@version = 1.0\nimport \"parts/*.casc\"\n");
        file_put_contents("{$dir}/parts/a.casc", "#@version = 1.0\na = 1\n");
        $glob = Cooper::loadFileTraced("{$dir}/app.casc", ['dotenv' => false, 'cache' => false])->globs[0];
        self::assertFalse($glob->changed());

        file_put_contents("{$dir}/parts/b.casc", "#@version = 1.0\nb = 2\n");

        self::assertTrue($glob->changed());
        self::assertSame(["{$dir}/parts/a.casc", "{$dir}/parts/b.casc"], $glob->expand());
    }

    public function testATracedLoadReportsTheCooperEnvItResolvedAgainst(): void
    {
        $dir = $this->scratchDir('traced_env');
        file_put_contents("{$dir}/c.casc", "#@version = 1.0\nwhich = \${COOPER_ENV}\n");

        $trace = Cooper::loadFileTraced("{$dir}/c.casc", ['dotenv' => false, 'env' => ['COOPER_ENV' => '', 'APP_ENV' => 'qa']]);

        self::assertSame(['which' => 'qa'], $trace->config);
        self::assertSame('qa', $trace->env['COOPER_ENV']);
    }
}

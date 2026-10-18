<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Tests;

use JOetjen\Cooper\Cooper;
use JOetjen\Cooper\CooperError;
use JOetjen\Cooper\Pipeline\Grammar;
use JOetjen\Cooper\Pipeline\Native;
use JOetjen\Cooper\Tests\Support\CooperTestCase;
use JOetjen\Cooper\Value\CooperAtom;
use JOetjen\Cooper\Value\CooperBytes;
use JOetjen\Cooper\Value\CooperDuration;
use JOetjen\Cooper\Value\CooperSecret;

/**
 * The specific CASC.md corners not covered incidentally elsewhere --
 * ported from the Elixir suite's `spec_coverage_test.exs`. See
 * `tests/SPEC_COVERAGE.md` for the full section-by-section table.
 */
final class SpecCoverageTest extends CooperTestCase
{
    // CASC.md §4.1. Once the corpus's case `014-underscore-names`, now
    // the Cooper Conformance Suite's; kept here so this repo's own suite
    // still covers it.
    public function testANameMayBeginWithAnUnderscoreAsAKeyAVariableOrAnAtom(): void
    {
        self::assertEquals(
            [
                '__foo__' => 2,
                'text' => 'x-2',
                'sentry' => CooperAtom::of('__sentry__'),
                'colon' => CooperAtom::of('__sentry__'),
                'private' => CooperAtom::of('_private'),
            ],
            self::load(<<<'CASC'
                @__foo__ = 2
                __foo__ = @{__foo__}
                text = "x-@{__foo__}"
                sentry = __sentry__
                colon = :__sentry__
                private = _private
                CASC),
        );
    }

    public function testTheGuardWorkedExampleVerbatim(): void
    {
        self::assertSame(
            ['region' => 'eu-west', 'max_retries' => 3, 'allowed_hosts' => ['localhost']],
            self::load(<<<'CASC'
                region = ${REGION}
                max_retries = !int(${MAX_RETRIES:3})
                ${?FEATURE_FLAG}
                enabled = true
                allowed_hosts = ${ALLOWED_HOSTS[]:["localhost"]}
                CASC, ['env' => ['REGION' => 'eu-west', 'FEATURE_FLAG' => '']]),
        );
    }

    public function testAGuardedStatementRunsWhenTheVariableIsSet(): void
    {
        self::assertSame(['enabled' => true], self::load("\${?FEATURE_FLAG}\nenabled = true", ['env' => ['FEATURE_FLAG' => '1']]));
        self::assertSame([], self::load("\${?FEATURE_FLAG}\nenabled = true", ['env' => ['FEATURE_FLAG' => '']]));
    }

    public function testAGuardCoversOnlyTheNextStatement(): void
    {
        self::assertSame(['kept' => 1], self::load("\${?COOPER_TEST_MISSING}\nskipped = true\nkept = 1"));
    }

    public function testASkippedStatementIsNeverEvaluated(): void
    {
        self::assertSame(['kept' => 1], self::load("\${?COOPER_TEST_MISSING}\nv = !nonexistent_tag(\"x\")\nkept = 1"));
        self::assertSame(['kept' => 1], self::load("\${?COOPER_TEST_MISSING}\nv = 999.999.999.999\nkept = 1"));
    }

    public function testReplaceAndSecretCombined(): void
    {
        $tree = Native::convert(Grammar::run(self::HEADER . "database { host = \"old.internal\", password = \"old-pw\" }\n~*database { host = \"db.internal\", password = \"hunter2\" }", ['env' => []]));

        self::assertEquals(
            ['database' => ['host' => new CooperSecret('db.internal'), 'password' => new CooperSecret('hunter2')]],
            $tree,
        );
    }

    public function testTheDisabledFormOfTheSameStatementProducesNothing(): void
    {
        self::assertSame(
            ['database' => ['host' => 'old.internal']],
            self::load("database { host = \"old.internal\" }\n#~*database { host = \"db.internal\", password = \"hunter2\" }"),
        );
    }

    public function testAMissingVersionHeader(): void
    {
        foreach (['', "foo = 1\n#@version = 1.0\n"] as $source) {
            try {
                Cooper::loadString($source, ['dotenv' => false]);
                self::fail('expected an error for ' . json_encode($source));
            } catch (CooperError $e) {
                self::assertSame(CooperError::STAGE_PARSER, $e->stage);
            }
        }
    }

    public function testTripleQuotedStringsDoNotInterpolate(): void
    {
        self::assertSame(['motd' => "hello @{name}\n"], self::load("motd = \"\"\"\n    hello @{name}\n    \"\"\""));
    }

    public function testDurationUnitTable(): void
    {
        $units = ['1ns' => 1, '1us' => 1_000, '1µs' => 1_000, '1ms' => 1_000_000, '1s' => 1_000_000_000, '1m' => 60_000_000_000, '1h' => 3_600_000_000_000, '1d' => 86_400_000_000_000, '1h30m' => 5_400_000_000_000];
        foreach ($units as $literal => $nanoseconds) {
            self::assertEquals(['v' => new CooperDuration($nanoseconds)], self::load("v = {$literal}"), $literal);
        }
    }

    public function testByteSizeUnitTable(): void
    {
        $units = [
            '1B' => 1, '1kB' => 1_000, '1MB' => 1_000_000, '1GB' => 1_000_000_000, '1TB' => 1_000_000_000_000, '1PB' => 1_000_000_000_000_000,
            '1KiB' => 1024, '1MiB' => 1024 ** 2, '1GiB' => 1024 ** 3, '1TiB' => 1024 ** 4, '1PiB' => 1024 ** 5,
        ];
        foreach ($units as $literal => $bytes) {
            self::assertEquals(['v' => new CooperBytes($bytes)], self::load("v = {$literal}"), $literal);
        }
    }
}

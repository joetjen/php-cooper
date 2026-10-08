<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Tests\Pipeline;

use JOetjen\Cooper\CooperError;
use JOetjen\Cooper\Node\ConfigRef;
use JOetjen\Cooper\Node\Op;
use JOetjen\Cooper\Tests\Support\CooperTestCase;

/**
 * CASC.md §5.5's loops -- ported from the Elixir suite's
 * `loop_test.exs`, plus the places this port substitutes bindings the
 * Elixir original does not.
 */
final class LoopTest extends CooperTestCase
{
    public function testParallelIterationWorkedExample(): void
    {
        self::assertSame(
            ['endpoints' => [
                'domain-0' => ['url' => 'https://us-east.example.com:8443'],
                'domain-1' => ['url' => 'https://eu-west.example.com:8444'],
            ]],
            self::merged(<<<'CASC'
                @domains = ["us-east.example.com", "eu-west.example.com"]
                @ports = [8443, 8444]

                for @idx, @domain in @{domains}, @port in @{ports} as endpoints."domain-@{idx}" {
                  url = "https://@{domain}:@{port}"
                }
                CASC),
        );
    }

    public function testMismatchedLengthsNameEachList(): void
    {
        $error = self::mergedError(<<<'CASC'
            @domains = ["us-east.example.com", "eu-west.example.com"]
            @ports = [8443]

            for @domain in @{domains}, @port in @{ports} as endpoints."@{domain}" {
              url = "@{port}"
            }
            CASC);

        self::assertSame(CooperError::STAGE_LOOP, $error->stage);
        self::assertSame("for loop's bound lists have mismatched lengths: @{domain} (2), @{port} (1)", $error->getMessage());
    }

    public function testAnIndexOnlyLoopIsAnError(): void
    {
        $error = self::mergedError("for @idx as dest.\"@{idx}\" {\n  k = 1\n}");

        self::assertSame(CooperError::STAGE_LOOP, $error->stage);
        self::assertStringContainsString('at least one element binding', $error->getMessage());
    }

    public function testTwoIndexBindingsAreAnError(): void
    {
        self::assertStringContainsString('at most one index binding', self::mergedError("for @a, @x in [1], @b as d.\"@{x}\" { k = 1 }")->getMessage());
    }

    public function testAnIndexBindingPlacedLastIsNotMistakenForAs(): void
    {
        self::assertSame(
            ['dest' => ['a' => ['position' => '0'], 'b' => ['position' => '1']]],
            self::merged("@names = [\"a\", \"b\"]\n\nfor @name in @{names}, @idx as dest.\"@{name}\" {\n  position = \"@{idx}\"\n}"),
        );
    }

    public function testFromTemplateGivesEachIterationALazyBase(): void
    {
        $entries = self::ops(<<<'CASC'
            defaults.replica {
              cpu = 1
              memory_mb = 512
            }

            @instances = ["a", "b", "c"]
            for @instance in @{instances} from defaults.replica as replicas."@{instance}" {
              cpu = 2
            }
            CASC);

        $bases = [];
        $overrides = [];
        foreach ($entries as $entry) {
            if ($entry instanceof Op && $entry->value instanceof ConfigRef && count($entry->path) === 2) {
                $bases[$entry->path[1]] = $entry->value;
            } elseif ($entry instanceof Op && count($entry->path) === 3 && $entry->path[0] === 'replicas') {
                $overrides[$entry->path[1]] = $entry->value;
            }
        }

        self::assertEquals(array_fill_keys(['a', 'b', 'c'], new ConfigRef(['defaults', 'replica'])), $bases);
        self::assertSame(['a' => 2, 'b' => 2, 'c' => 2], $overrides);
    }

    public function testFromTemplateResolvesEndToEnd(): void
    {
        self::assertSame(
            [
                'defaults' => ['replica' => ['cpu' => 1, 'memory_mb' => 512]],
                'replicas' => [
                    'a' => ['cpu' => 2, 'memory_mb' => 512],
                    'b' => ['cpu' => 2, 'memory_mb' => 512],
                ],
            ],
            self::load("defaults.replica { cpu = 1, memory_mb = 512 }\n\n@instances = [\"a\", \"b\"]\nfor @instance in @{instances} from defaults.replica as replicas.\"@{instance}\" {\n  cpu = 2\n}"),
        );
    }

    public function testALiteralListIterates(): void
    {
        self::assertSame(['dest' => ['p' => ['k' => 1], 'q' => ['k' => 1]]], self::merged("for @x in [\"p\", \"q\"] as dest.\"@{x}\" {\n  k = 1\n}"));
    }

    public function testAnEmptyListGeneratesNothing(): void
    {
        self::assertSame([], self::merged("for @x in [] as dest.\"@{x}\" {\n  k = 1\n}"));
    }

    public function testIterableErrors(): void
    {
        self::assertStringContainsString('undefined variable "@{missing}"', self::mergedError("for @x in @{missing} as d.\"@{x}\" { k = 1 }")->getMessage());
        self::assertStringContainsString('is not a list', self::mergedError("@s = \"x\"\nfor @x in @{s} as d.\"@{x}\" { k = 1 }")->getMessage());
        self::assertStringContainsString('config reference', self::mergedError("for @x in %{some.path} as d.\"@{x}\" { k = 1 }")->getMessage());
        self::assertStringContainsString('list literal or a variable', self::mergedError("for @x in \"abc\" as d.\"@{x}\" { k = 1 }")->getMessage());
    }

    public function testABindingSubstitutesIntoABodyKey(): void
    {
        self::assertSame(
            ['out' => ['a' => 'value-a', 'b' => 'value-b']],
            self::merged("@ids = [\"a\", \"b\"]\n\nfor @id in @{ids} as out {\n  \"@{id}\" = \"value-@{id}\"\n}"),
        );
    }

    public function testABindingSubstitutesIntoAConfigReferencePath(): void
    {
        // CASC.md §7.2 names `%{tokens."supervisor-@{id}"}`; the Elixir
        // original leaves `@{id}` unsubstituted there, so it fails as an
        // undefined variable once the loop is gone.
        self::assertSame(
            ['out' => ['a' => ['v' => 5]], 't' => ['a' => 5]],
            self::load("@l = [\"a\"]\nfor @i in @{l} as out.\"@{i}\" { v = %{t.\"@{i}\"} }\nt.a = 5"),
        );
    }

    public function testABindingSubstitutesIntoADefault(): void
    {
        self::assertSame(['out' => ['a' => ['v' => 'fallback-a']]], self::load("for @i in [\"a\"] as out.\"@{i}\" { v = @{missing:\"fallback-@{i}\"} }"));
    }

    public function testAKeyMayMixBindingsWithOuterVariables(): void
    {
        // The reference once rejected any key reference that was not one
        // of the loop's own bindings; the rest resolves at merge time.
        self::assertSame(['out' => ['prod-a' => ['v' => 1]]], self::load("@stage = \"prod\"\nfor @i in [\"a\"] as out.\"@{stage}-@{i}\" { v = 1 }"));
    }

    public function testAReplaceInsideTheBodyClearsUnderTheDestination(): void
    {
        // The reference once cleared the top-level `server` instead.
        self::assertSame(
            ['server' => ['x' => 1], 'out' => ['server' => ['a' => 1]]],
            self::merged("server.x = 1\n@l = [1]\nfor @i in @{l} as out { ~server { a = 1 } }"),
        );
    }

    public function testASecretDestinationMarksEveryGeneratedLeaf(): void
    {
        $result = self::load("for @i in [\"a\"] as *creds.\"@{i}\" { token = \"t-@{i}\" }");

        self::assertSame('t-a', $result['creds']['a']['token']->reveal());
    }

    // ---- where loops contradicted CASC.md ---------------------------------------

    public function testABoundValueKeepsTheReferencesFilters(): void
    {
        self::assertSame(
            ['out' => ['a' => ['up' => 'A'], 'b' => ['up' => 'B']]],
            self::load("for @x in [\"a\", \"b\"] as out.\"@{x}\" { up = @{x | upcase} }"),
        );
    }

    public function testABoundValueKeepsTheReferencesIndexAndSuffix(): void
    {
        self::assertSame(
            ['out' => ['first' => 1, 'missing' => 'none', 'alt' => 'yes']],
            self::load("for @x in [[1, 2]] as out { first = @{x[0]}\nmissing = @{x[5]:\"none\"}\nalt = @{x:+\"yes\"} }"),
        );
    }

    public function testPlusAndMinusInTheBodyEditTheFromTemplatesCopyNotTheTemplate(): void
    {
        $result = self::load(<<<'CASC'
            t { list = [1], a = 1, b = 2 }
            for @x in ["p", "q"] from t as out."@{x}" {
              +list = [@{x}]
              -a
            }
            CASC);

        self::assertSame(['list' => [1], 'a' => 1, 'b' => 2], $result['t']);
        self::assertSame(['list' => [1, 'p'], 'b' => 2], $result['out']['p']);
        self::assertSame(['list' => [1, 'q'], 'b' => 2], $result['out']['q']);
    }

    public function testAnEditOfAKeyTheTemplateLacksIsAPlainAssignmentOrNothing(): void
    {
        self::assertSame(
            ['t' => ['b' => 2], 'out' => ['p' => ['b' => 2, 'list' => ['p']]]],
            self::load("t { b = 2 }\nfor @x in [\"p\"] from t as out.\"@{x}\" {\n  +list = [@{x}]\n  -gone = [1]\n  -absent\n}"),
        );
    }

    public function testABindingReachesAFilterArgument(): void
    {
        self::assertSame(
            ['out' => ['s' => 'HTTPS']],
            self::load("for @x in [\"://\"] as out { s = \${SCHEME | trim_suffix: \"@{x}\"} }", ['env' => ['SCHEME' => 'HTTPS://']]),
        );
    }

    public function testADestinationKeyBuiltFromABindingMayNotHoldADotAsNoInterpolatedKeyMay(): void
    {
        $error = self::loadError("for @x in [\"a.b\"] as out.\"@{x}\" { v = 1 }");

        self::assertSame(CooperError::STAGE_RESOLVE, $error->stage);
        self::assertStringContainsString('more than one path segment', $error->getMessage());
    }

    public function testABodyKeyBuiltFromABindingMayNotHoldADot(): void
    {
        self::assertSame(CooperError::STAGE_RESOLVE, self::loadError("for @x in [\"a\"] as out { \"k-@{x}.z\" = 1 }")->stage);
    }

    public function testAKeyBuiltFromABindingMayNotBeEmpty(): void
    {
        $error = self::loadError("for @x in [\"\"] as out.\"@{x}\" { v = 1 }");

        self::assertSame(CooperError::STAGE_RESOLVE, $error->stage);
        self::assertStringContainsString('empty string', $error->getMessage());
    }

    public function testALoopIterableMustNameItsVariableNotBuildTheName(): void
    {
        $error = self::loadError("@n = \"l\"\n@l = [1]\nfor @x in @{\"@{n}\"} as out { v = @{x} }");

        self::assertSame(CooperError::STAGE_LOOP, $error->stage);
        self::assertStringContainsString('must name its variable directly', $error->getMessage());
    }

    public function testAPrivateIterableShadowsAPublicOneOfTheSameName(): void
    {
        self::assertSame(['out' => ['b' => ['v' => 1]]], self::load("@l = [\"a\"]\n@*l = [\"b\"]\nfor @x in @{l} as out.\"@{x}\" { v = 1 }"));
    }
}

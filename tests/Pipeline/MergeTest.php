<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Tests\Pipeline;

use JOetjen\Cooper\CooperError;
use JOetjen\Cooper\Node\ConfigRef;
use JOetjen\Cooper\Node\Layered;
use JOetjen\Cooper\Node\MapNode;
use JOetjen\Cooper\Pipeline\Grammar;
use JOetjen\Cooper\Pipeline\Merge;
use JOetjen\Cooper\Tests\Support\CooperTestCase;
use JOetjen\Cooper\Value\CooperDateTime;
use JOetjen\Cooper\Value\CooperDuration;
use JOetjen\Cooper\Value\CooperSecret;
use JOetjen\Cooper\Value\CooperTuple;

/**
 * CASC.md §8: deep-merged maps, wholesale-replaced lists, never-merged
 * tuples, and the sigils overriding each -- ported from the Elixir
 * suite's `merge_test.exs`.
 */
final class MergeTest extends CooperTestCase
{
    public function testMapsDeepMerge(): void
    {
        self::assertSame(['a' => ['b' => 1, 'c' => 2]], self::merged("a { b = 1 }\na { c = 2 }"));
        self::assertSame(
            ['a' => ['b' => ['c' => ['d' => 1, 'e' => 2], 'f' => 3]]],
            self::merged("a.b.c.d = 1\na { b { c { e = 2 } } }\na.b.f = 3"),
        );
    }

    public function testListsReplaceWholesale(): void
    {
        self::assertSame(['tags' => ['c']], self::merged("tags = [\"a\", \"b\"]\ntags = [\"c\"]"));
    }

    public function testTheWorkedExample(): void
    {
        self::assertSame(
            ['server' => ['port' => 9090], 'tags' => ['a', 'c', 'd'], 'feature' => []],
            self::merged(<<<'CASC'
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

    public function testAReplacedBlockKeepsItsPosition(): void
    {
        self::assertSame(['a' => ['y' => 2], 'b' => 1], self::merged("a.x = 1\nb = 1\n~a { y = 2 }"));
    }

    public function testAnEmptyReplaceEmptiesTheKeyWhereItWas(): void
    {
        // An empty block is an empty map (CASC.md §5.4), so `~a {}` leaves
        // `a` empty in its place; it once removed the key altogether.
        self::assertSame(['a' => [], 'b' => 1], self::merged("a.x = 1\nb = 1\n~a {}"));
    }

    public function testATupleIsReplacedWholesale(): void
    {
        self::assertEquals(['loc' => new CooperTuple([3, 4])], self::merged("loc = (1, 2)\nloc = (3, 4)"));
    }

    public function testSigilsAgainstATupleAreErrors(): void
    {
        foreach (["+loc = [3]" => '+', "-loc = [1]" => '-', '~loc { x = 1 }' => '~'] as $statement => $sigil) {
            $error = self::mergedError("loc = (1, 2)\n{$statement}");

            self::assertSame(CooperError::STAGE_MERGE, $error->stage, $statement);
            self::assertStringContainsString("\"{$sigil}loc\" targets a tuple", $error->getMessage());
        }
    }

    public function testAppendAndRemoveNeedAList(): void
    {
        self::assertStringContainsString('"+a" needs a list', self::mergedError("a = 1\n+a = [2]")->getMessage());
        self::assertStringContainsString('"-a" needs a list', self::mergedError("a = 1\n-a = [2]")->getMessage());
    }

    public function testAppendToAMissingPathIsAPlainAssignment(): void
    {
        self::assertSame(['a' => [1]], self::merged('+a = [1]'));
    }

    public function testAppendWrapsAScalar(): void
    {
        self::assertSame(['a' => [1, 2]], self::merged("a = [1]\n+a = 2"));
    }

    public function testRemoveIsByValueAndStrict(): void
    {
        self::assertSame(['a' => [1]], self::merged("a = [1, [2]]\n-a = [[2]]"));
        self::assertSame(['a' => [1, 2.0]], self::merged("a = [1, 2.0]\n-a = [2]"));
    }

    public function testRemoveFromAMissingPathIsANoOp(): void
    {
        self::assertSame([], self::merged('-a = [1]'));
    }

    public function testBareDeleteRemovesAnyType(): void
    {
        self::assertSame(['a' => ['c' => 2]], self::merged("a.b = 1\na.c = 2\n-a.b"));
        self::assertSame([], self::merged("a = (1, 2)\n-a"));
    }

    public function testALaterSecretFlagWinsAtTheSamePath(): void
    {
        self::assertSame(['password' => 'y'], self::merged("*password = \"x\"\npassword = \"y\""));
        self::assertEquals(['password' => new CooperSecret('y')], self::merged("password = \"x\"\n*password = \"y\""));
    }

    public function testDeletingASecretSubtreeForgetsItsSecrets(): void
    {
        self::assertSame(['db' => ['password' => 'plain']], self::merged("*db.password = \"x\"\n-db\ndb.password = \"plain\""));
    }

    public function testALoopFromTemplateBecomesLayered(): void
    {
        $entries = Grammar::runOps(self::HEADER . <<<'CASC'
            defaults.replica { cpu = 1, memory_mb = 512 }

            @instances = ["a", "b"]
            for @instance in @{instances} from defaults.replica as replicas."@{instance}" {
              cpu = 2
            }
            CASC, ['env' => []]);
        $tree = Merge::assemble($entries);

        $replicas = $tree->get('replicas');
        self::assertInstanceOf(MapNode::class, $replicas);
        foreach (['a', 'b'] as $name) {
            self::assertEquals(
                new Layered(new ConfigRef(['defaults', 'replica']), new MapNode(['cpu' => 2])),
                $replicas->get($name),
            );
        }
    }

    public function testInterpolatedKeysOutsideALoopResolve(): void
    {
        // CASC.md §4.2. The reference once left the unresolved
        // reference itself as the map key.
        self::assertSame(
            ['region-eu' => ['k' => 1], 'svc' => ['prod-x' => true]],
            self::load("@region = \"eu\"\n\"region-@{region}\".k = 1\nsvc.\"\${STAGE}-x\" = true", ['env' => ['STAGE' => 'prod']]),
        );
    }

    public function testAnInterpolatedKeyFollowsTheBuiltKeyRules(): void
    {
        self::assertStringContainsString('more than one path segment', self::loadError("@k = \"a.b\"\n\"@{k}\" = 1")->getMessage());
        self::assertStringContainsString('empty string', self::loadError("@k = \"\"\n\"x@{k}\" = 1\n\"@{k}\" = 2")->getMessage());
        self::assertStringContainsString('may only reference', self::loadError("a = \"k\"\n\"%{a}\" = 1")->getMessage());
    }

    // ---- `+`/`-` operands (§8.2, §8.4) ------------------------------------------

    public function testANilOperandIsOneElementAppendedAsItself(): void
    {
        // CASC.md §8.4: anything that is not a list is one element, nil
        // included. The reference once read nil as no elements at all.
        self::assertSame(['a' => [1, null]], self::merged("a = [1]\n+a = nil"));
    }

    public function testANilOperandRemovesTheNilElements(): void
    {
        self::assertSame(['a' => [1, 2]], self::merged("a = [1, nil, 2]\n-a = nil"));
    }

    public function testAnOperandThatIsAReferenceIsAppendedOnceItResolvesWhereverItWasDeclared(): void
    {
        self::assertSame(['tags' => ['a', 'b', 'c']], self::load("tags = [\"a\"]\n+tags = @{more}\n@more = [\"b\", \"c\"]"));
    }

    public function testAListThatIsStillAReferenceIsEditedOnceItResolves(): void
    {
        self::assertSame(
            ['base' => [1, 2], 'tags' => [2, 3]],
            self::load("base = [1, 2]\ntags = %{base}\n+tags = [3]\n-tags = [1]"),
        );
    }

    public function testRemovingComparesStrictlySoAnIntegerIsNotTheFloatOfTheSameValue(): void
    {
        self::assertSame(['a' => [1, 2.0, 'x']], self::merged("a = [1, 2.0, \"x\"]\n-a = [1.0, 2]"));
    }

    public function testRemovingADurationBeyond64BitsMatchesItsExactCount(): void
    {
        self::assertEquals(
            ['a' => [new CooperDuration(86_400_000_000_000)]],
            self::merged("a = [300000000d, 1d]\n-a = [300000000d]"),
        );
    }

    public function testRemovingAnOffsetDateTimeMatchesItsPrecisionToo(): void
    {
        // Elixir's strict `in` compares a `DateTime`'s precision along
        // with its instant, so `.5` removes `.5` and leaves `.50`.
        self::assertEquals(
            ['a' => [CooperDateTime::parse('1979-05-27T07:32:00.50Z')]],
            self::merged("a = [1979-05-27T07:32:00.5Z, 1979-05-27T07:32:00.50Z]\n-a = [1979-05-27T07:32:00.5Z]"),
        );
    }

    public function testAppendingToATupleIsAnError(): void
    {
        self::assertSame(CooperError::STAGE_MERGE, self::loadError("t = (1, 2)\n+t = [3]")->stage);
    }

    public function testADeferredEditOfSomethingThatIsNoListIsAResolveError(): void
    {
        $error = self::loadError("v = %{s}\ns = \"x\"\n+v = [1]");

        self::assertSame(CooperError::STAGE_RESOLVE, $error->stage);
        self::assertStringContainsString('"+v" needs a list at that path', $error->getMessage());
    }

    // ---- maps inside lists (CASC.md §6.10) -----------------------------------
    // Through the whole pipeline, since an element block becomes a map
    // only when it is resolved.

    public function testAListElementMayBeABlockWhichIsAMap(): void
    {
        self::assertSame(['l' => [['a' => 1], ['b' => ['c' => 2]]]], self::load("l = [{ a = 1 }, { b { c = 2 } }]"));
    }

    public function testSoMayATupleElement(): void
    {
        self::assertEquals(['t' => new CooperTuple([['x' => 1], 2])], self::load("t = ({ x = 1 }, 2)"));
    }

    public function testPlusAndMinusAppendAndRemoveWholeMapsComparedByValue(): void
    {
        self::assertSame(
            ['l' => [['a' => 2], ['a' => 3]]],
            self::load("l = [{ a = 1 }, { a = 2 }]\n+l = [{ a = 3 }]\n-l = [{ a = 1 }]"),
        );
    }

    public function testAnElementBlocksKeysInterpolateAndSeeTheFilesPrivateVariables(): void
    {
        self::assertSame(['l' => [['k-x' => 7]]], self::load("@*n = \"k\"\n@*v = 7\nl = [{ \"@{n}-x\" = @{v} }]"));
    }

    public function testALoopBindingReachesAnElementBlock(): void
    {
        self::assertSame(['o' => ['l' => [['k-a' => 'a']]]], self::load("for @x in [\"a\"] as o { l = [{ \"k-@{x}\" = @{x} }] }"));
    }

    public function testASecretInsideAnElementBlockStaysSecret(): void
    {
        $pw = self::load("l = [{ *pw = \"s\" }]")['l'][0]['pw'];

        self::assertInstanceOf(CooperSecret::class, $pw);
        self::assertSame('s', $pw->reveal());
    }

    public function testAnElementBlockMayNotAppearInAnInterpolatedKeyWhichIsResolvedBeforeTheTree(): void
    {
        self::assertSame(CooperError::STAGE_RESOLVE, self::loadError("@v = [{ a = 1 }]\n\"@{v}\" = 1")->stage);
    }

    // ---- an empty block (CASC.md §5.4) ----------------------------------------
    // Checked on the merged tree, where a map is still a `MapNode`: once
    // converted, an empty map and an empty list are both `[]`.

    public function testAnEmptyBlockIsAnEmptyMap(): void
    {
        $tree = Grammar::run(self::HEADER . "a {}\nb = {}", ['env' => []]);

        self::assertEquals(new MapNode(['a' => new MapNode(), 'b' => new MapNode()]), $tree);
        self::assertSame(['c' => [[]]], self::load("c = [{}]"));
    }

    public function testAnEmptyBlockWrittenOverAMapLeavesItAsItIs(): void
    {
        self::assertSame(['w' => ['x' => 1]], self::merged("w.x = 1\nw {}"));
    }

    public function testAnEmptyBlockWrittenOverAnythingElseReplacesIt(): void
    {
        self::assertEquals(new MapNode(['w' => new MapNode()]), Grammar::run(self::HEADER . "w = 1\nw {}", ['env' => []]));
    }

    public function testTildeEmptiesAMap(): void
    {
        self::assertEquals(new MapNode(['w' => new MapNode()]), Grammar::run(self::HEADER . "w.x = 1\n~w {}", ['env' => []]));
    }
}

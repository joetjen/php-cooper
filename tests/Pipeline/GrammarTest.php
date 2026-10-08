<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Tests\Pipeline;

use JOetjen\Cooper\CooperError;
use JOetjen\Cooper\Node\MapNode;
use JOetjen\Cooper\Pipeline\Grammar;
use JOetjen\Cooper\Pipeline\Native;
use JOetjen\Cooper\Tests\Support\CooperTestCase;
use JOetjen\Cooper\Value\CooperAtom;
use JOetjen\Cooper\Value\CooperDuration;
use JOetjen\Cooper\Value\CooperSecret;

/**
 * File structure: the version header, comments, disabled statements,
 * key paths, blocks, and secret keys -- ported from the Elixir suite's
 * `grammar_test.exs`, plus the parser-level differences from the Elixir
 * grammar (see `Syntax\Parser`).
 */
final class GrammarTest extends CooperTestCase
{
    public function testTheMinimalValidFileIsAnEmptyMap(): void
    {
        self::assertEquals(new MapNode(), Grammar::run('#@version = 1.0', ['env' => []]));
    }

    public function testVersionHeaderForms(): void
    {
        foreach (['#@version 1.0', '#@version = 1', '#@version = 1.0', '#@version = 2.1.3', "# leading comment\n\n#@version = 1"] as $source) {
            self::assertSame([], Native::convert(Grammar::run($source, ['env' => []])), $source);
        }
    }

    public function testAMismatchedHeaderKeywordIsRejected(): void
    {
        $this->expectException(CooperError::class);
        $this->expectExceptionMessage('expected "#@version", got "#@versio"');

        Grammar::run('#@versio = 1.0');
    }

    public function testAMissingHeaderIsRejected(): void
    {
        foreach (['', "foo = 1\n#@version = 1.0\n"] as $source) {
            try {
                Grammar::run($source);
                self::fail('expected a parse error for ' . json_encode($source));
            } catch (CooperError $e) {
                self::assertSame(CooperError::STAGE_PARSER, $e->stage);
            }
        }
    }

    public function testACommentWithAFollowingSpaceIsAComment(): void
    {
        self::assertSame(['foo' => 1], self::merged("# a comment\nfoo = 1 # trailing\n#\n"));
    }

    public function testAHashDirectlyBeforeTextThatIsNotAStatementIsAParseError(): void
    {
        self::assertSame(CooperError::STAGE_PARSER, self::mergedError('#TODO fix this')->stage);
    }

    public function testDisabledStatementsProduceNothing(): void
    {
        self::assertSame(['section' => ['kept' => true]], self::merged("section {\n  #ignored = 1\n  kept = true\n}"));
        self::assertSame(['section' => ['kept' => true]], self::merged("section {\n  #*sub { remove = yes }\n  kept = true\n}"));
    }

    public function testTheFourEquivalentSurfaceForms(): void
    {
        foreach ([
            'foo { bar { baz = "dronf" } }',
            'foo { bar.baz "dronf" }',
            'foo = { bar = { baz = "dronf" } }',
            'foo.bar.baz "dronf"',
        ] as $source) {
            self::assertSame(['foo' => ['bar' => ['baz' => 'dronf']]], self::merged($source), $source);
        }
    }

    public function testDifferentSurfaceFormsMergeIntoOneMap(): void
    {
        self::assertSame(
            ['foo' => ['bar' => ['baz' => 1, 'qux' => 2]]],
            self::merged("foo.bar.baz = 1\nfoo = { bar = { qux = 2 } }"),
        );
    }

    public function testQuotedKeySegments(): void
    {
        self::assertSame(
            ['headers' => ['Content-Type' => 'application/json'], 'foo' => ['bar baz' => ['dronf' => 'fnord']], 'sq' => ['a.b' => 1]],
            self::merged("headers = { \"Content-Type\" = \"application/json\" }\nfoo.\"bar baz\".dronf = \"fnord\"\nsq.'a.b' = 1"),
        );
    }

    public function testSecretKeysInEveryPosition(): void
    {
        $tree = self::merged("*password = \"hunter2\"\ndb.*password = \"x\"\n*other.password = \"y\"");
        assert(is_array($tree));

        self::assertEquals(new CooperSecret('hunter2'), $tree['password']);
        self::assertEquals(new CooperSecret('x'), $tree['db']['password']);
        self::assertEquals(new CooperSecret('y'), $tree['other']['password']);
    }

    public function testStatementsMayShareALineOrBeCommaSeparatedInABlock(): void
    {
        self::assertSame(['v' => 'x', 'w' => 2], self::merged('v = "x" w = 2'));
        self::assertSame(['s' => ['a' => 1, 'b' => 2]], self::merged('s { a = 1, b = 2, }'));
    }

    public function testContextualKeywordsAreOrdinaryKeys(): void
    {
        self::assertSame(['for' => 1, 'in' => 2, 'from' => 3, 'as' => 4, 'import' => 5], self::merged("for = 1\nin = 2\nfrom = 3\nas = 4\nimport = 5"));
    }

    // ---- differences from the Elixir grammar ------------------------------------

    public function testAnAssignmentWithoutEqualsToAStringValue(): void
    {
        // CASC.md §5.3's own example; the Elixir grammar takes `foo "bar"`
        // for an import statement and rejects it.
        self::assertSame(['foo' => 'bar', 'n' => 1], self::merged("foo \"bar\"\nn 1"));
    }

    public function testABareDeleteFollowedByAnotherStatement(): void
    {
        // CASC.md §5.7 (`followed_delete`). The reference's 0.4.0 read `-a.b` then
        // `c = 1` as the remove `-a.b = c` and failed on the stray `= 1`.
        self::assertSame(['a' => ['x' => 2], 'c' => 1], self::merged("a.b = 1\na.x = 2\n-a.b\nc = 1"));
    }

    public function testTheOneAmbiguousCaseResolvesAsADelete(): void
    {
        // Pinned, not endorsed: without newlines `-tags b` + `c = 1` cannot
        // be told from `-tags` + `b c`. CASC.md §5.7 says write `-tags = b`.
        self::assertSame(CooperError::STAGE_PARSER, self::mergedError("tags = [b]\n-tags b\nc = 1")->stage);
        self::assertSame(['tags' => [], 'c' => 1], self::merged("tags = [b]\n-tags = b\nc = 1"));
    }

    public function testADeleteInsideABlock(): void
    {
        self::assertSame(['a' => ['c' => 2]], self::merged("a.b = 1\na.c = 2\na { -b }"));
        self::assertSame(['a' => ['c' => 2]], self::merged("a.b = 1\na.c = 2\na { -b, }"));
    }

    public function testARemoveWhoseValueIsOnTheSameLineStillParses(): void
    {
        self::assertSame(['tags' => ['a']], self::merged("tags = [\"a\", \"b\"]\n-tags [\"b\"]"));
    }

    public function testAppendToAKeyStartingWithInf(): void
    {
        // The Elixir lexer reads `+info` as `+inf` followed by `o`.
        self::assertSame(['info' => [1, 2], 'infra' => CooperAtom::of('x')], self::merged("info = [1]\n+info = [2]\ninfra = x"));
    }

    public function testADurationTakesDigitSeparatorsAsEveryOtherNumberDoes(): void
    {
        // CASC.md §6.3, §6.8. The reference once read `1_000ms` as the
        // integer `1_000` followed by a stray `ms`.
        self::assertEquals(['t' => new CooperDuration(1_000_000_000)], self::merged('t = 1_000ms'));
    }

    public function testAnUnterminatedStringIsALexerError(): void
    {
        $error = self::mergedError('v = "open');

        self::assertSame(CooperError::STAGE_LEXER, $error->stage);
        self::assertSame(2, $error->sourceLine);
    }

    public function testAParseErrorNamesWhatWasExpected(): void
    {
        $error = self::mergedError('v = ');

        self::assertSame(CooperError::STAGE_PARSER, $error->stage);
        self::assertStringContainsString('unexpected end of input', $error->getMessage());
    }
}

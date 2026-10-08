<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Tests\Syntax;

use JOetjen\Cooper\CooperError;
use JOetjen\Cooper\Node\ConfigRef;
use JOetjen\Cooper\Node\EnvRef;
use JOetjen\Cooper\Node\Filter;
use JOetjen\Cooper\Node\InterpText;
use JOetjen\Cooper\Node\ResolverRef;
use JOetjen\Cooper\Node\Suffix;
use JOetjen\Cooper\Node\TaggedRef;
use JOetjen\Cooper\Node\VarRef;
use JOetjen\Cooper\Syntax\InterpParser;
use JOetjen\Cooper\Value\CooperAtom;
use PHPUnit\Framework\TestCase;

/**
 * References embedded in a double-quoted string (the Elixir original's
 * `casc_interp.aether` + `Cooper.InterpActions`).
 */
final class InterpParserTest extends TestCase
{
    public function testPlainTextStaysAString(): void
    {
        self::assertSame('', InterpParser::parse(''));
        self::assertSame('plain text, no refs', InterpParser::parse('plain text, no refs'));
        self::assertSame('a !b not a tag, 50% {x}', InterpParser::parse('a !b not a tag, 50% {x}'));
    }

    public function testEveryReferenceForm(): void
    {
        self::assertEquals(
            new InterpText([
                'port: ', new EnvRef('PORT', suffix: new Suffix(Suffix::DEFAULT, 8080)),
                ', tagged: ', new TaggedRef('int', '1'),
                ', cfg: ', new ConfigRef(['server', 'host'], 0, new Suffix(Suffix::DEFAULT, CooperAtom::of('default'))),
                ', res: ', new ResolverRef('vault', 'a/{b}/c'),
                ', var: ', new VarRef('x', null, new Suffix(Suffix::SUBSTITUTE, 'y')),
            ]),
            InterpParser::parse('port: ${PORT:8080}, tagged: !int("1"), cfg: %{server.host[0]:default}, res: !{vault:a/{b}/c}, var: @{x:+"y"}'),
        );
    }

    public function testTheReducedDefaultGrammar(): void
    {
        $default = static fn (string $text): mixed => (InterpParser::parse("@{x:{$text}}") instanceof InterpText)
            ? InterpParser::parse("@{x:{$text}}")->segments[0]->suffix?->value
            : null;

        self::assertNull($default('nil'));
        self::assertTrue($default('true'));
        self::assertSame(-3, $default('-3'));
        self::assertSame(2.5, $default('2.5'));
        self::assertSame('a:b', $default('"a:b"'));
        self::assertSame('lit', $default("'lit'"));
        self::assertSame(['a, b', 1, CooperAtom::of('c')], $default('["a, b", 1, c]'));
        self::assertSame(CooperAtom::of('bare'), $default('bare'));
    }

    public function testEnvironmentBracketForms(): void
    {
        self::assertEquals(new InterpText([new EnvRef('HOSTS', null, true)]), InterpParser::parse('${HOSTS[]}'));
        self::assertEquals(new InterpText([new EnvRef('HOSTS', 2)]), InterpParser::parse('${HOSTS[2]}'));
    }

    public function testFiltersAreQuoteAwareAndSplitBeforeTheSuffix(): void
    {
        self::assertEquals(
            new InterpText([new EnvRef('S', suffix: new Suffix(Suffix::DEFAULT, 'a|b'), filters: [new Filter('trim_suffix', '://'), new Filter('upcase')])]),
            InterpParser::parse("\${S:\"a|b\" | trim_suffix: '://' | upcase}"),
        );
    }

    public function testRequiredMessage(): void
    {
        self::assertEquals(new InterpText([new VarRef('n', null, new Suffix(Suffix::REQUIRED, "need\tit"))]), InterpParser::parse('@{n:?"need\tit"}'));
    }

    public function testAnUnterminatedReferenceIsAnError(): void
    {
        foreach (['@{abc', 'x ${', '!int("1"', '!{a:{b}'] as $text) {
            try {
                InterpParser::parse($text);
                self::fail('accepted ' . $text);
            } catch (CooperError $e) {
                self::assertSame(CooperError::STAGE_PARSER, $e->stage);
            }
        }
    }
}

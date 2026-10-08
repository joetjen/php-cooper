<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Tests\Pipeline;

use JOetjen\Cooper\CooperError;
use JOetjen\Cooper\Node\Clear;
use JOetjen\Cooper\Node\ConfigRef;
use JOetjen\Cooper\Node\EnvRef;
use JOetjen\Cooper\Node\Filter;
use JOetjen\Cooper\Node\InterpText;
use JOetjen\Cooper\Node\Op;
use JOetjen\Cooper\Node\ResolverRef;
use JOetjen\Cooper\Node\Suffix;
use JOetjen\Cooper\Node\TaggedRef;
use JOetjen\Cooper\Node\VarDecl;
use JOetjen\Cooper\Node\VarRef;
use JOetjen\Cooper\Pipeline\Scope;
use JOetjen\Cooper\Tests\Support\CooperTestCase;
use JOetjen\Cooper\Value\CooperAtom;
use JOetjen\Cooper\Value\CooperBytes;
use JOetjen\Cooper\Value\CooperDate;
use JOetjen\Cooper\Value\CooperDateTime;
use JOetjen\Cooper\Value\CooperDuration;
use JOetjen\Cooper\Value\CooperIPv4;
use JOetjen\Cooper\Value\CooperIPv6;
use JOetjen\Cooper\Value\CooperInteger;
use JOetjen\Cooper\Value\CooperLocalDateTime;
use JOetjen\Cooper\Value\CooperTime;
use JOetjen\Cooper\Value\CooperTuple;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Literal values (CASC.md §6), the unresolved reference nodes (§7), and
 * the op-level shape of the merge sigils (§5.7) -- ported from the
 * Elixir suite's `actions_test.exs`, `values_test.exs`,
 * `interpolation_test.exs`, and `sigils_test.exs`.
 */
final class EvaluatorTest extends CooperTestCase
{
    private const SCOPE = Scope::ANONYMOUS;

    // ---- §6.1-6.3 nil, booleans, numbers -------------------------------------

    public function testNilAndBooleans(): void
    {
        self::assertNull(self::value('nil'));
        self::assertTrue(self::value('true'));
        self::assertFalse(self::value('false'));
    }

    /**
     * @return iterable<string, array{string, int|float}>
     */
    public static function numbers(): iterable
    {
        yield 'negative' => ['-17', -17];
        yield 'zero' => ['0', 0];
        yield 'explicit plus' => ['+99', 99];
        yield 'hex' => ['0xDEAD_BEEF', 0xDEADBEEF];
        yield 'octal' => ['0o755', 0o755];
        yield 'binary' => ['0b11010110', 0b11010110];
        yield 'float' => ['3.1415', 3.1415];
        yield 'negative float' => ['-0.01', -0.01];
        yield 'exponent' => ['5e+22', 5.0e22];
        yield 'negative exponent' => ['-2E-2', -2.0e-2];
        yield 'digit separators' => ['1_000_000', 1_000_000];
        yield 'int64 max' => ['9223372036854775807', PHP_INT_MAX];
        yield 'int64 min' => ['-9223372036854775808', PHP_INT_MIN];
    }

    #[DataProvider('numbers')]
    public function testNumbers(string $literal, int|float $expected): void
    {
        self::assertSame($expected, self::value($literal));
    }

    public function testInfinity(): void
    {
        self::assertSame(INF, self::value('inf'));
        self::assertSame(INF, self::value('+inf'));
        self::assertSame(-INF, self::value('-inf'));
    }

    public function testAnIntegerBeyond64BitsKeepsEveryDigit(): void
    {
        // CASC.md §6.3 integers are arbitrary-precision, as the
        // reference's are; beyond PHP's `int` one is a `CooperInteger`.
        $value = self::value('12345678901234567890');

        self::assertInstanceOf(CooperInteger::class, $value);
        self::assertSame('12345678901234567890', (string) $value);
        self::assertSame(42, self::value('42'));
    }

    // ---- §6.4 atoms ------------------------------------------------------------

    public function testABareIdentifierIsAnAtom(): void
    {
        self::assertSame(CooperAtom::of('info'), self::value('info'));
        self::assertSame(CooperAtom::of('info'), self::value(':info'));
    }

    public function testAColonBeforeNilTrueOrFalseIsThatValue(): void
    {
        // CASC.md §6.4: `:nil`, `:true`, `:false` are the values, so a
        // document means one thing in every implementation.
        self::assertTrue(self::value(':true'));
        self::assertFalse(self::value(':false'));
        self::assertNull(self::value(':nil'));
    }

    public function testAColonBeforeInfIsAnOrdinaryAtom(): void
    {
        self::assertSame(CooperAtom::of('inf'), self::value(':inf'));
    }

    // ---- §6.5 strings ----------------------------------------------------------

    public function testDoubleQuotedStringsProcessEscapes(): void
    {
        self::assertSame("line1\nline2", self::value('"line1\nline2"'));
        self::assertSame('a "quote"', self::value('"a \"quote\""'));
        self::assertSame("tab\there", self::value('"tab\there"'));
        self::assertSame('tabAq', self::value('"tab\u0041\q"'));
        self::assertSame('é', self::value('"\u00e9"'));
    }

    public function testSingleQuotedStringsAreLiteral(): void
    {
        self::assertSame('no \n escapes here', self::value("'no \\n escapes here'"));
    }

    public function testATripleQuotedStringIsDedented(): void
    {
        $result = self::merged(<<<'CASC'
            motd = """
                Welcome to the service.
                Status: operational
                """
            CASC);

        self::assertSame(['motd' => "Welcome to the service.\nStatus: operational\n"], $result);
    }

    public function testADoubleQuotedStringWithoutReferencesStaysPlain(): void
    {
        self::assertSame('hello world', self::value('"hello world"'));
    }

    public function testADoubleQuotedStringWithAReferenceBecomesInterpText(): void
    {
        self::assertEquals(
            new InterpText(['hello ', new VarRef('name', scope: self::SCOPE)]),
            self::value('"hello @{name}"'),
        );
    }

    // ---- §6.6-6.9 dates, IPs, durations, byte sizes ---------------------------

    public function testOffsetDateTimeIsAUtcInstant(): void
    {
        $value = self::value('1979-05-27T07:32:00Z');

        self::assertInstanceOf(CooperDateTime::class, $value);
        self::assertSame('1979-05-27T07:32:00+00:00', $value->toDateTimeImmutable()->format(DATE_ATOM));
        self::assertSame(0, $value->precision);
    }

    public function testANumericOffsetIsNormalizedToUtc(): void
    {
        $value = self::value('1979-05-27T07:32:00.123+02:00');

        self::assertInstanceOf(CooperDateTime::class, $value);
        self::assertSame('1979-05-27T05:32:00.123Z', $value->toIso8601());
        self::assertSame('1979-05-27T02:02:00.5Z', self::isoOf(self::value('1979-05-27T07:32:00.5+05:30')));
    }

    public function testAnOffsetDateTimeKeepsTheFractionalDigitsAsWritten(): void
    {
        // The reference's `DateTime` keeps how many fractional digits
        // were written; this port's `DateTimeImmutable` once dropped
        // that, so `.120` came back as `.12` and `.0` as no fraction.
        self::assertSame('1979-05-27T07:32:00.120Z', self::isoOf(self::value('1979-05-27T07:32:00.120Z')));
        self::assertSame('1979-05-27T07:32:00.0Z', self::isoOf(self::value('1979-05-27T07:32:00.0Z')));
        self::assertSame('1979-05-27T07:32:00.123456Z', self::isoOf(self::value('1979-05-27T07:32:00.123456789Z')));
    }

    public function testAnImpossibleOffsetDateTimeIsALoadTimeError(): void
    {
        self::assertSame(CooperError::STAGE_ACTION, self::mergedError('v = 1979-02-30T07:32:00Z')->stage);
    }

    private static function isoOf(mixed $value): string
    {
        self::assertInstanceOf(CooperDateTime::class, $value);

        return $value->toIso8601();
    }

    public function testLocalDateTimeDateAndTime(): void
    {
        self::assertEquals(
            new CooperLocalDateTime(new CooperDate(1979, 5, 27), new CooperTime(7, 32, 0)),
            self::value('1979-05-27T07:32:00'),
        );
        self::assertEquals(new CooperDate(1979, 5, 27), self::value('1979-05-27'));
        self::assertEquals(new CooperTime(7, 32, 0), self::value('07:32:00'));
        self::assertEquals(new CooperTime(7, 32, 0, 123456, 6), self::value('07:32:00.1234567'));
    }

    public function testAnImpossibleDateIsALoadTimeError(): void
    {
        // The reference's 0.4.0 crashed here (`Date.from_iso8601!/1`).
        $error = self::mergedError('v = 2023-02-30');

        self::assertSame(CooperError::STAGE_ACTION, $error->stage);
        self::assertStringContainsString('invalid date', $error->getMessage());
    }

    public function testIpAddresses(): void
    {
        self::assertEquals(new CooperIPv4([127, 0, 0, 1]), self::value('127.0.0.1'));
        self::assertEquals(new CooperIPv4([127, 0, 0, 1], 32), self::value('127.0.0.1/32'));
        self::assertEquals(new CooperIPv6([0, 0, 0, 0, 0, 0, 0, 1]), self::value('::1'));
        self::assertEquals(new CooperIPv6([0, 0, 0, 0, 0, 0, 0, 1], 128), self::value('::1/128'));
    }

    public function testAnOutOfRangeOctetIsALoadTimeError(): void
    {
        $error = self::mergedError('v = 999.999.999.999');

        self::assertSame(CooperError::STAGE_ACTION, $error->stage);
        self::assertStringContainsString('invalid IP address', $error->getMessage());
    }

    public function testAnOutOfRangeCidrPrefixIsALoadTimeError(): void
    {
        self::assertSame('invalid CIDR prefix 33 (must be 0..32)', self::mergedError('v = 127.0.0.1/33')->reason);
        self::assertSame('invalid CIDR prefix 129 (must be 0..128)', self::mergedError('v = ::1/129')->reason);
    }

    public function testDurations(): void
    {
        self::assertEquals(new CooperDuration(500_000_000), self::value('500ms'));
        self::assertEquals(new CooperDuration(5_400_000_000_000), self::value('1h30m'));
        self::assertEquals(new CooperDuration(5_400_000_000_000), self::value('1.5h'));
        self::assertEquals(new CooperDuration(1_000), self::value('1µs'));
    }

    public function testADurationOrByteSizeBeyond64BitsLoadsAsTheReferenceLoadsIt(): void
    {
        // Once a load error here; the reference's integers have no limit.
        self::assertEquals(new CooperDuration(new CooperInteger('25920000000000000000000')), self::value('300000000d'));
        self::assertEquals(new CooperDuration(new CooperInteger('86400000000000000000000001')), self::value('1000000000000d1ns'));
        self::assertEquals(new CooperBytes(new CooperInteger('22517998136852480000000')), self::value('20000000PiB'));
    }

    public function testCompoundDurationRules(): void
    {
        self::assertStringContainsString('integer components', self::mergedError('v = 1.5h30m')->getMessage());
        self::assertStringContainsString('strictly descending', self::mergedError('v = 30m1h')->getMessage());
        self::assertStringContainsString('repeated', self::mergedError('v = 1h1h')->getMessage());
    }

    public function testByteSizes(): void
    {
        self::assertEquals(new CooperBytes(536_870_912), self::value('512MiB'));
        self::assertEquals(new CooperBytes(10_000_000_000), self::value('10GB'));
        self::assertEquals(new CooperBytes(1_610_612_736), self::value('1.5GiB'));
        self::assertEquals(new CooperBytes(10_000_000_000), self::value('10gb'));
        self::assertEquals(new CooperBytes(10_000_000_000), self::value('10Gb'));
        self::assertEquals(new CooperBytes(2), self::value('1.5B'));
    }

    // ---- §6.10-6.11 lists and tuples -------------------------------------------

    public function testLists(): void
    {
        self::assertSame(['a', 'b', 'c'], self::value('["a", "b", "c"]'));
        self::assertSame(['a', 'b'], self::value("[\n  \"a\"\n  \"b\"\n]"));
        self::assertSame([1, 2.5, true, null, CooperAtom::of('info')], self::value('[1, 2.5, true, nil, info]'));
        self::assertSame([], self::value('[]'));
    }

    public function testTuples(): void
    {
        self::assertEquals(new CooperTuple([52.52, 13.405]), self::value('(52.5200, 13.4050)'));
        self::assertEquals(new CooperTuple([1, 2, 3]), self::value('(1 2 3)'));
        self::assertEquals(new CooperTuple([]), self::value('()'));
        self::assertEquals([[1, 2], new CooperTuple([3, 4])], self::value('[[1, 2], (3, 4)]'));
    }

    // ---- §7 references, unresolved ---------------------------------------------

    public function testVariableReferences(): void
    {
        self::assertEquals(new VarRef('name', scope: self::SCOPE), self::value('@{name}'));
        self::assertEquals(new VarRef('name', null, new Suffix(Suffix::DEFAULT, 'fallback'), scope: self::SCOPE), self::value('@{name:"fallback"}'));
        self::assertEquals(new VarRef('name', null, new Suffix(Suffix::SUBSTITUTE, CooperAtom::of('alt')), scope: self::SCOPE), self::value('@{name:+alt}'));
        self::assertEquals(new VarRef('name', null, new Suffix(Suffix::REQUIRED, 'missing name'), scope: self::SCOPE), self::value('@{name:?"missing name"}'));
        self::assertEquals(new VarRef('name', 2, scope: self::SCOPE), self::value('@{name[2]}'));
        self::assertEquals(new VarRef('name', 2, new Suffix(Suffix::DEFAULT, 0), scope: self::SCOPE), self::value('@{name[2]:0}'));
    }

    public function testVariablesEmbeddedInAString(): void
    {
        self::assertEquals(
            new InterpText(['https://', new VarRef('domain', scope: self::SCOPE), ':', new VarRef('port', scope: self::SCOPE)]),
            self::value('"https://@{domain}:@{port}"'),
        );
    }

    public function testEnvironmentReferences(): void
    {
        self::assertEquals(new EnvRef('REGION'), self::value('${REGION}'));
        self::assertEquals(new EnvRef('MAX_RETRIES', suffix: new Suffix(Suffix::DEFAULT, 3)), self::value('${MAX_RETRIES:3}'));
        self::assertEquals(new EnvRef('FEATURE', suffix: new Suffix(Suffix::SUBSTITUTE, CooperAtom::of('on'))), self::value('${FEATURE:+on}'));
        self::assertEquals(new EnvRef('TOKEN', suffix: new Suffix(Suffix::REQUIRED, 'missing token')), self::value('${TOKEN:?"missing token"}'));
        self::assertEquals(new EnvRef('ALLOWED_HOSTS', null, true, new Suffix(Suffix::DEFAULT, ['localhost'])), self::value('${ALLOWED_HOSTS[]:["localhost"]}'));
        self::assertEquals(new EnvRef('HOSTS', 0, false, new Suffix(Suffix::DEFAULT, CooperAtom::of('default'))), self::value('${HOSTS[0]:default}'));
    }

    public function testAPlusSignedNumberAfterTheColonIsASubstitute(): void
    {
        // `:+1` lexes as ":" then the integer "+1"; the sign, not the
        // token boundary, decides (CASC.md §7.2: `:+` always introduces a
        // substitute). The reference once read it as a default of 1.
        self::assertEquals(new EnvRef('X', suffix: new Suffix(Suffix::SUBSTITUTE, 1)), self::value('${X:+1}'));
        self::assertEquals(new EnvRef('X', suffix: new Suffix(Suffix::SUBSTITUTE, 1.5)), self::value('${X:+1.5}'));
    }

    public function testAMinusSignedOrUnsignedNumberAfterTheColonIsADefault(): void
    {
        self::assertEquals(new EnvRef('X', suffix: new Suffix(Suffix::DEFAULT, -1)), self::value('${X:-1}'));
        self::assertEquals(new EnvRef('X', suffix: new Suffix(Suffix::DEFAULT, 1)), self::value('${X:1}'));
    }

    public function testConfigReferences(): void
    {
        self::assertEquals(new ConfigRef(['server', 'host']), self::value('%{server.host}'));
        self::assertEquals(new ConfigRef(['contact', 'admin'], null, new Suffix(Suffix::DEFAULT, 'ops@example.com')), self::value('%{contact.admin:"ops@example.com"}'));
        self::assertEquals(
            new InterpText(['http://', new ConfigRef(['server', 'host']), ':', new ConfigRef(['server', 'port']), '/health']),
            self::value('"http://%{server.host}:%{server.port}/health"'),
        );
    }

    public function testResolverReferencesSplitOnTheFirstColon(): void
    {
        self::assertEquals(new ResolverRef('vault', 'secret/db/password'), self::value('!{vault:secret/db/password}'));
        self::assertEquals(new ResolverRef('vault', 'secret:db:password'), self::value('!{vault:secret:db:password}'));
        self::assertEquals(new ResolverRef('vault', '{a:{b:{c:1}}}'), self::value('!{vault:{a:{b:{c:1}}}}'));
    }

    public function testAResolverReferenceWithoutAColonIsAnError(): void
    {
        self::assertStringContainsString("missing ':' separator", self::mergedError('v = !{vault}')->getMessage());
    }

    public function testTaggedValues(): void
    {
        // `value()` writes `v = <literal>` on line 2, so a tag starts at column 5.
        self::assertEquals(new TaggedRef('duration', '5m', true, null, 2, 5), self::value('!duration("5m")'));
        self::assertEquals(new TaggedRef('int', new EnvRef('PORT', suffix: new Suffix(Suffix::DEFAULT, 8080)), false, null, 2, 5), self::value('!int(${PORT:8080})'));
        self::assertEquals(new TaggedRef('bool', new EnvRef('DEBUG', suffix: new Suffix(Suffix::DEFAULT, false)), false, null, 2, 5), self::value('!bool(${DEBUG:false})'));
    }

    public function testATagRemembersWhetherItsArgumentWasWrittenAsAStringLiteral(): void
    {
        $literal = static fn (string $written): bool => self::taggedRef($written)->literal;

        self::assertTrue($literal('!t("a")'));
        self::assertTrue($literal("!t('a \${X}')"));
        self::assertTrue($literal('!t("""a ${X}""")'));
        self::assertFalse($literal('!t("a ${X}")'));
        self::assertFalse($literal('!t(@{x})'));
        self::assertFalse($literal('!t(1)'));
        self::assertFalse($literal('!t(!u("a"))'));
    }

    private static function taggedRef(string $written): TaggedRef
    {
        $value = self::value($written);
        self::assertInstanceOf(TaggedRef::class, $value);

        return $value;
    }

    public function testFiltersOnABareReference(): void
    {
        self::assertEquals(
            new EnvRef('S', filters: [new Filter('trim_suffix', '://'), new Filter('upcase')]),
            self::value('${S | trim_suffix: "://" | upcase}'),
        );
    }

    // ---- §5.7 sigils, at the op level ------------------------------------------

    public function testNoSigilIsMerge(): void
    {
        self::assertEquals([new Op(['port'], Op::MERGE, 9090)], self::ops('port = 9090'));
    }

    public function testReplaceEmitsAClearThenReplaceLeaves(): void
    {
        self::assertEquals(
            [
                new Clear(['server']),
                new Op(['server', 'host'], Op::REPLACE, '0.0.0.0'),
                new Op(['server', 'port'], Op::REPLACE, 9090),
            ],
            self::ops('~server { host = "0.0.0.0", port = 9090 }'),
        );
    }

    public function testAppendRemoveAndDelete(): void
    {
        self::assertEquals(
            [
                new Op(['tags'], Op::APPEND, ['d']),
                new Op(['tags'], Op::REMOVE, ['b']),
                new Op(['feature', 'legacy_mode'], Op::DELETE, null),
            ],
            self::ops("+tags = [\"d\"]\n-tags = [\"b\"]\n-feature.legacy_mode"),
        );
    }

    public function testSecretAndPrivateMarkers(): void
    {
        self::assertEquals([new Op(['foo'], Op::MERGE, 1, false)], self::ops('foo = 1'));
        self::assertEquals([new Op(['bar'], Op::MERGE, 2, true)], self::ops('*bar = 2'));
        self::assertEquals([new VarDecl('x', 1, true)], self::ops('@x = 1'));
        self::assertEquals([new VarDecl('x', 1, false)], self::ops('@*x = 1'));
    }

    public function testASigilInsideABlockKeepsItsMeaning(): void
    {
        // The reference's 0.4.0 overwrote every inner sigil with the
        // enclosing statement's -- `a { +tags = [2] }` replaced the list
        // and `a { -b }` set `b = nil`.
        self::assertEquals(
            [new Op(['a', 'tags'], Op::APPEND, [2]), new Op(['a', 'b'], Op::DELETE, null)],
            self::ops("a {\n  +tags = [2]\n  -b\n}"),
        );
        self::assertSame(['a' => ['tags' => [1, 2], 'c' => 2]], self::merged("a.tags = [1]\na.b = 1\na.c = 2\na {\n  +tags = [2]\n  -b\n}"));
    }

    public function testAnOuterSigilAppliesToPlainInnerOps(): void
    {
        self::assertEquals(
            [new Clear(['s']), new Op(['s', 'x'], Op::REPLACE, 1), new Op(['s', 'l'], Op::APPEND, [1])],
            self::ops('~s { x = 1, +l = [1] }'),
        );
    }

    // ---- §5.6 disabled statements ---------------------------------------------

    public function testADisabledStatementIsNeverEvaluated(): void
    {
        // The reference's 0.4.0 evaluated it and dropped only its entries: a
        // disabled `#@x` still defined the variable, a disabled `#import`
        // still ran (and failed on a missing file), and a bad literal in
        // one still raised.
        self::assertSame(['v' => 'fallback'], self::load("#@x = 1\nv = @{x:\"fallback\"}"));
        self::assertSame(['v' => 1], self::load("#import \"nonexistent.casc\"\nv = 1"));
        self::assertSame(['v' => 1], self::load("#bad = 999.999.999.999\nv = 1"));
    }
}

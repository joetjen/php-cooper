<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Tests\Pipeline;

use JOetjen\Cooper\Tests\Support\CooperTestCase;
use JOetjen\Cooper\Value\CooperSecret;

/**
 * CASC.md §7.2's `| filter` clauses and §7.5's normalizing tags --
 * ported from the Elixir suite's `filters_test.exs`. Every case is also
 * a parity case between a bare reference (parsed by `Parser`) and the
 * same reference inside a string (hand-parsed by `InterpParser`).
 */
final class FiltersTest extends CooperTestCase
{
    public function testParameterlessFilters(): void
    {
        self::assertSame(
            ['a' => 'x', 'b' => 'abc', 'c' => 'ABC', 'd' => 'ÄBC'],
            self::resolve("a = \${PADDED | trim}\nb = \${MIXED | downcase}\nc = \${MIXED | upcase}\nd = \${UMLAUT | upcase}", ['env' => ['PADDED' => "  x \u{00A0}", 'MIXED' => 'AbC', 'UMLAUT' => 'äbc']]),
        );
    }

    public function testAnArgumentOnAParameterlessFilter(): void
    {
        self::assertStringContainsString('takes no argument', self::resolveError('a = ${X | trim: "y"}', ['env' => ['X' => 'v']])->getMessage());
    }

    public function testTrimSuffixAndPrefix(): void
    {
        self::assertSame(['a' => 'https'], self::resolve('a = ${SCHEME | trim_suffix: "://"}', ['env' => ['SCHEME' => 'https://']]));
        self::assertSame(['a' => 'https'], self::resolve('a = ${SCHEME | trim_suffix: "://"}', ['env' => ['SCHEME' => 'https']]));
        self::assertSame(['a' => '/v1'], self::resolve('a = ${P | trim_prefix: "/api"}', ['env' => ['P' => '/api/v1']]));
    }

    public function testAMissingArgument(): void
    {
        self::assertStringContainsString('requires an argument', self::resolveError('a = ${X | trim_suffix}', ['env' => ['X' => 'v']])->getMessage());
    }

    public function testSingleAndDoubleQuotedArgumentsAreEquivalent(): void
    {
        $env = ['env' => ['SCHEME' => 'https://']];

        self::assertSame(self::resolve('a = ${SCHEME | trim_suffix: "://"}', $env), self::resolve("a = \${SCHEME | trim_suffix: '://'}", $env));
    }

    public function testFiltersApplyLeftToRightAfterTheSuffix(): void
    {
        self::assertSame(['a' => 'HTTPS'], self::resolve('a = ${S | trim_suffix: "://" | upcase}', ['env' => ['S' => 'https://']]));
        self::assertSame(['a' => 'padded'], self::resolve('a = ${COOPER_TEST_MISSING:"  padded  " | trim}'));
        self::assertSame(['a' => 'set'], self::resolve('a = ${S:"unused" | trim}', ['env' => ['S' => '  set  ']]));
    }

    public function testEveryReferenceFormFilters(): void
    {
        self::assertSame(['a' => 'v'], self::resolve("@padded = \"  v  \"\na = @{padded | trim}"));
        self::assertSame(['raw' => '  v  ', 'a' => 'v'], self::resolve("raw = \"  v  \"\na = %{raw | trim}"));
    }

    public function testBareAndInterpolatedReferencesAgree(): void
    {
        $env = ['env' => ['SCHEME' => 'https://']];

        self::assertSame(['a' => 'https'], self::resolve("a = \${SCHEME | trim_suffix: '://'}", $env));
        self::assertSame(['a' => 'https'], self::resolve("a = \"\${SCHEME | trim_suffix: '://'}\"", $env));
        self::assertSame(
            self::resolve("a = \${SCHEME | trim_suffix: '://' | upcase}", $env),
            self::resolve("a = \"\${SCHEME | trim_suffix: '://' | upcase}\"", $env),
        );
    }

    public function testAPipeInsideAQuotedDefaultIsNotAFilter(): void
    {
        self::assertSame(['a' => 'a|b'], self::resolve('a = ${COOPER_TEST_MISSING:"a|b"}'));
        self::assertSame(['a' => 'a|b'], self::resolve('a = "${COOPER_TEST_MISSING:\'a|b\'}"'));
    }

    public function testAnUnknownFilterNamesTheKnownOnes(): void
    {
        $message = self::resolveError('a = ${X | nope}', ['env' => ['X' => 'v']])->getMessage();

        self::assertStringContainsString('unknown filter "nope"', $message);
        self::assertStringContainsString('known filters: downcase, trim, trim_prefix, trim_suffix, upcase', $message);
    }

    public function testFilteringANonStringIsAnError(): void
    {
        self::assertStringContainsString('not a string', self::resolveError("n = 42\na = %{n | trim}")->getMessage());
    }

    public function testTheNormalizingTags(): void
    {
        self::assertSame(['a' => 'x', 'b' => 'abc', 'c' => 'DEF'], self::resolve("a = !trim(\"  x  \")\nb = !downcase(\"AbC\")\nc = !upcase(\"dEf\")"));
        self::assertStringContainsString('not a string', self::resolveError('a = !trim(42)')->getMessage());
    }

    // ---- a secret (§4.3) --------------------------------------------------------

    public function testASecretIsFilteredAsItsValueAndStaysASecret(): void
    {
        // CASC.md §4.3: the stored value is unaffected by being secret.
        // The reference once refused it as "not a string".
        $trimmed = self::load("*pw = \"  hunter2  \"\ntrimmed = %{pw | trim}")['trimmed'];

        self::assertInstanceOf(CooperSecret::class, $trimmed);
        self::assertSame('hunter2', $trimmed->reveal());
    }

    public function testASecretIsFilteredThroughAChain(): void
    {
        $value = self::load("*pw = \"  AbC  \"\nv = %{pw | trim | downcase}")['v'];

        self::assertInstanceOf(CooperSecret::class, $value);
        self::assertSame('abc', $value->reveal());
    }

    public function testAFilteredSecretInsideAStringRedactsItsOwnPortion(): void
    {
        $value = self::load("*pw = \"  x  \"\nv = \"[%{pw | trim}]\"")['v'];

        self::assertInstanceOf(CooperSecret::class, $value);
        self::assertSame('[x]', $value->reveal());
        self::assertSame('[[~~REDACTED~~]]', (string) $value);
    }
}

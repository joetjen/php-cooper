<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Tests\Pipeline;

use JOetjen\Cooper\CooperError;
use JOetjen\Cooper\Tests\Support\CooperTestCase;

/**
 * CASC.md §7.2's built reference names -- `${"TOKEN_@{id}"}` -- ported
 * from the Elixir suite's `built_names_test.exs`. The motivating case is
 * one environment variable per tenant, which a document otherwise
 * cannot follow: CASC reads named variables and cannot enumerate the
 * environment.
 */
final class BuiltNamesTest extends CooperTestCase
{
    private const ENV = [
        'TOKEN_1' => 'tok-one',
        'TOKEN_2' => 'tok-two',
        'APP_HOST' => 'example.test',
    ];

    /**
     * @return array<array-key, mixed>
     */
    private static function loadWithEnv(string $source): array
    {
        return self::load($source, ['env' => self::ENV]);
    }

    public function testABuiltEnvironmentName(): void
    {
        self::assertSame(['host' => 'example.test'], self::loadWithEnv("@which = \"HOST\"\nhost = \${\"APP_@{which}\"}"));
        self::assertSame(['host' => 'fallback'], self::loadWithEnv("@which = \"COOPER_TEST_MISSING\"\nhost = \${\"APP_@{which}\":\"fallback\"}"));
    }

    public function testABuiltVariableName(): void
    {
        self::assertSame(['value' => 'indirect'], self::loadWithEnv("@target = \"indirect\"\n@which = \"target\"\nvalue = @{\"@{which}\"}"));
    }

    public function testABuiltNameInsideALoop(): void
    {
        self::assertSame(
            ['tokens' => ['1' => 'tok-one', '2' => 'tok-two']],
            self::loadWithEnv("@ids = [\"1\", \"2\"]\n\nfor @id in @{ids} as tokens {\n  \"@{id}\" = \${\"TOKEN_@{id}\"}\n}"),
        );
    }

    public function testANameThatIsNotIdentifierShaped(): void
    {
        self::assertStringContainsString('not a valid name', self::loadErrorWithEnv("@bad = \"not an identifier\"\nx = \${\"APP_@{bad}\"}")->getMessage());
    }

    public function testANameBuiltFromASecret(): void
    {
        self::assertStringContainsString('may not be built from a secret value', self::loadErrorWithEnv("*token = \"s3cret\"\nx = \${\"APP_%{token}\"}")->getMessage());
    }

    public function testANameBuiltFromANumberIsNotAValidName(): void
    {
        // Interpolation always yields text, so 42 arrives as "42" -- and a
        // name cannot start with a digit.
        self::assertStringContainsString('"42", which is not a valid name', self::loadErrorWithEnv("@n = 42\nx = \${\"@{n}\"}")->getMessage());
    }

    public function testABuiltConfigKeySegment(): void
    {
        self::assertSame(['tokens' => ['supervisor-1' => 'one'], 'probe' => 'one'], self::loadWithEnv("@id = \"1\"\ntokens {\n  \"supervisor-1\" = \"one\"\n}\n\nprobe = %{tokens.\"supervisor-@{id}\"}"));
        self::assertSame(['m' => ['with-hyphen' => 1], 'probe' => 1], self::loadWithEnv("@k = \"with-hyphen\"\nm {\n  \"with-hyphen\" = 1\n}\n\nprobe = %{m.\"@{k}\"}"));
    }

    public function testABuiltKeyThatWouldSplit(): void
    {
        self::assertStringContainsString('more than one path segment', self::loadErrorWithEnv("@k = \"a.b\"\nm {\n  \"x\" = 1\n}\n\nprobe = %{m.\"@{k}\"}")->getMessage());
    }

    public function testOnlyTheBareFormMayBeBuilt(): void
    {
        // Inside a string the reference token ends at the first `}`, so a
        // nested `@{...}` cannot appear in its name -- a clean error, not
        // a silent misparse.
        $this->expectException(CooperError::class);

        self::loadWithEnv("@w = \"HOST\"\nx = \"url=\${\"APP_@{w}\"}\"");
    }

    private static function loadErrorWithEnv(string $source): CooperError
    {
        return self::loadError($source, ['env' => self::ENV]);
    }
}

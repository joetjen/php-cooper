<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Tests;

use JOetjen\Cooper\Cooper;
use JOetjen\Cooper\CooperError;
use JOetjen\Cooper\LiteralTag;
use JOetjen\Cooper\Tests\Support\CooperTestCase;

/**
 * `LiteralTag`: a consumer tag that takes only a string written out in
 * the document -- a host-specific integration feature, for a tag whose
 * argument must never come from anywhere but the file (a framework's
 * `!php("...")`, which runs its argument as code). Every other tag,
 * registered as a plain callable, behaves as it always has.
 */
final class LiteralTagTest extends CooperTestCase
{
    /** @var list<mixed> every argument the probe tag was called with */
    private array $calls = [];

    // ---- what it accepts -------------------------------------------------------

    public function testALiteralTagReceivesADoubleQuotedStringWrittenInTheDocument(): void
    {
        self::assertSame(['a' => 'CODE'], $this->loadWith('a = !code("code")'));
        self::assertSame(['code'], $this->calls);
    }

    public function testALiteralTagReceivesASingleQuotedString(): void
    {
        self::assertSame(['a' => '${X} @{Y}'], $this->loadWith("a = !code('\${x} @{y}')"));
    }

    public function testALiteralTagReceivesATripleQuotedStringWhichNeverInterpolates(): void
    {
        self::assertSame(['a' => 'RETURN "${X}" . !EMPTY($Y);'], $this->loadWith("a = !code(\"\"\"return \"\${x}\" . !empty(\$y);\"\"\")"));
    }

    public function testALiteralTagMayStandAsAnEnvironmentDefault(): void
    {
        self::assertSame(['a' => 'FALLBACK'], $this->loadWith('a = ${COOPER_LITERAL_UNSET:!code("fallback")}'));
    }

    public function testALiteralTagInsideALoopBodyStillReceivesItsLiteral(): void
    {
        self::assertSame(
            ['out' => ['a' => ['v' => 'SAME'], 'b' => ['v' => 'SAME']]],
            $this->loadWith("@names = [\"a\", \"b\"]\nfor @n in @{names} as out.\"@{n}\" {\n  v = !code(\"same\")\n}"),
        );
    }

    public function testAVariableHoldingALiteralTagGivesItsValue(): void
    {
        self::assertSame(['a' => 'CODE'], $this->loadWith("@v = !code(\"code\")\na = @{v}"));
    }

    // ---- what it refuses ---------------------------------------------------------

    public function testAnEnvironmentReferenceIsRefusedEvenWhenTheVariableIsSet(): void
    {
        $e = $this->refusal('a = !code(${COOPER_LITERAL_SET})', ['env' => ['COOPER_LITERAL_SET' => 'echo 1;']]);

        self::assertStringContainsString('!code(...) takes only a string literal written in the document', $e->getMessage());
        self::assertSame([], $this->calls);
    }

    public function testAnEnvironmentReferenceIsRefusedWhenAHostAnswersReferencesItself(): void
    {
        $this->refusal('a = !code(${COOPER_LITERAL_SET})', [
            'env' => ['COOPER_LITERAL_SET' => 'x'],
            'envValue' => static fn (mixed $reference, \Closure $read): mixed => $read(),
        ]);

        self::assertSame([], $this->calls);
    }

    public function testAnInterpolatedStringIsRefused(): void
    {
        $this->refusal('a = !code("echo ${COOPER_LITERAL_SET};")', ['env' => ['COOPER_LITERAL_SET' => 'x']]);
    }

    public function testAVariableIsRefusedEvenWhenItHoldsALiteralString(): void
    {
        $this->refusal("@v = \"code\"\na = !code(@{v})");
    }

    public function testALoopVariableIsRefused(): void
    {
        $this->refusal("@names = [\"a\"]\nfor @n in @{names} as out.\"@{n}\" {\n  v = !code(@{n})\n}");
    }

    public function testAConfigReferenceIsRefused(): void
    {
        $this->refusal("b = \"code\"\na = !code(%{b})");
    }

    public function testAnotherTagIsRefused(): void
    {
        $this->refusal('a = !code(!trim(" code "))');
    }

    public function testAResolverIsRefused(): void
    {
        $this->refusal('a = !code(!{r:code})', ['resolvers' => ['r' => static fn (string $payload): string => $payload]]);
    }

    public function testAValueThatIsNotAStringIsRefused(): void
    {
        $this->refusal('a = !code(1)');
        $this->refusal('a = !code(["x"])');
    }

    public function testALiteralTagInterpolatedIntoAStringIsRefused(): void
    {
        $this->refusal('a = "pre !code(\"x\") post"');
    }

    public function testTheRefusalNamesTheFileAndTheKey(): void
    {
        $dir = $this->scratchDir('literal_tag');
        file_put_contents("{$dir}/main.casc", self::HEADER . "db {\n  hosts = [\"a\", !code(\${COOPER_LITERAL_SET})]\n}\n");

        try {
            Cooper::loadFile("{$dir}/main.casc", $this->options(['env' => ['COOPER_LITERAL_SET' => 'x'], 'cache' => false]));
            self::fail('expected the load to be refused');
        } catch (CooperError $e) {
            self::assertSame(CooperError::STAGE_RESOLVE, $e->stage);
            self::assertStringContainsString('at db.hosts[1]', $e->getMessage());
            self::assertSame("{$dir}/main.casc", $e->sourceFile);
            self::assertSame(3, $e->sourceLine);
        }
    }

    public function testTheKeyNamedIsTheTaggedOneWhenAnotherKeyReachesItFirst(): void
    {
        $e = $this->refusal("first = %{later}\n@v = \"x\"\nlater = !code(@{v})");

        self::assertStringContainsString('at later', $e->getMessage());
    }

    // ---- when it fails -------------------------------------------------------------

    public function testAFailingLiteralTagNamesTheFileAndTheKey(): void
    {
        $dir = $this->scratchDir('literal_tag');
        file_put_contents("{$dir}/main.casc", self::HEADER . "app {\n  name = !code(\"boom\")\n}\n");

        try {
            Cooper::loadFile("{$dir}/main.casc", ['tags' => ['code' => new LiteralTag(static fn (string $code): never => throw new \RuntimeException("{$code} went off"))], 'dotenv' => false, 'cache' => false]);
            self::fail('expected the load to fail');
        } catch (CooperError $e) {
            self::assertStringContainsString('!code(...) failed at app.name: boom went off', $e->getMessage());
            self::assertSame("{$dir}/main.casc", $e->sourceFile);
            self::assertSame(3, $e->sourceLine);
            self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }
    }

    // ---- everything else is unchanged ---------------------------------------------------

    public function testAPlainCallableTagStillTakesAnyArgument(): void
    {
        self::assertSame(
            ['a' => 'FROM ENV', 'b' => 'X'],
            self::load("@v = \"x\"\na = !up(\${COOPER_LITERAL_SET})\nb = !up(@{v})", [
                'env' => ['COOPER_LITERAL_SET' => 'from env'],
                'tags' => ['up' => static fn (mixed $value): string => strtoupper((string) $value)],
            ]),
        );
    }

    public function testALiteralTagIsAcceptedWhereverACallableTagIs(): void
    {
        self::assertTrue(is_callable(new LiteralTag(static fn (string $code): string => $code)));
    }

    // ---- helpers -------------------------------------------------------------------------

    /**
     * Loads `$source` with `!code` registered as a literal tag recording
     * each call and upper-casing its argument.
     *
     * @param array<string, mixed> $options
     * @return array<array-key, mixed>
     */
    private function loadWith(string $source, array $options = []): array
    {
        return self::load($source, $this->options($options));
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function options(array $options = []): array
    {
        return $options + ['dotenv' => false, 'tags' => ['code' => new LiteralTag(function (string $code): string {
            $this->calls[] = $code;

            return strtoupper($code);
        })]];
    }

    /**
     * The error loading `$source` fails with, which must be the refusal.
     *
     * @param array<string, mixed> $options
     */
    private function refusal(string $source, array $options = []): CooperError
    {
        $e = self::loadError($source, $this->options($options));
        self::assertStringContainsString('!code(...) takes only a string literal written in the document', $e->getMessage());

        return $e;
    }
}

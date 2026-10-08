<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Tests;

use JOetjen\Cooper\Cooper;
use JOetjen\Cooper\EnvReference;
use JOetjen\Cooper\Tests\Support\CooperTestCase;
use JOetjen\Cooper\Value\CooperSecret;

/**
 * The `envValue` load option: an application deciding what a `${NAME}`
 * written as a value resolves to -- a framework keeping it a runtime
 * placeholder instead of the value the environment holds at load time.
 */
final class EnvReferenceTest extends CooperTestCase
{
    public function testWithoutTheOptionAnEnvironmentReferenceResolvesAsItAlwaysHas(): void
    {
        self::assertSame(['a' => 'x', 'p' => 80], self::load("a = \${X}\np = !int(\${P})", ['env' => ['X' => 'x', 'P' => '80']]));
    }

    public function testTheOptionDecidesWhatAReferenceWrittenAsAValueResolvesTo(): void
    {
        $config = self::load('a = ${X}', ['envValue' => static fn (EnvReference $ref): string => "<{$ref->name}>"]);

        self::assertSame(['a' => '<X>'], $config);
    }

    public function testTheReferenceDescribesItsNameListIndexSuffixAndFilters(): void
    {
        $seen = [];
        self::load(
            "a = \${A}\nb = \${B[]}\nc = \${C[1]}\nd = \${D:\"dflt\"}\ne = \${E:+\"alt\"}\nf = \${F:?\"needed\"}\ng = \${G | trim | downcase}",
            ['envValue' => static function (EnvReference $ref) use (&$seen): string {
                $seen[$ref->name] = [$ref->list, $ref->index, $ref->suffix, $ref->filters, $ref->tag];

                return '';
            }],
        );

        self::assertSame([
            'A' => [false, null, null, [], null],
            'B' => [true, null, null, [], null],
            'C' => [false, 1, null, [], null],
            'D' => [false, null, EnvReference::DEFAULT, [], null],
            'E' => [false, null, EnvReference::SUBSTITUTE, [], null],
            'F' => [false, null, EnvReference::REQUIRED, [], null],
            'G' => [false, null, null, ['trim', 'downcase'], null],
        ], $seen);
    }

    public function testASuffixValueIsResolvedWhenAskedFor(): void
    {
        $config = self::load(
            "@v = 1\na = \${A:\"d@{v}\"}\nb = \${B:?\"set B\"}",
            ['envValue' => static fn (EnvReference $ref): mixed => $ref->suffixValue()],
        );

        self::assertSame(['a' => 'd1', 'b' => 'set B'], $config);
    }

    public function testATagWrittenDirectlyAroundTheReferenceIsLeftToTheOption(): void
    {
        $tags = [];
        $config = self::load(
            "p = !int(\${PORT})\nq = !duration(\"\${T}\")",
            ['envValue' => static function (EnvReference $ref) use (&$tags): string {
                $tags[$ref->name] = $ref->tag;

                return $ref->tag === null ? '5s' : "{$ref->tag}:{$ref->name}";
            }],
        );

        // `!duration("${T}")` tags a string holding a reference, not the
        // reference itself: the reference is asked for untagged, and the
        // tag then applies to what the option gave back.
        self::assertSame(['PORT' => 'int', 'T' => null], $tags);
        self::assertSame('int:PORT', $config['p']);
        self::assertSame(5_000_000_000, $config['q']->nanoseconds);
    }

    public function testResolvingGivesTheValueCooperWouldHaveGiven(): void
    {
        $config = self::load(
            "p = !int(\${PORT})\nd = \${D:\"dflt\"}\ng = \${G | upcase}\nl = \${L[]}",
            [
                'env' => ['PORT' => '80', 'G' => 'abc', 'L' => 'a, b'],
                'envValue' => static fn (EnvReference $ref, \Closure $resolve): mixed => $resolve(),
            ],
        );

        self::assertSame(['p' => 80, 'd' => 'dflt', 'g' => 'ABC', 'l' => ['a', 'b']], $config);
    }

    public function testAReferenceInsideAStringIsAskedForAndJoinedIntoIt(): void
    {
        $config = self::load('url = "http://${HOST}:${PORT}/"', ['envValue' => static fn (EnvReference $ref): string => "[{$ref->name}]"]);

        self::assertSame(['url' => 'http://[HOST]:[PORT]/'], $config);
    }

    public function testASecretKeepsItsSecrecyAroundWhatTheOptionGaveBack(): void
    {
        $config = self::load('*pw = ${PW}', ['envValue' => static fn (EnvReference $ref): string => "[{$ref->name}]"]);

        self::assertInstanceOf(CooperSecret::class, $config['pw']);
        self::assertSame('[PW]', $config['pw']->reveal());
    }

    public function testAReferenceThatShapesTheDocumentIsNeverAskedFor(): void
    {
        $asked = [];
        $config = self::load(
            "\${?FLAG} on = true\n\"k_\${K}\" = 1\nv = \${V}",
            [
                'env' => ['FLAG' => '1', 'K' => 'key', 'V' => 'v'],
                'envValue' => static function (EnvReference $ref) use (&$asked): string {
                    $asked[] = $ref->name;

                    return 'placeholder';
                },
            ],
        );

        self::assertSame(['V'], $asked);
        self::assertSame(['on' => true, 'k_key' => 1, 'v' => 'placeholder'], $config);
    }

    public function testATracedLoadRecordsOnlyTheNamesWhoseValueWasRead(): void
    {
        $dir = sys_get_temp_dir() . '/cooper-envvalue-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("{$dir}/main.casc", "#@version = 1.0\n\${?GUARD} g = 1\na = \${KEPT}\nb = \${READ}\n");

        try {
            $trace = Cooper::loadFileTraced("{$dir}/main.casc", [
                'dotenv' => false,
                'cache' => false,
                'env' => ['GUARD' => '1', 'KEPT' => 'k', 'READ' => 'r'],
                'envValue' => static fn (EnvReference $ref, \Closure $resolve): mixed => $ref->name === 'READ' ? $resolve() : 'placeholder',
            ]);
        } finally {
            unlink("{$dir}/main.casc");
            rmdir($dir);
        }

        self::assertSame(['g' => 1, 'a' => 'placeholder', 'b' => 'r'], $trace->config);
        self::assertArrayNotHasKey('KEPT', $trace->env);
        self::assertSame('r', $trace->env['READ']);
        self::assertSame('1', $trace->env['GUARD']);
    }

    public function testAnOptionThatIsNotCallableIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('option "envValue" must be a callable');

        self::load('a = 1', ['envValue' => 'no such function']);
    }
}

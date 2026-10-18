<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Tests;

use JOetjen\Cooper\ConformanceAdapter;
use JOetjen\Cooper\ConformanceModule;
use JOetjen\Cooper\Node\MapNode;
use JOetjen\Cooper\Value\CooperAtom;
use JOetjen\Cooper\Value\CooperBytes;
use JOetjen\Cooper\Value\CooperDate;
use JOetjen\Cooper\Value\CooperDateTime;
use JOetjen\Cooper\Value\CooperDuration;
use JOetjen\Cooper\Value\CooperInteger;
use JOetjen\Cooper\Value\CooperIPv4;
use JOetjen\Cooper\Value\CooperIPv6;
use JOetjen\Cooper\Value\CooperLocalDateTime;
use JOetjen\Cooper\Value\CooperSecret;
use JOetjen\Cooper\Value\CooperTime;
use JOetjen\Cooper\Value\CooperTuple;
use PHPUnit\Framework\TestCase;

/**
 * The adapter the Cooper Conformance Suite runs this implementation
 * through: a JSON request in, a JSON answer out, every value in the
 * suite's typed JSON, every error as its CASC.md §9.5 category. Mirrors
 * the Elixir reference's `test/cooper/conformance_test.exs`.
 */
final class ConformanceAdapterTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/fixtures/conformance_adapter';

    /**
     * @return array{id: string, operation: string, document: string}
     */
    private static function load(string $name): array
    {
        return ['id' => "c: {$name}", 'operation' => 'load', 'document' => self::FIXTURES . "/{$name}"];
    }

    /**
     * @return array{tuple: list<mixed>}
     */
    private static function tagged(string $tag, mixed ...$items): array
    {
        return ['tuple' => [['keyword' => $tag], ...$items]];
    }

    // ---- a value in the suite's typed JSON -------------------------------------

    public function testAStringABooleanAndNullAreThemselves(): void
    {
        self::assertSame(['s', true, false, null], ConformanceAdapter::encode(['s', true, false, null]));
    }

    public function testANumberIsTypedAsText(): void
    {
        self::assertSame(['integer' => '42'], ConformanceAdapter::encode(42));
        self::assertSame(['float' => '2.0'], ConformanceAdapter::encode(2.0));
        self::assertSame(
            ['integer' => '123456789012345678901'],
            ConformanceAdapter::encode(new CooperInteger('123456789012345678901')),
        );
    }

    /**
     * Elixir's `Float.to_string/1`: the shortest digits that read back as
     * the same float, written whichever way is shorter -- plainly, or
     * with an exponent -- and plainly on a tie; from 2^53 up, where a
     * float is always a whole number, always with an exponent.
     */
    public function testAFloatIsWrittenAsTheReferenceWritesIt(): void
    {
        $cases = [
            '2.0' => 2.0,
            '0.1' => 0.1,
            '100.0' => 100.0,
            '1.0e3' => 1000.0,
            '1234.0' => 1234.0,
            '1.23e4' => 12300.0,
            '1.0e5' => 100000.0,
            '0.0001' => 0.0001,
            '1.25e-4' => 1.25e-4,
            '1.0e-5' => 1.0e-5,
            '1.0e25' => 1.0e25,
            '1.2345678901234568e16' => 12345678901234567.0,
            '1234567890123456.0' => 1234567890123456.0,
            '9007199254740991.0' => 9007199254740991.0,
            '9.007199254740992e15' => 9007199254740992.0,
            '123456789.123' => 123456789.123,
            '-0.0' => -0.0,
            '-3.5' => -3.5,
            '5.0e-324' => 5.0e-324,
            '1.79e308' => 1.79e308,
        ];
        foreach ($cases as $text => $float) {
            self::assertSame(['float' => (string) $text], ConformanceAdapter::encode($float), "for {$text}");
        }
    }

    public function testEveryFloatsTextReadsBackAsTheSameFloatAndKeepsAFractionOrAnExponent(): void
    {
        mt_srand(20261018);
        for ($i = 0; $i < 2000; $i++) {
            $bits = pack('J', (mt_rand(0, 0x7FFFFFFF) << 32) | mt_rand(0, 0xFFFFFFFF));
            $float = unpack('E', $bits)[1];
            if (!is_finite($float)) {
                continue;
            }
            $encoded = ConformanceAdapter::encode($float);
            self::assertIsArray($encoded);
            $text = $encoded['float'];
            self::assertSame($float, (float) $text, "{$text} reads back");
            self::assertMatchesRegularExpression('/\A-?\d+(\.\d+)?(e-?\d+)?\z/', $text);
            self::assertMatchesRegularExpression('/[.e]/', $text, "{$text} keeps a fraction or an exponent");
        }
    }

    public function testAnAtomIsAKeywordAListAnArrayATupleATuple(): void
    {
        self::assertSame(
            [['keyword' => 'info'], ['tuple' => [['integer' => '1'], ['keyword' => 'x']]]],
            ConformanceAdapter::encode([CooperAtom::of('info'), new CooperTuple([1, CooperAtom::of('x')])]),
        );
    }

    public function testAMapIsAListOfPairsInKeyOrder(): void
    {
        self::assertSame(
            ['map' => [['a', ['integer' => '2']], ['b', ['integer' => '1']]]],
            ConformanceAdapter::encode(new MapNode(['b' => 1, 'a' => 2])),
        );
    }

    public function testAMapWhoseKeysLookNumericKeepsThemAsStrings(): void
    {
        self::assertSame(
            ['map' => [['0', true], ['10', false], ['9', null]]],
            ConformanceAdapter::encode(new MapNode(['9' => null, '10' => false, '0' => true])),
        );
    }

    public function testAnEmptyMapIsAMapAndAnEmptyListAnArray(): void
    {
        self::assertSame(['map' => []], ConformanceAdapter::encode(new MapNode()));
        self::assertSame([], ConformanceAdapter::encode([]));
    }

    public function testADurationAndAByteSizeAreTaggedWithTheirBaseUnit(): void
    {
        self::assertSame(
            self::tagged('casc/duration', ['integer' => '300000000000']),
            ConformanceAdapter::encode(new CooperDuration(300_000_000_000)),
        );
        self::assertSame(
            self::tagged('casc/bytes', ['integer' => '512']),
            ConformanceAdapter::encode(new CooperBytes(512)),
        );
        self::assertSame(
            self::tagged('casc/bytes', ['integer' => '123456789012345678901']),
            ConformanceAdapter::encode(new CooperBytes(new CooperInteger('123456789012345678901'))),
        );
    }

    public function testASecretIsTaggedAndCarriesItsShownFormWhenPartlyRedacted(): void
    {
        self::assertSame(self::tagged('casc/secret', 's'), ConformanceAdapter::encode(new CooperSecret('s')));
        self::assertSame(
            self::tagged('casc/secret', 'http://a', 'http://[~~REDACTED~~]'),
            ConformanceAdapter::encode(new CooperSecret('http://a', 'http://[~~REDACTED~~]')),
        );
    }

    public function testAModuleIsTaggedWithItsNameAsCascWritesIt(): void
    {
        self::assertSame(
            self::tagged('casc/module', 'Foo.Bar'),
            ConformanceAdapter::encode(new ConformanceModule('Foo\\Bar')),
        );
    }

    public function testInfinityIsATaggedFloat(): void
    {
        self::assertSame(self::tagged('casc/float', 'inf'), ConformanceAdapter::encode(INF));
        self::assertSame(self::tagged('casc/float', '-inf'), ConformanceAdapter::encode(-INF));
    }

    public function testAnAddressIsTaggedWithItsCanonicalText(): void
    {
        self::assertSame(self::tagged('casc/ipv4', '10.0.0.1'), ConformanceAdapter::encode(CooperIPv4::parse('10.0.0.1')));
        self::assertSame(self::tagged('casc/ipv4', '10.0.0.0/8'), ConformanceAdapter::encode(CooperIPv4::parse('10.0.0.0/8')));
        self::assertSame(self::tagged('casc/ipv6', '2001:db8::1'), ConformanceAdapter::encode(CooperIPv6::parse('2001:0db8:0:0:0:0:0:1')));
    }

    public function testDatesAndTimesAreTaggedIso8601(): void
    {
        self::assertSame(self::tagged('casc/date', '2026-10-18'), ConformanceAdapter::encode(new CooperDate(2026, 10, 18)));
        self::assertSame(self::tagged('casc/time', '07:32:00'), ConformanceAdapter::encode(new CooperTime(7, 32, 0)));
        self::assertSame(
            self::tagged('casc/local-date-time', '2026-10-18T07:32:00'),
            ConformanceAdapter::encode(new CooperLocalDateTime(new CooperDate(2026, 10, 18), new CooperTime(7, 32, 0))),
        );
        self::assertSame(
            self::tagged('casc/date-time', '2026-10-18T07:32:00Z'),
            ConformanceAdapter::encode(new CooperDateTime(new \DateTimeImmutable('2026-10-18T07:32:00Z'))),
        );
    }

    public function testAFractionalSecondKeepsTheWrittenPrecision(): void
    {
        self::assertSame(self::tagged('casc/time', '07:32:00.120'), ConformanceAdapter::encode(new CooperTime(7, 32, 0, 120000, 3)));
        self::assertSame(
            self::tagged('casc/date-time', '2026-10-18T07:32:00.120Z'),
            ConformanceAdapter::encode(new CooperDateTime(new \DateTimeImmutable('2026-10-18T07:32:00.12Z'), 3)),
        );
    }

    public function testAValueTheSuiteHasNoTypeForIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ConformanceAdapter::encode(new \stdClass());
    }

    // ---- answering a request ---------------------------------------------------

    public function testADocumentThatLoadsAnswersItsResult(): void
    {
        $answer = ConformanceAdapter::answer(['cases' => [self::load('plain.casc')]]);

        self::assertSame(
            ['results' => [[
                'id' => 'c: plain.casc',
                'result' => ConformanceAdapter::encode(new MapNode([
                    'a' => 1,
                    'wait' => new CooperDuration(300_000_000_000),
                    'region' => 'eu-west',
                    'pw' => new CooperSecret('s'),
                    't' => new CooperTuple([1, CooperAtom::of('x')]),
                ])),
            ]]],
            $answer,
        );
    }

    public function testADocumentThatIsRefusedAnswersItsCategory(): void
    {
        self::assertSame(
            ['results' => [['id' => 'c: refused.casc', 'error' => 'resolve']]],
            ConformanceAdapter::answer(['cases' => [self::load('refused.casc')]]),
        );
    }

    public function testADocumentThatIsMissingIsAnImportError(): void
    {
        self::assertSame(
            ['results' => [['id' => 'c: missing.casc', 'error' => 'import']]],
            ConformanceAdapter::answer(['cases' => [self::load('missing.casc')]]),
        );
    }

    public function testAModuleInADocumentAnswersTheNameAsWrittenAndReadsSoInAString(): void
    {
        $answer = ConformanceAdapter::answer(['cases' => [self::load('module.casc')]]);

        self::assertSame(
            ['results' => [[
                'id' => 'c: module.casc',
                'result' => ['map' => [
                    ['m', self::tagged('casc/module', 'Acme.Payments.StripeClient')],
                    ['n', self::tagged('casc/module', 'Foo.Bar')],
                    ['s', 'n=Foo.Bar'],
                    ['t', 'x-Foo.Bar'],
                ]],
            ]]],
            $answer,
        );
    }

    public function testTheSuitesExtensionPointsAreRegistered(): void
    {
        $answer = ConformanceAdapter::answer(['cases' => [self::load('extensions.casc')]]);

        self::assertSame(
            ['results' => [[
                'id' => 'c: extensions.casc',
                'result' => ['map' => [
                    ['echoed', 'hello'],
                    ['from_mem', true],
                    ['mem_var', 'from-mem'],
                    ['n', ['integer' => '42']],
                    ['s', 'abab'],
                    ['shared', 'mem'],
                ]],
            ]]],
            $answer,
        );
    }

    public function testTheFailResolverRefusesAsAResolveError(): void
    {
        self::assertSame(
            ['results' => [['id' => 'c: fail.casc', 'error' => 'resolve']]],
            ConformanceAdapter::answer(['cases' => [self::load('fail.casc')]]),
        );
    }

    public function testAnOperationItDoesNotKnowIsAnErrorNamingIt(): void
    {
        self::assertSame(
            ['results' => [['id' => 'x', 'error' => 'unknown operation: "dance"']]],
            ConformanceAdapter::answer(['cases' => [['id' => 'x', 'operation' => 'dance']]]),
        );
    }

    public function testACrashOnOneCaseIsThatCasesAnswerAndTheRestAreStillAnswered(): void
    {
        $answer = ConformanceAdapter::answer(['cases' => [
            ['id' => 'odd', 'operation' => 'load', 'document' => 42],
            self::load('refused.casc'),
        ]]);

        self::assertSame('odd', $answer['results'][0]['id']);
        self::assertStringStartsWith('crash: ', $answer['results'][0]['error'] ?? '');
        self::assertSame(['id' => 'c: refused.casc', 'error' => 'resolve'], $answer['results'][1]);
    }

    public function testTheResultsFollowTheOrderOfTheCases(): void
    {
        $answer = ConformanceAdapter::answer(['cases' => [self::load('refused.casc'), self::load('plain.casc')]]);

        self::assertSame(['c: refused.casc', 'c: plain.casc'], array_column($answer['results'], 'id'));
    }

    // ---- the files -------------------------------------------------------------

    public function testARequestFileIsAnsweredInAnAnswerFile(): void
    {
        $dir = sys_get_temp_dir() . '/cooper-conformance-' . bin2hex(random_bytes(6));
        mkdir($dir);
        $request = "{$dir}/request.json";
        $answer = "{$dir}/answer.json";
        file_put_contents($request, json_encode(['cases' => [self::load('refused.casc')]], JSON_THROW_ON_ERROR));

        try {
            ConformanceAdapter::run($request, $answer);

            self::assertSame(
                ['results' => [['id' => 'c: refused.casc', 'error' => 'resolve']]],
                json_decode((string) file_get_contents($answer), true, flags: JSON_THROW_ON_ERROR),
            );
        } finally {
            @unlink($request);
            @unlink($answer);
            rmdir($dir);
        }
    }

    public function testARequestThatCannotBeReadIsAFailureOfTheRun(): void
    {
        $this->expectException(\RuntimeException::class);
        ConformanceAdapter::run(self::FIXTURES . '/no-such-request.json', sys_get_temp_dir() . '/unused-answer.json');
    }

    public function testARequestThatIsNotJsonIsAFailureOfTheRun(): void
    {
        $this->expectException(\RuntimeException::class);
        ConformanceAdapter::run(self::FIXTURES . '/plain.casc', sys_get_temp_dir() . '/unused-answer.json');
    }

    public function testARequestWithoutCasesIsAFailureOfTheRun(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ConformanceAdapter::answer(['nope' => []]);
    }

    // ---- the command -----------------------------------------------------------

    /**
     * Runs `bin/cooper-conformance` with `$arguments`.
     *
     * @param list<string> $arguments
     * @return array{0: int, 1: string} the exit status and what went to stderr
     */
    private static function command(array $arguments): array
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/bin/cooper-conformance', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stderr];
    }

    public function testTheCommandWritesTheAnswerAndExitsZeroWhateverTheCasesAnswered(): void
    {
        $dir = sys_get_temp_dir() . '/cooper-conformance-' . bin2hex(random_bytes(6));
        mkdir($dir);
        $request = "{$dir}/request.json";
        $answer = "{$dir}/answer.json";
        file_put_contents($request, json_encode(['cases' => [self::load('refused.casc'), self::load('plain.casc')]], JSON_THROW_ON_ERROR));

        try {
            self::assertSame(0, self::command([$request, $answer])[0]);
            $decoded = json_decode((string) file_get_contents($answer), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            self::assertSame(['id' => 'c: refused.casc', 'error' => 'resolve'], $decoded['results'][0]);
            self::assertSame('c: plain.casc', $decoded['results'][1]['id']);
            self::assertArrayHasKey('result', $decoded['results'][1]);
        } finally {
            @unlink($request);
            @unlink($answer);
            rmdir($dir);
        }
    }

    public function testTheCommandExitsNonZeroWhenTheRequestCannotBeRead(): void
    {
        [$status, $stderr] = self::command([self::FIXTURES . '/no-such-request.json', sys_get_temp_dir() . '/unused-answer.json']);

        self::assertSame(1, $status);
        self::assertStringContainsString('cannot read the request', $stderr);
    }

    public function testTheCommandExitsNonZeroWhenTheAnswerCannotBeWritten(): void
    {
        $request = tempnam(sys_get_temp_dir(), 'cooper-conformance-');
        self::assertIsString($request);
        file_put_contents($request, '{"cases":[]}');

        try {
            [$status, $stderr] = self::command([$request, self::FIXTURES . '/no-such-directory/answer.json']);

            self::assertSame(1, $status);
            self::assertStringContainsString('cannot write the answer', $stderr);
        } finally {
            unlink($request);
        }
    }

    public function testTheCommandWantsARequestAndAnAnswer(): void
    {
        [$status, $stderr] = self::command(['only-one']);

        self::assertSame(2, $status);
        self::assertStringContainsString('usage: cooper-conformance REQUEST ANSWER', $stderr);
    }
}

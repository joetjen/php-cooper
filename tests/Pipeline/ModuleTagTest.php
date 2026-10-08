<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Tests\Pipeline;

use JOetjen\Cooper\Cooper;
use JOetjen\Cooper\CooperError;
use JOetjen\Cooper\Tests\Support\CooperTestCase;

/**
 * `!module` (CASC.md §7.5): a module name is written the same way for
 * every Cooper implementation -- dot-separated PascalCase -- and each
 * resolves it in two steps: the application's `modules` mapping, by the
 * name exactly as written, then the host's convention. PHP's convention
 * is a class-string: `Acme.Payments` -> `Acme\Payments`. Ported from the
 * Elixir suite's `module_tag_test.exs`.
 */
final class ModuleTagTest extends CooperTestCase
{
    private const NOT_PASCAL_CASE = 'not a dot-separated PascalCase module name';

    // ---- the convention --------------------------------------------------------

    public function testADotSeparatedPascalCaseNameBecomesTheClassStringOfThatName(): void
    {
        self::assertSame(['a' => 'Acme\\Payments\\StripeClient'], self::resolve('a = !module("Acme.Payments.StripeClient")'));
    }

    public function testASingleSegmentNameIsItsOwnClassString(): void
    {
        self::assertSame(['a' => 'Cooper'], self::resolve('a = !module("Cooper")'));
    }

    public function testSegmentsAreKeptExactlyAsWrittenRunsOfCapitalsIncluded(): void
    {
        self::assertSame(['a' => 'ASCO\\HTTPClient'], self::resolve('a = !module("ASCO.HTTPClient")'));
    }

    public function testAClassStringHasNoLeadingBackslash(): void
    {
        $class = self::resolve('a = !module("Acme.Client")')['a'];

        self::assertIsString($class);
        self::assertStringStartsNotWith('\\', $class);
    }

    public function testSurroundingWhitespaceIsTrimmedBeforeTheNameIsRead(): void
    {
        self::assertSame(['a' => 'DateTimeImmutable'], self::resolve('a = !module("  DateTimeImmutable  ")'));
    }

    public function testTheResultIsUsableAsAClassName(): void
    {
        $class = self::resolve('a = !module("ArrayObject")')['a'];

        self::assertInstanceOf(\ArrayObject::class, new $class());
    }

    public function testANameMayComeFromTheEnvironment(): void
    {
        self::assertSame(['a' => 'App\\Client'], self::resolve('a = !module("${CLIENT}")', ['env' => ['CLIENT' => 'App.Client']]));
    }

    public function testAModuleMayStandInsideAList(): void
    {
        self::assertSame(['a' => ['A\\B', 'C']], self::resolve('a = [!module("A.B"), !module("C")]'));
    }

    public function testAClassThatDoesNotExistIsNeitherCheckedNorAutoloadedAtLoad(): void
    {
        $asked = [];
        $autoloader = static function (string $class) use (&$asked): void {
            $asked[] = $class;
        };
        spl_autoload_register($autoloader);

        try {
            $result = self::resolve('a = !module("No.Such.ClassAnywhere")');
        } finally {
            spl_autoload_unregister($autoloader);
        }

        self::assertSame(['a' => 'No\\Such\\ClassAnywhere'], $result);
        self::assertSame([], $asked);
    }

    // ---- the mapping -----------------------------------------------------------

    public function testAMappedNameResolvesToWhatTheMappingHolds(): void
    {
        self::assertSame(
            ['a' => \ArrayObject::class],
            self::resolve('a = !module("Store")', ['modules' => ['Store' => \ArrayObject::class]]),
        );
    }

    public function testTheMappingMayHoldAnyValueNotOnlyAClassString(): void
    {
        $factory = new \stdClass();

        self::assertSame(['a' => $factory], self::resolve('a = !module("Factory")', ['modules' => ['Factory' => $factory]]));
    }

    public function testTheMappingIsConsultedByTheNameAfterTrimming(): void
    {
        self::assertSame(['a' => 'Mapped'], self::resolve('a = !module("  Store  ")', ['modules' => ['Store' => 'Mapped']]));
    }

    public function testTheMappingIsConsultedByTheExactCaseOfTheWrittenName(): void
    {
        self::assertSame(
            ['a' => 'STORE', 'b' => 'Mapped'],
            self::resolve('a = !module("STORE")' . "\n" . 'b = !module("Store")', ['modules' => ['Store' => 'Mapped']]),
        );
    }

    public function testANameTheMappingDoesNotHoldFallsBackToTheConvention(): void
    {
        self::assertSame(
            ['a' => 'Mapped', 'b' => 'Acme\\Other'],
            self::resolve('a = !module("Acme.Store")' . "\n" . 'b = !module("Acme.Other")', ['modules' => ['Acme.Store' => 'Mapped']]),
        );
    }

    public function testLoadStringTakesTheModulesOption(): void
    {
        self::assertSame(['a' => 'Mapped', 'b' => 'Acme\\Other'], self::load(
            'a = !module("Store")' . "\n" . 'b = !module("Acme.Other")',
            ['modules' => ['Store' => 'Mapped']],
        ));
    }

    public function testACachedLoadFileTakesEachCallsOwnModulesOption(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cooper-module-') . '.casc';
        file_put_contents($file, self::HEADER . 'a = !module("Store")' . "\n");

        try {
            $first = Cooper::loadFile($file, ['dotenv' => false, 'modules' => ['Store' => 'First']]);
            $second = Cooper::loadFile($file, ['dotenv' => false, 'modules' => ['Store' => 'Second']]);
            $unmapped = Cooper::loadFile($file, ['dotenv' => false]);
        } finally {
            unlink($file);
        }

        self::assertSame(['a' => 'First'], $first);
        self::assertSame(['a' => 'Second'], $second);
        self::assertSame(['a' => 'Store'], $unmapped);
    }

    public function testAModulesOptionThatIsNotAnArrayIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('option "modules" must be an array of name => value');

        Cooper::loadString(self::HEADER, ['modules' => 'Store']);
    }

    public function testAModulesOptionKeyedByANonStringIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('option "modules" must be an array of name => value');

        Cooper::loadString(self::HEADER, ['modules' => ['Store']]);
    }

    // ---- rejections ------------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function namesThatAreNotPascalCase(): iterable
    {
        yield 'a lower-case name' => ['crypto'];
        yield 'a lower-case segment' => ['Foo.bar'];
        yield 'an underscore' => ['Foo_Bar'];
        yield 'a PHP namespace separator' => ['Foo\\\\Bar'];
        yield 'a leading backslash' => ['\\\\Foo'];
        yield 'a path' => ['./x.js'];
        yield 'a scheme' => ['node:fs'];
        yield 'an empty segment' => ['Foo..Bar'];
        yield 'a trailing dot' => ['Foo.'];
        yield 'a leading digit' => ['1Foo'];
        yield 'a space inside' => ['Foo Bar'];
        yield 'the empty string' => [''];
        yield 'only whitespace' => ['   '];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('namesThatAreNotPascalCase')]
    public function testANameThatIsNotDotSeparatedPascalCaseIsAResolveError(string $written): void
    {
        $error = self::resolveError("a = !module(\"{$written}\")");

        self::assertSame(CooperError::STAGE_RESOLVE, $error->stage);
        self::assertStringContainsString(self::NOT_PASCAL_CASE, $error->getMessage());
    }

    public function testANameThatIsNotPascalCaseIsAnErrorEvenWhenTheMappingHoldsIt(): void
    {
        $error = self::resolveError('a = !module("crypto")', ['modules' => ['crypto' => 'Crypto']]);

        self::assertSame(CooperError::STAGE_RESOLVE, $error->stage);
        self::assertStringContainsString(self::NOT_PASCAL_CASE, $error->getMessage());
    }

    public function testANonStringArgumentIsAnError(): void
    {
        $error = self::resolveError('a = !module(42)');

        self::assertSame(CooperError::STAGE_RESOLVE, $error->stage);
        self::assertStringContainsString('not a string', $error->getMessage());
    }

    public function testANameLongerThan512BytesIsAnError(): void
    {
        self::assertStringContainsString('longer than 512 bytes', self::resolveError('a = !module("' . str_repeat('A', 513) . '")')->getMessage());
    }

    public function testANameOfExactly512BytesIsAccepted(): void
    {
        $name = str_repeat('A', 512);

        self::assertSame(['a' => $name], self::resolve("a = !module(\"{$name}\")"));
    }
}

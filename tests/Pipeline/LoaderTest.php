<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Tests\Pipeline;

use JOetjen\Cooper\Cooper;
use JOetjen\Cooper\CooperError;
use JOetjen\Cooper\Pipeline\Grammar;
use JOetjen\Cooper\Pipeline\Native;
use JOetjen\Cooper\Tests\Support\CooperTestCase;
use JOetjen\Cooper\Value\CooperAtom;

/**
 * CASC.md §5.1 imports and §5.2 variable visibility across them --
 * ported from the Elixir suite's `loader_test.exs` and
 * `import_interpolation_test.exs`.
 */
final class LoaderTest extends CooperTestCase
{
    public function testABareImportResolvesRelativeToTheImporterAndLaterStatementsWin(): void
    {
        $result = Native::convert(Grammar::runFile(self::fixture('imports', 'main.casc'), ['env' => []]));
        assert(is_array($result));

        self::assertSame(['host' => '0.0.0.0', 'port' => 9090], $result['server']);
        self::assertArrayHasKey('name', $result['app']);
    }

    public function testAPublicVariableCrossesTheImportBoundaryAPrivateOneDoesNot(): void
    {
        $ctx = Grammar::initialContext(['root' => self::fixture('imports'), 'file' => self::fixture('imports', 'main.casc'), 'env' => []]);
        Grammar::runWithContext((string) file_get_contents(self::fixture('imports', 'main.casc')), $ctx);

        self::assertSame(['shared', true], $ctx->vars['shared_name']);
        self::assertArrayNotHasKey('local_only', $ctx->vars);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function visibility(): array
    {
        return Cooper::loadFile(self::fixture('visibility', 'main.casc'), ['cache' => false, 'dotenv' => false]);
    }

    public function testAPrivateVariableIsUsableInItsOwnFile(): void
    {
        self::assertSame('entry-private', self::visibility()['entry']['own_private']);
        self::assertSame('shared-private', self::visibility()['shared']['own_private']);
    }

    public function testPrivateVariablesNeverCrossAFileBoundary(): void
    {
        self::assertSame('MISSING', self::visibility()['shared']['from_importer_private']);
        self::assertSame('MISSING', self::visibility()['entry']['imported_private']);
    }

    public function testPublicVariablesTravelUpAndDown(): void
    {
        self::assertSame('shared-public', self::visibility()['entry']['imported_public']);
        self::assertSame('entry-public', self::visibility()['shared']['from_importer_public']);
    }

    public function testOnePrivateVariableReadsAnother(): void
    {
        // A reference inside a private declaration's own value belongs to
        // the declaring file too; the reference once left it unattributed.
        self::assertSame(['x' => 'v1'], self::load("@*a = 1\n@*b = \"v@{a}\"\nx = @{b}"));
    }

    public function testAPrivateVariableShadowsAnImportedPublicOneOfTheSameNameInThisFileOnly(): void
    {
        // CASC.md §5.2: inside its file a private declaration *shadows* a
        // public one; it does not remove it for the file that declared it.
        $source = "import \"shadow_child.casc\"\n@*name = \"private\"\nx = @{name}";

        self::assertSame(['child' => 'public', 'x' => 'private'], self::load($source, ['root' => self::fixture('visibility'), 'file' => self::fixture('visibility', 'entry.casc')]));
    }

    public function testIdenticallyNamedPrivatesStayIndependent(): void
    {
        self::assertSame('shared-private', self::visibility()['shared']['own_private']);
        self::assertSame('other-private', self::visibility()['other']['own_private']);
    }

    public function testBraceAndGlobExpansionLoadInLexicographicOrder(): void
    {
        self::assertSame(
            ['val' => 'dev-z', 'order_marker' => 'last'],
            Native::convert(Grammar::runFile(self::fixture('imports', 'glob_main.casc'), ['env' => []])),
        );
    }

    public function testAGuardInAnImportedFileSeesTheImportersEnvironment(): void
    {
        $main = self::fixture('import_env_guard', 'main.casc');

        self::assertSame(['name' => 'base'], Native::convert(Grammar::runFile($main, ['env' => []])));
        self::assertSame(['enabled' => true, 'name' => 'base'], Native::convert(Grammar::runFile($main, ['env' => ['FLAG' => '1']])));
    }

    public function testACycleBackToTheEntryFileIsCaughtOnTheFirstRepeat(): void
    {
        try {
            Grammar::runFile(self::fixture('imports', 'cycle_a.casc'), ['env' => []]);
            self::fail('expected an import cycle');
        } catch (CooperError $e) {
            self::assertSame(CooperError::STAGE_IMPORT, $e->stage);
            self::assertStringContainsString('cycle_a.casc', $e->getMessage());
            self::assertStringContainsString('cycle_b.casc', $e->getMessage());
            self::assertDoesNotMatchRegularExpression('/cycle_b\.casc -> \S*cycle_b\.casc/', $e->getMessage());
        }
    }

    public function testFailureCases(): void
    {
        self::assertStringContainsString('unregistered import scheme "myscheme"', self::mergedError('import "myscheme://foo"')->getMessage());
        self::assertStringContainsString('matched no files', self::mergedError('import "does/not/exist/*.casc"', ['root' => self::fixture('imports')])->getMessage());
        self::assertStringContainsString('may interpolate only', self::mergedError('import "@{some_var}"')->getMessage());
    }

    public function testAnErrorInAnImportedFileNamesThatFile(): void
    {
        $dir = $this->scratchDir('import_error');
        file_put_contents("{$dir}/bad.casc", "#@version = 1.0\nv = = 1\n");

        $error = self::mergedError('import "bad.casc"', ['root' => $dir]);

        self::assertSame(CooperError::STAGE_PARSER, $error->stage);
        self::assertSame("{$dir}/bad.casc", $error->sourceFile);
        self::assertSame(2, $error->sourceLine);
    }

    public function testASchemeLoaderFailureIsAnImportError(): void
    {
        $error = self::mergedError('import "vault://x"', ['importSchemes' => ['vault' => static fn (): string => throw new \RuntimeException('sealed')]]);

        self::assertSame(CooperError::STAGE_IMPORT, $error->stage);
        self::assertStringContainsString('vault:// loader failed for "x": sealed', $error->getMessage());
    }

    public function testASchemeImportOfItselfIsACycleLikeAFileImport(): void
    {
        $source = "#@version = 1.0\nimport \"loop://self\"\n";
        $error = self::mergedError('import "loop://self"', ['importSchemes' => ['loop' => static fn (string $rest): string => $source]]);

        self::assertSame(CooperError::STAGE_IMPORT, $error->stage);
        self::assertStringContainsString('cycle', $error->getMessage());
    }

    public function testASchemeImportCycleIsCaught(): void
    {
        $schemes = ['mem' => static fn (string $rest): string => "#@version = 1.0\nimport \"mem://{$rest}\"\n"];

        self::assertStringContainsString('import cycle detected', self::mergedError('import "mem://loop"', ['importSchemes' => $schemes])->getMessage());
    }

    // ---- ${NAME} in an import path --------------------------------------------

    private function overlayDir(): string
    {
        $dir = $this->scratchDir('import_interpolation');
        mkdir("{$dir}/env");
        file_put_contents("{$dir}/env/dev.casc", "#@version = 1.0\ndemo { from = dev }\n");
        file_put_contents("{$dir}/env/test.casc", "#@version = 1.0\ndemo { from = test }\n");

        return $dir;
    }

    /**
     * @param array<string, string> $env
     * @return array<array-key, mixed>
     */
    private function loadEntry(string $dir, string $source, array $env): array
    {
        file_put_contents("{$dir}/entry.casc", $source);

        return Cooper::loadFile("{$dir}/entry.casc", ['cache' => false, 'dotenv' => false, 'env' => $env]);
    }

    public function testAnEnvironmentVariableSelectsTheImport(): void
    {
        $dir = $this->overlayDir();
        $source = "#@version = 1.0\nimport \"env/\${APP_ENV:dev}.casc\"\n";

        self::assertSame(['demo' => ['from' => CooperAtom::of('test')]], $this->loadEntry($dir, $source, ['APP_ENV' => 'test']));
        self::assertSame(['demo' => ['from' => CooperAtom::of('dev')]], $this->loadEntry($dir, $source, ['APP_ENV' => '']));
        self::assertSame(['demo' => ['from' => CooperAtom::of('test')]], $this->loadEntry($dir, "#@version = 1.0\nimport \"env/test.casc\"\n", []));
    }

    public function testAnUnsetVariableWithoutDefaultIsAnError(): void
    {
        $dir = $this->overlayDir();

        try {
            $this->loadEntry($dir, "#@version = 1.0\nimport \"env/\${COOPER_TEST_UNSET_ENV}.casc\"\n", []);
            self::fail('expected an error');
        } catch (CooperError $e) {
            self::assertStringContainsString('unset and has no default', $e->getMessage());
        }
    }

    public function testAConfigReferenceCannotBeUsedInAnImportPath(): void
    {
        $dir = $this->overlayDir();

        try {
            $this->loadEntry($dir, "#@version = 1.0\nwhich = \"test\"\nimport \"env/%{which}.casc\"\n", []);
            self::fail('expected an error');
        } catch (CooperError $e) {
            self::assertStringContainsString('may interpolate only', $e->getMessage());
        }
    }

    public function testAMissingSelectedFileIsAnOrdinaryImportFailure(): void
    {
        $dir = $this->overlayDir();

        try {
            $this->loadEntry($dir, "#@version = 1.0\nimport \"env/\${APP_ENV:dev}.casc\"\n", ['APP_ENV' => 'staging']);
            self::fail('expected an error');
        } catch (CooperError $e) {
            self::assertStringContainsString('matched no files', $e->getMessage());
        }
    }

    public function testTransitivePublicVisibilityAndImportOrder(): void
    {
        self::assertSame(['value' => 'from-deepest'], Cooper::loadFile(self::fixture('transitive_imports', 'top.casc'), ['cache' => false, 'dotenv' => false]));
        self::assertSame(['value' => 'second'], Cooper::loadFile(self::fixture('import_order', 'main.casc'), ['cache' => false, 'dotenv' => false]));
    }
}

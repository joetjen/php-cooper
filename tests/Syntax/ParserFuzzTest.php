<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Tests\Syntax;

use JOetjen\Cooper\Cooper;
use JOetjen\Cooper\CooperError;
use PHPUnit\Framework\TestCase;

/**
 * Property-style (hand-rolled, per AGENTS.md): whatever the input, a
 * load either succeeds or throws `CooperError` -- never another
 * exception, a PHP warning (PHPUnit fails on those), or a hang.
 */
final class ParserFuzzTest extends TestCase
{
    private const FRAGMENTS = [
        'a', 'b.c', '"k"', "'q'", '*', '~', '+', '-', '#', '@', '@*', '=', ' ', "\n", ',', '.', ':', '|', '?',
        '{', '}', '[', ']', '(', ')', '${', '${?', '@{', '%{', '!{', '!', '"', '"""', "'",
        '1', '-2', '3.5', '0x1F', '1e3', 'nil', 'true', 'inf', '+inf', '1979-05-27', '07:32:00',
        '1979-05-27T07:32:00Z', '127.0.0.1', '::1', '1h30m', '512MiB', 'info', 'for', 'in', 'as', 'from',
        'import', '"@{x}"', '"${Y:d}"', '"%{a.b}"', '!int(', 'trim', '\\', 'µ', "\t",
    ];

    public function testRandomTokenSoupNeverEscapesCooperError(): void
    {
        mt_srand(8080);
        for ($run = 0; $run < 1500; $run++) {
            $source = "#@version = 1.0\n";
            $count = mt_rand(1, 25);
            for ($i = 0; $i < $count; $i++) {
                $source .= self::FRAGMENTS[mt_rand(0, count(self::FRAGMENTS) - 1)];
                if (mt_rand(0, 2) === 0) {
                    $source .= ' ';
                }
            }
            self::assertLoadsOrFailsCleanly($source);
        }
    }

    public function testMutatedValidDocumentsNeverEscapeCooperError(): void
    {
        $valid = <<<'CASC'
            #@version = 1.0
            @region = "eu-west"
            @ids = ["a", "b"]
            server { host = "0.0.0.0", port = 8080 }
            db.*password = ${DB_PASSWORD:"x"}
            for @i, @id in @{ids} from server as replicas."@{id}" { port = !int("9000") }
            url = "http://%{server.host}:%{server.port}/@{region | upcase}"
            tags = [1, 2.5, :true, (1, 2)]
            ~server { port = 9090 }
            -tags = [1]
            CASC;

        mt_srand(4040);
        for ($run = 0; $run < 1500; $run++) {
            $source = $valid;
            $edits = mt_rand(1, 4);
            for ($e = 0; $e < $edits; $e++) {
                $at = mt_rand(16, strlen($source));
                $source = match (mt_rand(0, 2)) {
                    0 => substr($source, 0, $at) . substr($source, $at + mt_rand(1, 5)),
                    1 => substr($source, 0, $at) . self::FRAGMENTS[mt_rand(0, count(self::FRAGMENTS) - 1)] . substr($source, $at),
                    default => substr($source, 0, $at) . chr(mt_rand(32, 126)) . substr($source, $at),
                };
            }
            self::assertLoadsOrFailsCleanly($source);
        }
    }

    private static function assertLoadsOrFailsCleanly(string $source): void
    {
        try {
            Cooper::loadString($source, ['dotenv' => false, 'env' => [], 'root' => sys_get_temp_dir() . '/php_cooper_fuzz_nonexistent']);
            self::assertTrue(true);
        } catch (CooperError $e) {
            self::assertNotSame('', $e->stage);
        } catch (\Throwable $e) {
            self::fail(get_class($e) . ': ' . $e->getMessage() . "\nfor input:\n" . $source);
        }
    }
}

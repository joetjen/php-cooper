<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Tests\Syntax;

use JOetjen\Cooper\CooperError;
use JOetjen\Cooper\Syntax\Lexer;
use JOetjen\Cooper\Syntax\Token;
use JOetjen\Cooper\Syntax\TokenType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The maximal-munch token race the Elixir grammar's tokenizer runs.
 */
final class LexerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function races(): iterable
    {
        yield 'keyword beats identifier on a tie' => ['nil', ['NIL_KW:nil']];
        yield 'longer identifier beats keyword' => ['nilly info', ['IDENT:nilly', 'IDENT:info']];
        yield 'infinity' => ['inf +inf -inf', ['INF_KW:inf', 'INF_KW:+inf', 'INF_KW:-inf']];
        yield 'append to a key starting with inf' => ['+info', ['+:+', 'IDENT:info']];
        yield 'identifier punctuation' => ['handle-otp+x ready! enabled?', ['IDENT:handle-otp+x', 'IDENT:ready!', 'IDENT:enabled?']];
        yield 'date beats integer' => ['1979-05-27', ['DATE:1979-05-27']];
        yield 'datetime' => ['1979-05-27T07:32:00.5+02:00', ['DATETIME:1979-05-27T07:32:00.5+02:00']];
        yield 'time beats integer' => ['07:32:00', ['TIME:07:32:00']];
        yield 'ipv4 beats float' => ['1.2.3.4/8', ['IPV4:1.2.3.4/8']];
        yield 'ipv6 forms' => ['::1 fe80::1/64 1:2:3:4:5:6:7:8', ['IPV6:::1', 'IPV6:fe80::1/64', 'IPV6:1:2:3:4:5:6:7:8']];
        yield 'an atom colon is not ipv6' => [':false', [':::', 'FALSE_KW:false']];
        yield 'durations' => ['500ms 1h30m 1.5h 1µs', ['DURATION:500ms', 'DURATION:1h30m', 'DURATION:1.5h', 'DURATION:1µs']];
        yield 'a duration takes digit separators' => ['1_000ms 1h3_0m', ['DURATION:1_000ms', 'DURATION:1h3_0m']];
        yield 'byte sizes, any case' => ['512MiB 10gb 1B', ['BYTES:512MiB', 'BYTES:10gb', 'BYTES:1B']];
        yield 'binary integer beats a byte size' => ['0b1010', ['INTEGER:0b1010']];
        yield 'numbers' => ['-17 0xDEAD_BEEF 5e+22 -2E-2', ['INTEGER:-17', 'INTEGER:0xDEAD_BEEF', 'FLOAT:5e+22', 'FLOAT:-2E-2']];
        yield 'version header folds a float' => ['#@version 1.0.0', ['#:#', '@:@', 'IDENT:version', 'FLOAT:1.0', '.:.', 'INTEGER:0']];
        yield 'reference openers' => ['${? ${ @{ %{', ['${?:${?', '${:${', '@{:@{', '%{:%{']];
        yield 'resolver payload nests braces' => ['!{vault:{a:{b}}}', ['RESOLVER_REF_RAW:!{vault:{a:{b}}}']];
        yield 'triple string beats an empty string' => ['"""x"""', ['TRIPLE_STRING:"""x"""']];
        yield 'strings' => ['"a\"b" \'c\'', ['DQ_STRING:"a\"b"', "SQ_STRING:'c'"]];
        yield 'comments are trivia' => ["# c\na # d\n#\nb", ['IDENT:a', 'IDENT:b']];
        yield 'a hash before a letter is a token' => ['#key #*s #@v', ['#:#', 'IDENT:key', '#:#', '*:*', 'IDENT:s', '#:#', '@:@', 'IDENT:v']];
    }

    /**
     * @param list<string> $expected TYPE:text per token
     */
    #[DataProvider('races')]
    public function testTokenRace(string $source, array $expected): void
    {
        $tokens = array_filter(Lexer::tokenize($source), static fn (Token $t): bool => $t->type !== TokenType::Eof);

        self::assertSame($expected, array_values(array_map(static fn (Token $t): string => "{$t->type->value}:{$t->text}", $tokens)));
    }

    public function testPositions(): void
    {
        $tokens = Lexer::tokenize("a\n  \"x\ny\" b");

        self::assertSame([1, 1, 1], [$tokens[0]->line, $tokens[0]->column, $tokens[0]->endLine]);
        self::assertSame([2, 3, 3], [$tokens[1]->line, $tokens[1]->column, $tokens[1]->endLine]);
        self::assertSame([3, 4], [$tokens[2]->line, $tokens[2]->column]);
    }

    public function testAnUnknownCharacter(): void
    {
        try {
            Lexer::tokenize("a\n  ^");
            self::fail('expected a lexer error');
        } catch (CooperError $e) {
            self::assertSame(CooperError::STAGE_LEXER, $e->stage);
            self::assertSame([2, 3], [$e->sourceLine, $e->sourceColumn]);
            self::assertSame('unexpected character "^"', $e->reason);
        }
    }
}

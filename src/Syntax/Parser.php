<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Syntax;

use JOetjen\Cooper\CooperError;
use JOetjen\Cooper\Syntax\Ast\AssignStmt;
use JOetjen\Cooper\Syntax\Ast\AtomExpr;
use JOetjen\Cooper\Syntax\Ast\AtRefExpr;
use JOetjen\Cooper\Syntax\Ast\Binding;
use JOetjen\Cooper\Syntax\Ast\BlockExpr;
use JOetjen\Cooper\Syntax\Ast\ConditionalStmt;
use JOetjen\Cooper\Syntax\Ast\ConfigRefExpr;
use JOetjen\Cooper\Syntax\Ast\DeleteStmt;
use JOetjen\Cooper\Syntax\Ast\DisabledStmt;
use JOetjen\Cooper\Syntax\Ast\EnvRefExpr;
use JOetjen\Cooper\Syntax\Ast\Expr;
use JOetjen\Cooper\Syntax\Ast\FileNode;
use JOetjen\Cooper\Syntax\Ast\FilterExpr;
use JOetjen\Cooper\Syntax\Ast\ForStmt;
use JOetjen\Cooper\Syntax\Ast\ImportStmt;
use JOetjen\Cooper\Syntax\Ast\KeyPath;
use JOetjen\Cooper\Syntax\Ast\KeySegment;
use JOetjen\Cooper\Syntax\Ast\ListExpr;
use JOetjen\Cooper\Syntax\Ast\LiteralExpr;
use JOetjen\Cooper\Syntax\Ast\ResolverExpr;
use JOetjen\Cooper\Syntax\Ast\Stmt;
use JOetjen\Cooper\Syntax\Ast\SuffixExpr;
use JOetjen\Cooper\Syntax\Ast\TaggedExpr;
use JOetjen\Cooper\Syntax\Ast\TupleExpr;
use JOetjen\Cooper\Syntax\Ast\VarDeclStmt;
use JOetjen\Cooper\Node\Suffix;

/**
 * A backtracking recursive-descent parser for CASC, rule for rule the
 * Elixir original's PEG grammar (`priv/grammar/casc.aether`): ordered
 * choice tries alternatives in turn and backtracks on failure, `?`/`*`
 * are greedy. Every method returns its node, or `null` with the token
 * position restored. Literal values are left as tokens -- converting
 * them is `Evaluator`'s job, so a statement that a `${?NAME}` guard
 * skips is never evaluated at all.
 *
 * The contextual keywords (`import`, `for`, `in`, `from`, `as`) are
 * matched by spelling while parsing; the Elixir grammar accepts any
 * identifier in those positions and decides afterwards (an `import`
 * statement under another word is an assignment, `foo "bar"`), which
 * accepts the same documents.
 *
 * `followedDelete` is the grammar's `followed_delete`: a bare
 * `-key.path` followed by another complete statement is a delete
 * (CASC.md §5.7). The grammar is newline-insensitive, so without it the
 * optional `=` makes the next line's key the remove value.
 *
 * @internal
 */
final class Parser
{
    private int $pos = 0;
    private int $furthest = 0;
    /** @var array<string, true> */
    private array $expected = [];

    /**
     * @param list<Token> $tokens
     */
    private function __construct(private readonly array $tokens, private readonly ?string $file)
    {
    }

    /**
     * @param string $src a complete CASC file's text, version header included
     * @param string|null $file the file it came from, for error messages
     * @throws CooperError (stage `lexer`, `parser`, or `action`) on malformed input
     */
    public static function parse(string $src, ?string $file = null): FileNode
    {
        $parser = new self(Lexer::tokenize($src, $file), $file);

        return $parser->file();
    }

    private function file(): FileNode
    {
        $version = $this->versionHeader();
        if ($version === null) {
            throw $this->error();
        }

        $statements = [];
        while (($statement = $this->statement()) !== null) {
            $statements[] = $statement;
        }
        if (!$this->peek()->is(TokenType::Eof)) {
            $this->fail('a statement');
            throw $this->error();
        }

        return new FileNode($version, $statements);
    }

    /**
     * `#@version = 1.0` (CASC.md §2). `version` is an ordinary
     * identifier, checked by spelling (CASC.md §4.1 reserves no such
     * word). The lexer may fold `1.0` of `1.0.0` into one float token,
     * so the version is rebuilt from the raw token text.
     *
     * @return list<int>|null
     */
    private function versionHeader(): ?array
    {
        if ($this->accept(TokenType::Hash) === null || $this->accept(TokenType::At) === null) {
            return null;
        }
        $keyword = $this->accept(TokenType::Ident);
        if ($keyword === null) {
            return null;
        }
        if ($keyword->text !== 'version') {
            throw new CooperError(
                "expected \"#@version\", got \"#@{$keyword->text}\"",
                CooperError::STAGE_ACTION,
                $keyword->line,
                $keyword->column,
                $this->file,
            );
        }
        $this->accept(TokenType::Equals);

        $head = $this->accept(TokenType::Float) ?? $this->accept(TokenType::Integer);
        if ($head === null) {
            return null;
        }
        $parts = explode('.', $head->text);
        while (true) {
            $save = $this->pos;
            if ($this->accept(TokenType::Dot) !== null && ($part = $this->accept(TokenType::Integer)) !== null) {
                $parts[] = $part->text;
                continue;
            }
            $this->pos = $save;
            break;
        }

        $version = [];
        foreach ($parts as $part) {
            if (preg_match('/\A\d+\z/', $part) !== 1) {
                throw new CooperError(
                    'invalid version number ' . json_encode(implode('.', $parts)),
                    CooperError::STAGE_ACTION,
                    $head->line,
                    $head->column,
                    $this->file,
                );
            }
            $version[] = (int) $part;
        }

        return $version;
    }

    private function statement(): ?Stmt
    {
        $start = $this->pos;

        if ($this->accept(TokenType::Hash) !== null) {
            $statement = $this->realStatement();
            if ($statement !== null) {
                return new DisabledStmt($statement);
            }
            $this->pos = $start;

            return null;
        }

        if ($this->accept(TokenType::EnvGuardOpen) !== null) {
            $name = $this->accept(TokenType::Ident);
            if ($name !== null && $this->accept(TokenType::RBrace) !== null) {
                $statement = $this->realStatement();
                if ($statement !== null) {
                    return new ConditionalStmt($name, $statement);
                }
            }
            $this->pos = $start;

            return null;
        }

        return $this->realStatement();
    }

    private function realStatement(): ?Stmt
    {
        return $this->attempt($this->varDecl(...))
            ?? $this->attempt($this->forStatement(...))
            ?? $this->attempt($this->importStatement(...))
            ?? $this->attempt($this->followedDelete(...))
            ?? $this->attempt($this->sigilKvStatement(...))
            ?? $this->attempt($this->deleteStatement(...));
    }

    private function varDecl(): ?VarDeclStmt
    {
        if ($this->accept(TokenType::At) === null) {
            return null;
        }
        $private = $this->accept(TokenType::Star) !== null;
        $name = $this->accept(TokenType::Ident);
        if ($name === null) {
            return null;
        }
        $this->accept(TokenType::Equals);
        $value = $this->value();

        return $value === null ? null : new VarDeclStmt($private, $name, $value);
    }

    private function forStatement(): ?ForStmt
    {
        if ($this->acceptWord('for') === null) {
            return null;
        }
        $first = $this->binding();
        if ($first === null) {
            return null;
        }
        $bindings = [$first];
        while (true) {
            $save = $this->pos;
            if ($this->accept(TokenType::Comma) !== null && ($binding = $this->binding()) !== null) {
                $bindings[] = $binding;
                continue;
            }
            $this->pos = $save;
            break;
        }

        // `from <template> as <dest>` is tried whole before the plain
        // `as <dest>` form -- see casc.aether's own note on why this has
        // to be an explicit choice rather than an optional `from` part.
        $template = null;
        $save = $this->pos;
        $dest = null;
        if ($this->acceptWord('from') !== null
            && ($template = $this->keyPath()) !== null
            && $this->acceptWord('as') !== null) {
            $dest = $this->keyPath();
        }
        if ($dest === null) {
            $this->pos = $save;
            $template = null;
            if ($this->acceptWord('as') === null) {
                return null;
            }
            $dest = $this->keyPath();
            if ($dest === null) {
                return null;
            }
        }

        $body = $this->block();

        return $body === null ? null : new ForStmt($bindings, $template, $dest, $body);
    }

    private function binding(): ?Binding
    {
        if ($this->accept(TokenType::At) === null) {
            return null;
        }
        $name = $this->accept(TokenType::Ident);
        if ($name === null) {
            return null;
        }
        $save = $this->pos;
        if ($this->acceptWord('in') !== null && ($iterable = $this->value(false)) !== null) {
            return new Binding($name, $iterable);
        }
        $this->pos = $save;

        return new Binding($name, null);
    }

    private function importStatement(): ?ImportStmt
    {
        if ($this->acceptWord('import') === null) {
            return null;
        }
        $path = $this->accept(TokenType::DqString) ?? $this->accept(TokenType::SqString);

        return $path === null ? null : new ImportStmt($path);
    }

    private function sigilKvStatement(): ?AssignStmt
    {
        $sigil = $this->accept(TokenType::Tilde) ?? $this->accept(TokenType::Plus) ?? $this->accept(TokenType::Minus);
        $keyPath = $this->keyPath();
        if ($keyPath === null) {
            return null;
        }
        $this->accept(TokenType::Equals);

        $rhs = $this->attempt($this->block(...)) ?? $this->value();

        return $rhs === null ? null : new AssignStmt($sigil?->text, $keyPath, $rhs);
    }

    /**
     * `followed_delete := delete_statement &statement`: a bare delete
     * whose next token starts another complete statement. Tried before
     * `sigilKvStatement`, so `-a.b` then `c = 1` is a delete and an
     * assignment, never the remove `-a.b = c`. The one case this cannot
     * tell apart -- `-tags b` then `c = 1` -- resolves as a delete; the
     * remove is written `-tags = b`.
     */
    private function followedDelete(): ?DeleteStmt
    {
        $delete = $this->deleteStatement();
        if ($delete === null) {
            return null;
        }
        $save = $this->pos;
        $next = $this->statement();
        $this->pos = $save;

        return $next === null ? null : $delete;
    }

    private function deleteStatement(): ?DeleteStmt
    {
        if ($this->accept(TokenType::Minus) === null) {
            return null;
        }
        $keyPath = $this->keyPath();

        return $keyPath === null ? null : new DeleteStmt($keyPath);
    }

    private function block(): ?BlockExpr
    {
        if ($this->accept(TokenType::LBrace) === null) {
            return null;
        }
        $statements = [];
        while (($statement = $this->statement()) !== null) {
            $statements[] = $statement;
            $this->accept(TokenType::Comma);
        }

        return $this->accept(TokenType::RBrace) === null ? null : new BlockExpr($statements);
    }

    private function keyPath(): ?KeyPath
    {
        $first = $this->keySegment();
        if ($first === null) {
            return null;
        }
        $segments = [$first];
        while (true) {
            $save = $this->pos;
            if ($this->accept(TokenType::Dot) !== null && ($segment = $this->keySegment()) !== null) {
                $segments[] = $segment;
                continue;
            }
            $this->pos = $save;
            break;
        }

        return new KeyPath($segments);
    }

    private function keySegment(): ?KeySegment
    {
        $save = $this->pos;
        $secret = $this->accept(TokenType::Star) !== null;
        $token = $this->accept(TokenType::Ident) ?? $this->accept(TokenType::DqString) ?? $this->accept(TokenType::SqString);
        if ($token === null) {
            $this->pos = $save;

            return null;
        }

        return new KeySegment($secret, $token);
    }

    /**
     * `value` -- or, with `$allowAtom` false, `iterable_value`, which
     * leaves out bare atoms so a binding list ending in an index binding
     * (`for @x in @{xs}, @idx as dest`) cannot read `as` as an iterable.
     */
    private function value(bool $allowAtom = true): ?Expr
    {
        $token = $this->peek();
        if ($token->type->isLiteral()) {
            $this->pos++;

            return new LiteralExpr($token);
        }

        $expr = match ($token->type) {
            TokenType::LBracket => $this->attempt(fn (): ?ListExpr => ($items = $this->sequence(TokenType::LBracket, TokenType::RBracket)) === null ? null : new ListExpr($items)),
            TokenType::LParen => $this->attempt(fn (): ?TupleExpr => ($items = $this->sequence(TokenType::LParen, TokenType::RParen)) === null ? null : new TupleExpr($items)),
            TokenType::AtOpen => $this->attempt($this->atRef(...)),
            TokenType::EnvOpen => $this->attempt($this->envRef(...)),
            TokenType::ConfigOpen => $this->attempt($this->configRef(...)),
            TokenType::ResolverRefRaw => $this->resolverRef(),
            TokenType::Bang => $this->attempt($this->taggedRef(...)),
            TokenType::Colon => $allowAtom ? $this->attempt($this->colonAtom(...)) : null,
            TokenType::Ident => $allowAtom ? $this->bareAtom() : null,
            default => null,
        };

        if ($expr === null) {
            $this->fail('a value');
        }

        return $expr;
    }

    /**
     * `"[" (element ","?)* "]"` (or the tuple's parentheses).
     *
     * @return list<Expr|BlockExpr>|null
     */
    private function sequence(TokenType $open, TokenType $close): ?array
    {
        if ($this->accept($open) === null) {
            return null;
        }
        $items = [];
        while (($item = $this->element()) !== null) {
            $items[] = $item;
            $this->accept(TokenType::Comma);
        }

        return $this->accept($close) === null ? null : $items;
    }

    /**
     * `element := block | value` -- a list or tuple element may be a
     * block, which is a map (CASC.md §6.10). Only a value once was, so a
     * list of maps (`[{ path = "^/admin" }]`, the shape framework
     * configuration is full of) could not be written at all.
     */
    private function element(): Expr|BlockExpr|null
    {
        return $this->attempt($this->block(...)) ?? $this->value();
    }

    private function resolverRef(): ResolverExpr
    {
        return new ResolverExpr($this->tokens[$this->pos++]);
    }

    private function bareAtom(): AtomExpr
    {
        return new AtomExpr($this->tokens[$this->pos++]);
    }

    private function colonAtom(): ?AtomExpr
    {
        if ($this->accept(TokenType::Colon) === null) {
            return null;
        }
        $word = $this->accept(TokenType::Ident)
            ?? $this->accept(TokenType::NilKw)
            ?? $this->accept(TokenType::TrueKw)
            ?? $this->accept(TokenType::FalseKw)
            ?? $this->accept(TokenType::InfKw);

        return $word === null ? null : new AtomExpr($word);
    }

    private function atRef(): ?AtRefExpr
    {
        if ($this->accept(TokenType::AtOpen) === null) {
            return null;
        }
        $name = $this->refName();
        if ($name === null) {
            return null;
        }
        $index = $this->attempt($this->indexSuffix(...));
        $suffix = $this->refSuffix();
        $filters = $this->filters();

        return $this->accept(TokenType::RBrace) === null ? null : new AtRefExpr($name, $index, $suffix, $filters);
    }

    private function envRef(): ?EnvRefExpr
    {
        if ($this->accept(TokenType::EnvOpen) === null) {
            return null;
        }
        $name = $this->refName();
        if ($name === null) {
            return null;
        }

        $bracket = false;
        $index = null;
        $save = $this->pos;
        if ($this->accept(TokenType::LBracket) !== null) {
            $index = $this->accept(TokenType::Integer);
            if ($this->accept(TokenType::RBracket) !== null) {
                $bracket = true;
            } else {
                $this->pos = $save;
                $index = null;
            }
        }

        $suffix = $this->refSuffix();
        $filters = $this->filters();

        return $this->accept(TokenType::RBrace) === null ? null : new EnvRefExpr($name, $bracket, $index, $suffix, $filters);
    }

    private function configRef(): ?ConfigRefExpr
    {
        if ($this->accept(TokenType::ConfigOpen) === null) {
            return null;
        }
        $path = $this->keyPath();
        if ($path === null) {
            return null;
        }
        $index = $this->attempt($this->indexSuffix(...));
        $suffix = $this->refSuffix();
        $filters = $this->filters();

        return $this->accept(TokenType::RBrace) === null ? null : new ConfigRefExpr($path, $index, $suffix, $filters);
    }

    private function refName(): ?Token
    {
        return $this->accept(TokenType::Ident) ?? $this->accept(TokenType::DqString);
    }

    private function indexSuffix(): ?Token
    {
        if ($this->accept(TokenType::LBracket) === null) {
            return null;
        }
        $index = $this->accept(TokenType::Integer);

        return $index === null || $this->accept(TokenType::RBracket) === null ? null : $index;
    }

    /**
     * `:?"msg"` and `:+alt` are tried before the plain `:default` form,
     * the same ordered choice the Elixir grammar makes.
     *
     * The lexer folds `:+5` into ":" and the signed number `+5` (a
     * signed number out-munches the `+` punctuation), which the plain
     * `:default` form would read as a *default* of 5: the opposite of
     * what `:+alt` means, and not what the same reference inside a string
     * means. So a number (or `inf`) directly after ":" is checked for its
     * sign: `+` makes it the substitute form, anything else stays a
     * default (`${PORT:-1}` is a default of minus one). The reference
     * once made it a default either way.
     */
    private function refSuffix(): ?SuffixExpr
    {
        $save = $this->pos;
        if ($this->accept(TokenType::Colon) === null) {
            return null;
        }
        $afterColon = $this->pos;

        if ($this->accept(TokenType::Question) !== null && ($message = $this->accept(TokenType::DqString)) !== null) {
            return new SuffixExpr(Suffix::REQUIRED, $message);
        }
        $this->pos = $afterColon;

        if ($this->accept(TokenType::Plus) !== null && ($alt = $this->value()) !== null) {
            return new SuffixExpr(Suffix::SUBSTITUTE, $alt);
        }
        $this->pos = $afterColon;

        $number = $this->peek();
        if (in_array($number->type, [TokenType::Integer, TokenType::Float, TokenType::InfKw], true) && str_starts_with($number->text, '+')) {
            $this->pos++;

            return new SuffixExpr(Suffix::SUBSTITUTE, new LiteralExpr($number));
        }

        $default = $this->value();
        if ($default !== null) {
            return new SuffixExpr(Suffix::DEFAULT, $default);
        }
        $this->pos = $save;

        return null;
    }

    /**
     * @return list<FilterExpr>
     */
    private function filters(): array
    {
        $filters = [];
        while (true) {
            $save = $this->pos;
            if ($this->accept(TokenType::Pipe) !== null && ($name = $this->accept(TokenType::Ident)) !== null) {
                $argument = null;
                $argSave = $this->pos;
                if ($this->accept(TokenType::Colon) !== null) {
                    $argument = $this->accept(TokenType::DqString) ?? $this->accept(TokenType::SqString);
                    if ($argument === null) {
                        $this->pos = $argSave;
                    }
                }
                $filters[] = new FilterExpr($name, $argument);
                continue;
            }
            $this->pos = $save;

            return $filters;
        }
    }

    private function taggedRef(): ?TaggedExpr
    {
        if ($this->accept(TokenType::Bang) === null) {
            return null;
        }
        $name = $this->accept(TokenType::Ident);
        if ($name === null || $this->accept(TokenType::LParen) === null) {
            return null;
        }
        $argument = $this->value();
        if ($argument === null || $this->accept(TokenType::RParen) === null) {
            return null;
        }

        return new TaggedExpr($name, $argument);
    }

    // ---- token-level helpers ------------------------------------------------

    /**
     * Runs `$rule`, restoring the token position if it fails.
     *
     * @template T
     * @param callable(): (T|null) $rule
     * @return T|null
     */
    private function attempt(callable $rule): mixed
    {
        $save = $this->pos;
        $result = $rule();
        if ($result === null) {
            $this->pos = $save;
        }

        return $result;
    }

    private function peek(): Token
    {
        return $this->tokens[$this->pos];
    }

    private function accept(TokenType $type): ?Token
    {
        $token = $this->tokens[$this->pos];
        if ($token->type === $type) {
            $this->pos++;

            return $token;
        }
        $this->fail(json_encode($type->value, JSON_UNESCAPED_SLASHES) . '');

        return null;
    }

    private function acceptWord(string $word): ?Token
    {
        $token = $this->tokens[$this->pos];
        if ($token->isWord($word)) {
            $this->pos++;

            return $token;
        }
        $this->fail("\"{$word}\"");

        return null;
    }

    private function fail(string $expected): void
    {
        if ($this->pos > $this->furthest) {
            $this->furthest = $this->pos;
            $this->expected = [];
        }
        if ($this->pos === $this->furthest) {
            $this->expected[$expected] = true;
        }
    }

    private function error(): CooperError
    {
        $token = $this->tokens[$this->furthest];
        $expected = array_keys($this->expected);
        $message = 'unexpected ' . $token->describe();
        if ($expected !== []) {
            $message .= ' -- expected ' . (count($expected) === 1 ? $expected[0] : 'one of ' . implode(', ', $expected));
        }

        return new CooperError($message, CooperError::STAGE_PARSER, $token->line, $token->column, $this->file);
    }
}

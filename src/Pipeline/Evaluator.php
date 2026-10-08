<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Pipeline;

use JOetjen\Cooper\CooperError;
use JOetjen\Cooper\Node\Block;
use JOetjen\Cooper\Node\Clear;
use JOetjen\Cooper\Node\ConfigRef;
use JOetjen\Cooper\Node\EnvRef;
use JOetjen\Cooper\Node\Filter;
use JOetjen\Cooper\Node\InterpText;
use JOetjen\Cooper\Node\MapNode;
use JOetjen\Cooper\Node\Op;
use JOetjen\Cooper\Node\Suffix;
use JOetjen\Cooper\Node\TaggedRef;
use JOetjen\Cooper\Node\VarDecl;
use JOetjen\Cooper\Node\VarRef;
use JOetjen\Cooper\Syntax\Ast\AssignStmt;
use JOetjen\Cooper\Syntax\Ast\AtomExpr;
use JOetjen\Cooper\Syntax\Ast\AtRefExpr;
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
use JOetjen\Cooper\Syntax\Ast\ListExpr;
use JOetjen\Cooper\Syntax\Ast\LiteralExpr;
use JOetjen\Cooper\Syntax\Ast\ResolverExpr;
use JOetjen\Cooper\Syntax\Ast\Stmt;
use JOetjen\Cooper\Syntax\Ast\SuffixExpr;
use JOetjen\Cooper\Syntax\Ast\TaggedExpr;
use JOetjen\Cooper\Syntax\Ast\TupleExpr;
use JOetjen\Cooper\Syntax\Ast\VarDeclStmt;
use JOetjen\Cooper\Syntax\InterpParser;
use JOetjen\Cooper\Syntax\Literals;
use JOetjen\Cooper\Syntax\Token;
use JOetjen\Cooper\Syntax\TokenType;
use JOetjen\Cooper\Value\CooperAtom;
use JOetjen\Cooper\Value\CooperBytes;
use JOetjen\Cooper\Value\CooperDate;
use JOetjen\Cooper\Value\CooperDateTime;
use JOetjen\Cooper\Value\CooperDuration;
use JOetjen\Cooper\Value\CooperIPv4;
use JOetjen\Cooper\Value\CooperIPv6;
use JOetjen\Cooper\Value\CooperInteger;
use JOetjen\Cooper\Value\CooperLocalDateTime;
use JOetjen\Cooper\Value\CooperTime;
use JOetjen\Cooper\Value\CooperTuple;

/**
 * Evaluates a parsed file into a flat list of entries -- `Op` (one
 * per leaf assignment, dotted paths and nested blocks already folded
 * into its path), `VarDecl`, and `Clear` -- the Elixir original's
 * `Cooper.Actions`. Assembling those into a tree is `Merge`'s job,
 * deliberately separate so an imported file's entries can be spliced
 * straight into its importer's list.
 *
 * Every leaf literal becomes its PHP value here (CASC.md §6), and every
 * reference an unresolved node (`VarRef`, `EnvRef`, ... -- §7) for
 * `Resolver` to handle once the whole load is merged. Imports and loops
 * are expanded inline, in source order, so a variable an import or
 * declaration brings in is visible to what follows it.
 *
 * Places the reference (as of its 0.4.0) contradicted CASC.md, since
 * fixed there and followed here:
 *
 *  - A disabled statement (§5.6, `#key = ...`) is never evaluated. The
 *    reference evaluated it and discarded only the entries, so a
 *    disabled `#@name = ...` still defined the variable and a disabled
 *    `#import "..."` still ran the import.
 *  - A merge sigil inside a block keeps its meaning: `a { +tags =
 *    ["d"] }` appends and `a { -b }` deletes. The reference overwrote
 *    every inner op's sigil with the enclosing statement's, turning
 *    those into a plain replace and `b = nil`.
 *
 * @internal
 */
final class Evaluator
{
    private function __construct(private readonly Context $ctx)
    {
    }

    /**
     * @return list<Op|VarDecl|Clear>
     */
    public static function evaluate(FileNode $file, Context $ctx): array
    {
        return (new self($ctx))->statements($file->statements);
    }

    /**
     * @param list<Stmt> $statements
     * @return list<Op|VarDecl|Clear>
     */
    private function statements(array $statements): array
    {
        $entries = [];
        foreach ($statements as $statement) {
            array_push($entries, ...$this->statement($statement));
        }

        return $entries;
    }

    /**
     * @return list<Op|VarDecl|Clear>
     */
    private function statement(Stmt $statement): array
    {
        return match (true) {
            $statement instanceof DisabledStmt => [],
            $statement instanceof ConditionalStmt => $this->conditional($statement),
            $statement instanceof VarDeclStmt => $this->varDecl($statement),
            $statement instanceof ForStmt => $this->forStatement($statement),
            $statement instanceof ImportStmt => Loader::loadImport($this->dqOrSq($statement->path), $this->ctx),
            $statement instanceof AssignStmt => $this->assign($statement),
            $statement instanceof DeleteStmt => [new Op(...$this->keyPath($statement->keyPath, Op::DELETE))],
            default => throw new \LogicException('unknown statement ' . $statement::class),
        };
    }

    /**
     * `${?NAME}` (CASC.md §7.2) guards exactly the one statement after
     * it. When the variable is unset or empty that statement is never
     * evaluated, so nothing inside it can fail just for being skipped.
     *
     * @return list<Op|VarDecl|Clear>
     */
    private function conditional(ConditionalStmt $statement): array
    {
        $name = $statement->name->text;
        $this->ctx->shapeEnvNames[$name] = true;
        $value = $this->ctx->env[$name] ?? '';

        return $value === '' ? [] : $this->statement($statement->statement);
    }

    /**
     * @return list<VarDecl>
     */
    private function varDecl(VarDeclStmt $statement): array
    {
        $value = $this->value($statement->value);
        $name = $statement->name->text;
        // `Loop` resolves a binding's iterable against the variables
        // declared so far, so they are recorded as they are evaluated.
        //
        // The value is stamped with this file's scope as it is recorded:
        // the variable environment `Resolver` reads is built from
        // `ctx->vars`, not from the stamped entries, so a reference inside
        // a declaration's own value (`@*b = "v@{a}"`, `a` private too)
        // would otherwise belong to no file and never see this file's
        // private declarations.
        $key = $statement->private ? Scope::privateKey($name) : $name;
        $this->ctx->vars[$key] = [Scope::stamp($value, $this->ctx->scope), !$statement->private];

        return [new VarDecl($name, $value, !$statement->private)];
    }

    /**
     * @return list<Op|VarDecl|Clear>
     */
    private function forStatement(ForStmt $statement): array
    {
        $bindings = [];
        foreach ($statement->bindings as $binding) {
            $bindings[] = $binding->iterable === null
                ? ['index', $binding->name->text]
                : ['element', $binding->name->text, $this->value($binding->iterable)];
        }
        $template = $statement->template === null ? null : $this->keyPath($statement->template)[0];
        [$dest, , , $destSecret] = $this->keyPath($statement->dest);
        $body = $this->statements($statement->body->statements);

        return Loop::expand($bindings, $template, $dest, $destSecret, $body, $this->ctx->vars);
    }

    /**
     * @return list<Op|VarDecl|Clear>
     */
    private function assign(AssignStmt $statement): array
    {
        $sigil = match ($statement->sigil) {
            '~' => Op::REPLACE,
            '+' => Op::APPEND,
            '-' => Op::REMOVE,
            default => Op::MERGE,
        };
        [$path, , , $secret] = $this->keyPath($statement->keyPath);

        if (!$statement->rhs instanceof BlockExpr) {
            return [new Op($path, $sigil, $this->value($statement->rhs), $secret)];
        }

        $nested = $this->statements($statement->rhs->statements);
        if ($nested === []) {
            // An empty block is an empty map (CASC.md §5.4): it writes
            // one, which `Merge` lets leave a map already there as it is.
            // Emitting nothing, as this once did (after the reference),
            // made `w {}` and `w = {}` no key at all.
            $op = new Op($path, $sigil, new MapNode(), $secret);

            return $sigil === Op::REPLACE ? [new Clear($path), $op] : [$op];
        }

        $entries = [];
        if ($sigil === Op::REPLACE) {
            // `~key { ... }` replaces the *whole* subtree, not just the
            // paths the new block mentions (CASC.md §8.4's own example
            // drops `host`) -- one clear at the statement's own path,
            // ahead of the leaves.
            $entries[] = new Clear($path);
        }
        foreach ($nested as $entry) {
            $entries[] = match (true) {
                $entry instanceof Op => new Op(
                    [...$path, ...$entry->path],
                    $entry->sigil === Op::MERGE ? $sigil : $entry->sigil,
                    $entry->value,
                    $secret || $entry->secret,
                ),
                $entry instanceof Clear => new Clear([...$path, ...$entry->path]),
                default => $entry,
            };
        }

        return $entries;
    }

    /**
     * A key path's segments (plain strings, or `InterpText` for an
     * interpolated double-quoted segment), shaped as `Op` constructor
     * arguments: `[path, sigil, value, secret?]`.
     *
     * @return array{0: list<string|InterpText>, 1: string, 2: null, 3: bool}
     */
    private function keyPath(KeyPath $keyPath, string $sigil = Op::MERGE): array
    {
        $segments = [];
        $secret = false;
        foreach ($keyPath->segments as $segment) {
            $secret = $secret || $segment->secret;
            $segments[] = $this->dqOrSq($segment->token);
        }

        return [$segments, $sigil, null, $secret];
    }

    private function value(Expr $expr): mixed
    {
        return match (true) {
            $expr instanceof LiteralExpr => $this->literal($expr->token),
            $expr instanceof ListExpr => array_map($this->element(...), $expr->items),
            $expr instanceof TupleExpr => new CooperTuple(array_map($this->element(...), $expr->items)),
            $expr instanceof AtRefExpr => new VarRef(
                $this->dqOrSq($expr->name),
                $this->index($expr->index),
                $this->suffix($expr->suffix),
                $this->filters($expr->filters),
            ),
            $expr instanceof EnvRefExpr => new EnvRef(
                $this->dqOrSq($expr->name),
                $this->index($expr->index),
                $expr->bracket && $expr->index === null,
                $this->suffix($expr->suffix),
                $this->filters($expr->filters),
            ),
            $expr instanceof ConfigRefExpr => new ConfigRef(
                $this->keyPath($expr->path)[0],
                $this->index($expr->index),
                $this->suffix($expr->suffix),
                $this->filters($expr->filters),
            ),
            $expr instanceof ResolverExpr => InterpParser::resolverRef($expr->token->text, $expr->token, $this->ctx->file),
            $expr instanceof TaggedExpr => $this->tagged($expr),
            $expr instanceof AtomExpr => $this->atom($expr->token),
            default => throw new \LogicException('unknown expression ' . $expr::class),
        };
    }

    /**
     * A `!Name(arg)`, remembering whether its argument was written as a
     * string literal and where it stands -- what a `LiteralTag` checks,
     * and names in its errors. A double-quoted string that interpolates
     * is no literal: it evaluates to an `InterpText`, not a string.
     */
    private function tagged(TaggedExpr $expr): TaggedRef
    {
        $argument = $this->value($expr->argument);
        $literal = $expr->argument instanceof LiteralExpr
            && in_array($expr->argument->token->type, [TokenType::DqString, TokenType::SqString, TokenType::TripleString], true)
            && is_string($argument);

        // The name's token starts right after the `!`, which is where the
        // tag is written.
        return new TaggedRef($expr->name->text, $argument, $literal, $this->ctx->file, $expr->name->line, $expr->name->column - 1);
    }

    /**
     * A list or tuple element. A block (CASC.md §6.10) is evaluated like
     * any other -- variables declared, loops expanded, imports spliced in
     * -- but stays a `Block` of its entries, becoming a map in `Resolver`
     * once its keys, which may interpolate, can be resolved.
     */
    private function element(Expr|BlockExpr $expr): mixed
    {
        return $expr instanceof BlockExpr ? new Block($this->statements($expr->statements)) : $this->value($expr);
    }

    /**
     * A bare or `:`-prefixed atom (CASC.md §6.4). `:nil`, `:true`, and
     * `:false` are the values, not atoms, so a document means one thing
     * in every implementation. Keeping them as atoms was tried and
     * dropped; §6.4 says why.
     */
    private function atom(Token $token): mixed
    {
        return match ($token->type) {
            TokenType::NilKw => null,
            TokenType::TrueKw => true,
            TokenType::FalseKw => false,
            default => CooperAtom::of($token->text),
        };
    }

    private function suffix(?SuffixExpr $suffix): ?Suffix
    {
        if ($suffix === null) {
            return null;
        }
        $value = $suffix->value instanceof Token ? $this->dqOrSq($suffix->value) : $this->value($suffix->value);

        return new Suffix($suffix->kind, $value);
    }

    /**
     * @param list<FilterExpr> $filters
     * @return list<Filter>
     */
    private function filters(array $filters): array
    {
        return array_map(
            fn (FilterExpr $filter): Filter => new Filter(
                $filter->name->text,
                $filter->argument === null ? null : $this->dqOrSq($filter->argument),
            ),
            $filters,
        );
    }

    /**
     * An identifier's text, or a quoted string's value (a double-quoted
     * one unescaped and possibly interpolated).
     */
    private function dqOrSq(Token $token): string|InterpText
    {
        return match ($token->type) {
            TokenType::DqString => $this->guard($token, fn (): string|InterpText => InterpParser::parse(
                Literals::unescape(substr($token->text, 1, -1)),
                $token,
                $this->ctx->file,
            )),
            TokenType::SqString => substr($token->text, 1, -1),
            default => $token->text,
        };
    }

    private function integer(Token $token): int|CooperInteger
    {
        return $this->guard($token, static fn (): int|CooperInteger => Literals::integer($token->text));
    }

    /**
     * An index (`@{list[2]}`). One past PHP's `int` can never be in
     * range, so it is held as the nearest `int`, which is not in range
     * either: the reference finds nothing there, and so does this.
     */
    private function index(?Token $token): ?int
    {
        if ($token === null) {
            return null;
        }
        $index = $this->integer($token);
        if ($index instanceof CooperInteger) {
            return str_starts_with($index->digits, '-') ? PHP_INT_MIN : PHP_INT_MAX;
        }

        return $index;
    }

    /**
     * A literal token's PHP value (CASC.md §6).
     */
    private function literal(Token $token): mixed
    {
        $text = $token->text;

        return match ($token->type) {
            TokenType::NilKw => null,
            TokenType::TrueKw => true,
            TokenType::FalseKw => false,
            TokenType::InfKw => str_starts_with($text, '-') ? -INF : INF,
            TokenType::Integer => $this->integer($token),
            TokenType::Float => $this->guard($token, static fn (): float => Literals::float($text)),
            TokenType::Date => $this->guard($token, static fn (): CooperDate => self::date($text)),
            TokenType::Time => $this->guard($token, static fn (): CooperTime => CooperTime::parse($text)),
            TokenType::DateTime => $this->guard($token, static fn (): CooperLocalDateTime|CooperDateTime => self::dateTime($text)),
            TokenType::IPv4 => $this->guard($token, static fn (): CooperIPv4 => CooperIPv4::parse($text)),
            TokenType::IPv6 => $this->guard($token, static fn (): CooperIPv6 => CooperIPv6::parse($text)),
            TokenType::Duration => $this->guard($token, static fn (): CooperDuration => new CooperDuration(Literals::duration($text))),
            TokenType::Bytes => $this->guard($token, static fn (): CooperBytes => new CooperBytes(Literals::bytes($text))),
            TokenType::DqString, TokenType::SqString => $this->dqOrSq($token),
            TokenType::TripleString => Literals::dedent(substr($text, 3, -3)),
            default => throw new \LogicException('not a literal: ' . $token->type->value),
        };
    }

    private static function date(string $text): CooperDate
    {
        [$year, $month, $day] = array_map('intval', explode('-', $text));

        return new CooperDate($year, $month, $day);
    }

    /**
     * A local date-time, or -- with a `Z`/`+hh:mm` offset -- an instant,
     * normalized to UTC as the Elixir original's `DateTime` is.
     */
    private static function dateTime(string $text): CooperLocalDateTime|CooperDateTime
    {
        if (preg_match('/(?:Z|[+-]\d{2}:\d{2})\z/', $text) === 1) {
            return CooperDateTime::parse($text);
        }
        if (preg_match('/\A(\d{4}-\d{2}-\d{2})T([0-9:.]+)\z/', $text, $m) !== 1) {
            throw new \InvalidArgumentException('invalid date-time ' . json_encode($text));
        }

        return new CooperLocalDateTime(self::date($m[1]), CooperTime::parse($m[2]));
    }

    /**
     * Runs a literal conversion, turning its `\InvalidArgumentException`
     * into an `action`-stage `CooperError` pointing at `$token`.
     *
     * @template T
     * @param callable(): T $convert
     * @return T
     */
    private function guard(Token $token, callable $convert): mixed
    {
        try {
            return $convert();
        } catch (\InvalidArgumentException $e) {
            throw new CooperError($e->getMessage(), CooperError::STAGE_ACTION, $token->line, $token->column, $this->ctx->file, $e);
        }
    }
}

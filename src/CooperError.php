<?php

declare(strict_types=1);

namespace JOetjen\Cooper;

/**
 * The one exception type every `cooper` failure mode throws -- lexing,
 * parsing, literal validation, loop expansion, imports, merging,
 * reference resolution, and `.env` loading alike -- so a caller only
 * ever needs to catch one type.
 *
 * Elixir's `Cooper` returns `{:error, %Ichor.Error{stage: ...}}` instead;
 * throwing is simply idiomatic PHP (the same call `php-dextrin` makes
 * for `DXNError`). `$stage` keeps the Elixir error's own vocabulary --
 * `lexer`, `parser`, `action`, `loop`, `import`, `merge`, `resolve`,
 * `dotenv` -- so an error reads the same regardless of which port
 * raised it.
 *
 * `$sourceLine`/`$sourceColumn` locate lexer, parser, and literal
 * errors in the CASC source (1-based); `$sourceFile` names the file the
 * failing source came from, when it came from one (an entry file or one
 * of its imports). They are separate from `Exception`'s own
 * `getFile()`/`getLine()`, which locate the PHP code that threw.
 */
final class CooperError extends \RuntimeException
{
    public const STAGE_LEXER = 'lexer';
    public const STAGE_PARSER = 'parser';
    public const STAGE_ACTION = 'action';
    public const STAGE_LOOP = 'loop';
    public const STAGE_IMPORT = 'import';
    public const STAGE_MERGE = 'merge';
    public const STAGE_RESOLVE = 'resolve';
    public const STAGE_DOTENV = 'dotenv';

    /**
     * The error description alone, without the location suffix
     * `getMessage()` appends when a line/column/file is known.
     */
    public readonly string $reason;

    /**
     * @param string $message the error description, without location
     * @param string $stage which pipeline stage failed (one of the `STAGE_*` constants)
     * @param int|null $sourceLine 1-based CASC source line, when known
     * @param int|null $sourceColumn 1-based CASC source column, when known
     * @param string|null $sourceFile the CASC file the error occurred in, when known
     * @param \Throwable|null $previous the underlying cause, if any
     */
    public function __construct(
        string $message,
        public readonly string $stage,
        public readonly ?int $sourceLine = null,
        public readonly ?int $sourceColumn = null,
        public readonly ?string $sourceFile = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(self::format($message, $sourceLine, $sourceColumn, $sourceFile), 0, $previous);
        $this->reason = $message;
    }

    /**
     * Returns a copy of this error attributed to `$file`, unless it
     * already names one -- used when an error raised while parsing an
     * imported file bubbles up through its importer.
     *
     * @param string|null $file the file to attribute the error to
     * @return self the attributed copy (or `$this`, unchanged)
     */
    public function withFile(?string $file): self
    {
        if ($file === null || $this->sourceFile !== null) {
            return $this;
        }

        return new self($this->reason, $this->stage, $this->sourceLine, $this->sourceColumn, $file, $this->getPrevious());
    }

    private static function format(string $message, ?int $line, ?int $column, ?string $file): string
    {
        $where = [];
        if ($file !== null) {
            $where[] = $file;
        }
        if ($line !== null) {
            $where[] = $column !== null ? "line {$line}, column {$column}" : "line {$line}";
        }

        return $where === [] ? $message : $message . ' (' . implode(', ', $where) . ')';
    }
}

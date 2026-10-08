<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Pipeline;

/**
 * The reference filters (CASC.md §7.2) -- `| trim`, `| downcase`,
 * `| upcase`, `| trim_prefix: "..."`, `| trim_suffix: "..."` -- and the
 * matching parameterless string tags (`!trim`, `!downcase`, `!upcase`,
 * §7.5), shared so the two cannot drift.
 *
 * The vocabulary is deliberately closed: an open one would oblige every
 * conformant reader in every language to implement an unbounded set,
 * and would turn a config format into an expression language. Filters
 * normalize; they never convert -- a non-string is an error.
 *
 * @internal
 */
final class Filters
{
    /** @var array<string, bool> filter name => whether it takes an argument */
    private const FILTERS = [
        'downcase' => false,
        'trim' => false,
        'trim_prefix' => true,
        'trim_suffix' => true,
        'upcase' => false,
    ];

    private function __construct()
    {
    }

    /**
     * Applies `$filters` left to right.
     *
     * @param list<array{0: string, 1: string|null}> $filters name/argument pairs
     * @throws \InvalidArgumentException on an unknown filter, a wrong argument, or a non-string value
     */
    public static function apply(mixed $value, array $filters): mixed
    {
        foreach ($filters as [$name, $argument]) {
            $value = self::applyOne($value, $name, $argument);
        }

        return $value;
    }

    /**
     * Unicode-aware whitespace trim, as Elixir's `String.trim/1`.
     */
    public static function trim(string $value): string
    {
        return (string) preg_replace('/\A[\s\p{Z}]+|[\s\p{Z}]+\z/u', '', $value);
    }

    public static function downcase(string $value): string
    {
        return mb_strtolower($value, 'UTF-8');
    }

    public static function upcase(string $value): string
    {
        return mb_strtoupper($value, 'UTF-8');
    }

    private static function applyOne(mixed $value, string $name, ?string $argument): string
    {
        if (!array_key_exists($name, self::FILTERS)) {
            $known = array_keys(self::FILTERS);

            throw new \InvalidArgumentException(
                'unknown filter ' . Display::inspect($name) . '; known filters: ' . implode(', ', $known)
            );
        }
        $takesArgument = self::FILTERS[$name];
        if (!$takesArgument && $argument !== null) {
            throw new \InvalidArgumentException('filter ' . Display::inspect($name) . ' takes no argument');
        }
        if ($takesArgument && $argument === null) {
            throw new \InvalidArgumentException('filter ' . Display::inspect($name) . " requires an argument, as in |{$name}: \"...\"");
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException(
                'cannot apply filter ' . Display::inspect($name) . ' to ' . Display::inspect($value) . ': not a string'
            );
        }

        return match ($name) {
            'trim' => self::trim($value),
            'downcase' => self::downcase($value),
            'upcase' => self::upcase($value),
            'trim_prefix' => $argument !== '' && str_starts_with($value, (string) $argument) ? substr($value, strlen((string) $argument)) : $value,
            default => $argument !== '' && str_ends_with($value, (string) $argument) ? substr($value, 0, -strlen((string) $argument)) : $value,
        };
    }
}

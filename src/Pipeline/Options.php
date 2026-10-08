<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Pipeline;

/**
 * The validated form of the options array `Cooper::loadFile()` /
 * `Cooper::loadString()` accept. An unknown key or a wrongly typed
 * value is an `\InvalidArgumentException` up front, rather than an
 * option that silently does nothing.
 *
 * @internal
 */
final class Options
{
    private const KEYS = [
        'env', 'dotenv', 'dotenvEnv', 'dotenvFiles', 'dotenvDir', 'dotenvOverride',
        'root', 'file', 'resolvers', 'tags', 'modules', 'importSchemes', 'cache', 'envValue',
    ];

    /**
     * @param array<string, string> $env
     * @param list<string>|null $dotenvFiles
     * @param array<string, callable> $resolvers
     * @param array<string, callable> $tags
     * @param array<string, mixed> $modules
     * @param array<string, callable> $importSchemes
     * @param (\Closure(\JOetjen\Cooper\EnvReference, \Closure(): mixed): mixed)|null $envValue
     */
    private function __construct(
        public readonly array $env,
        public readonly ?bool $dotenv,
        public readonly bool $dotenvEnvGiven,
        public readonly ?string $dotenvEnv,
        public readonly ?array $dotenvFiles,
        public readonly ?string $dotenvDir,
        public readonly bool $dotenvOverride,
        public readonly ?string $root,
        public readonly ?string $file,
        public readonly array $resolvers,
        public readonly array $tags,
        public readonly array $modules,
        public readonly array $importSchemes,
        public readonly bool $cache,
        public readonly ?\Closure $envValue = null,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     * @throws \InvalidArgumentException on an unknown key or a mistyped value
     */
    public static function from(array $options): self
    {
        $unknown = array_diff(array_keys($options), self::KEYS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                'unknown option(s): ' . implode(', ', $unknown) . '; known options: ' . implode(', ', self::KEYS)
            );
        }

        return new self(
            self::stringMap($options, 'env'),
            self::nullableBool($options, 'dotenv'),
            array_key_exists('dotenvEnv', $options),
            self::nullableString($options, 'dotenvEnv'),
            self::stringList($options, 'dotenvFiles'),
            self::nullableString($options, 'dotenvDir'),
            self::nullableBool($options, 'dotenvOverride') ?? false,
            self::nullableString($options, 'root'),
            self::nullableString($options, 'file'),
            self::callables($options, 'resolvers'),
            self::callables($options, 'tags'),
            self::nameMap($options, 'modules'),
            self::callables($options, 'importSchemes'),
            self::nullableBool($options, 'cache') ?? true,
            self::nullableCallable($options, 'envValue'),
        );
    }

    /**
     * The options `Grammar` takes.
     *
     * @param array<string, string> $env
     * @return array{root?: string, file?: string, importSchemes: array<string, callable>, env: array<string, string>}
     */
    public function grammar(array $env, ?string $root = null, ?string $file = null): array
    {
        $options = ['importSchemes' => $this->importSchemes, 'env' => $env];
        $root ??= $this->root;
        $file ??= $this->file;
        if ($root !== null) {
            $options['root'] = $root;
        }
        if ($file !== null) {
            $options['file'] = $file;
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    private static function stringMap(array $options, string $key): array
    {
        $value = $options[$key] ?? [];
        if (!is_array($value)) {
            throw self::invalid($key, 'an array of name => string');
        }
        foreach ($value as $name => $item) {
            if (!is_string($name) || !is_string($item)) {
                throw self::invalid($key, 'an array of name => string');
            }
        }

        /** @var array<string, string> $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $options
     * @return list<string>|null
     */
    private static function stringList(array $options, string $key): ?array
    {
        $value = $options[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || !array_is_list($value) || array_filter($value, 'is_string') !== $value) {
            throw self::invalid($key, 'a list of strings');
        }

        /** @var list<string> $value */
        return $value;
    }

    /**
     * A name => anything array: `modules`, whose values are whatever the
     * application wants `!module` to give back, so only the keys are
     * checked.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private static function nameMap(array $options, string $key): array
    {
        $value = $options[$key] ?? [];
        if (!is_array($value)) {
            throw self::invalid($key, 'an array of name => value');
        }
        foreach (array_keys($value) as $name) {
            if (!is_string($name)) {
                throw self::invalid($key, 'an array of name => value');
            }
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, callable>
     */
    private static function callables(array $options, string $key): array
    {
        $value = $options[$key] ?? [];
        if (!is_array($value)) {
            throw self::invalid($key, 'an array of name => callable');
        }
        foreach ($value as $name => $item) {
            if (!is_string($name) || !is_callable($item)) {
                throw self::invalid($key, 'an array of name => callable');
            }
        }

        /** @var array<string, callable> $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function nullableCallable(array $options, string $key): ?\Closure
    {
        $value = $options[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_callable($value)) {
            throw self::invalid($key, 'a callable');
        }

        return \Closure::fromCallable($value);
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function nullableBool(array $options, string $key): ?bool
    {
        $value = $options[$key] ?? null;
        if ($value !== null && !is_bool($value)) {
            throw self::invalid($key, 'a bool');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function nullableString(array $options, string $key): ?string
    {
        $value = $options[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw self::invalid($key, 'a string');
        }

        return $value;
    }

    private static function invalid(string $key, string $expected): \InvalidArgumentException
    {
        return new \InvalidArgumentException("option \"{$key}\" must be {$expected}");
    }
}

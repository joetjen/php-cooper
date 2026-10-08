<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Value;

/**
 * Wraps a secret value (CASC.md §4.3's `*key`-prefixed keys) in the
 * result `Cooper::loadFile()`/`Cooper::loadString()` return, so it never
 * leaks by accident: string conversion, `json_encode()`, `var_dump()`,
 * and `print_r()` all redact regardless of the wrapped value. Reach the
 * real value deliberately with `reveal()` -- the wrapping is only ever a
 * display-time concern, never a barrier to the developer's own use of
 * the value.
 *
 * `var_export()` and `serialize()` are not redacted: neither has a hook
 * a class can intercept without also breaking deliberate use.
 *
 * ## Partial redaction
 *
 * A secret embedded inside a larger interpolated string
 * (`"postgres://user:%{database.password}@host"`) redacts only the
 * embedded portion: `(string) $secret` renders
 * `"postgres://user:[~~REDACTED~~]@host"`, and several secrets in one
 * string each redact independently. `$redacted` carries that
 * precomputed display text; `null` (an ordinary whole-value secret)
 * falls back to the blanket `[~~REDACTED~~]` marker. `reveal()` always
 * returns the real, fully unmasked value.
 */
final class CooperSecret implements \Stringable, \JsonSerializable
{
    /** The blanket marker a whole-value secret displays as. */
    public const MARKER = '[~~REDACTED~~]';

    /**
     * @param mixed $value the real, unmasked value
     * @param string|null $redacted precomputed display text for a partially redacted string, or null for the blanket marker
     */
    public function __construct(
        private readonly mixed $value,
        public readonly ?string $redacted = null,
    ) {
    }

    /**
     * Unwraps the secret, returning the real, fully unmasked value.
     *
     * @return mixed the real value
     */
    public function reveal(): mixed
    {
        return $this->value;
    }

    /**
     * The redacted display text -- never the real value.
     *
     * @return string `$redacted`, or the blanket marker
     */
    public function __toString(): string
    {
        return $this->redacted ?? self::MARKER;
    }

    /**
     * Encodes as the redacted display text, never the real value.
     *
     * @return string the same text as `__toString()`
     */
    public function jsonSerialize(): string
    {
        return $this->__toString();
    }

    /**
     * What `var_dump()`/`print_r()` show: the redacted text only.
     *
     * @return array{redacted: string}
     */
    public function __debugInfo(): array
    {
        return ['redacted' => $this->__toString()];
    }
}

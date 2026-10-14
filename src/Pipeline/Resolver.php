<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Pipeline;

use JOetjen\Cooper\CooperError;
use JOetjen\Cooper\EnvReference;
use JOetjen\Cooper\LiteralTag;
use JOetjen\Cooper\Node\Absent;
use JOetjen\Cooper\Node\Block;
use JOetjen\Cooper\Node\ConfigRef;
use JOetjen\Cooper\Node\EnvRef;
use JOetjen\Cooper\Node\Filter;
use JOetjen\Cooper\Node\InterpText;
use JOetjen\Cooper\Node\Layered;
use JOetjen\Cooper\Node\ListEdit;
use JOetjen\Cooper\Node\MapNode;
use JOetjen\Cooper\Node\Op;
use JOetjen\Cooper\Node\ResolverRef;
use JOetjen\Cooper\Node\Suffix;
use JOetjen\Cooper\Node\TaggedRef;
use JOetjen\Cooper\Node\VarRef;
use JOetjen\Cooper\Syntax\Literals;
use JOetjen\Cooper\Value\CooperBytes;
use JOetjen\Cooper\Value\CooperDuration;
use JOetjen\Cooper\Value\CooperInteger;
use JOetjen\Cooper\Value\CooperSecret;
use JOetjen\Cooper\Value\CooperTuple;

/**
 * Resolves everything `Merge` left unresolved -- the Elixir original's
 * `Cooper.Resolver`: `@{...}` variables and `${...}` environment reads
 * (§7.1-§7.2), `%{...}` config references against the *final* merged
 * tree (§7.3, memoized and cycle-checked), `!{resolver:payload}` and
 * `!Name(arg)` dispatch (§7.4-§7.5), `Layered` loop-template values, and
 * the `+key`/`-key` edits `Merge` deferred as `ListEdit`s (§8.4).
 *
 * Secrecy travels with values: a `CooperSecret`'s inner value is
 * resolved and re-wrapped, a tag applied to a secret argument or an
 * index into a secret list yields a secret, and a secret interpolated
 * into a larger string redacts only its own portion.
 *
 * Consumer-registered resolvers and tags are plain callables that
 * return the value or throw; the Elixir original's `{:ok, v}` /
 * `{:error, reason}` convention becomes "return or throw" here.
 *
 * @internal
 */
final class Resolver
{
    /** @var array<string, callable(mixed): mixed> */
    private const BUILT_IN_TAGS = [
        'int' => [self::class, 'tagInt'],
        'float' => [self::class, 'tagFloat'],
        'bool' => [self::class, 'tagBool'],
        'duration' => [self::class, 'tagDuration'],
        'bytes' => [self::class, 'tagBytes'],
        'trim' => [self::class, 'tagTrim'],
        'downcase' => [self::class, 'tagDowncase'],
        'upcase' => [self::class, 'tagUpcase'],
    ];

    /**
     * What a built `${...}`/`@{...}` name may resolve to: the shape a
     * written-out one could have, so building a name reaches nothing
     * that could not have been written directly.
     */
    private const REF_NAME = '/\A[A-Za-z_][A-Za-z0-9_]*\z/';

    /**
     * A module name as CASC writes it (§7.5): dot-separated PascalCase
     * segments, the same in every Cooper implementation, each of which
     * translates it into its own host's convention -- so one document
     * names one module everywhere. This port once accepted PHP's own
     * shape too (`App\Client`, `crypto`), which made a document naming a
     * module readable by this port only.
     */
    private const MODULE_NAME = '/\A[A-Z][A-Za-z0-9]*(?:\.[A-Z][A-Za-z0-9]*)*\z/';
    private const MAX_MODULE_BYTES = 512;

    /** @var array<string, mixed> resolved %{...} paths */
    private array $cache = [];
    /** @var list<list<string>> */
    private array $inProgress = [];
    /** @var list<array{0: string, 1: string}> [scope-or-"public", name] */
    private array $varInProgress = [];
    /** @var array<string, true> */
    private array $envNames = [];
    /** @var array<string, callable> */
    private readonly array $tags;
    /**
     * Where in the tree resolution is: map keys and list indices from the
     * root. Kept only to name the key in a `LiteralTag`'s errors.
     *
     * @var list<string|int>
     */
    private array $at = [];

    /**
     * @param array<string, mixed> $vars public variables
     * @param array<string, array<string, mixed>> $privateVars scope => private variables
     * @param array<string, string> $env
     * @param array<string, callable> $resolvers
     * @param array<string, callable> $tags consumer tags (beside the built-ins)
     * @param array<string, mixed> $modules what `!module("Name")` means, by the name as written
     * @param bool $keysOnly resolving an interpolated key: only `@{}`/`${}` allowed
     * @param (\Closure(EnvReference, \Closure(): mixed): mixed)|null $envValue the `envValue` option (see `Cooper`)
     */
    private function __construct(
        private readonly MapNode $tree,
        private readonly array $vars,
        private readonly array $privateVars,
        private readonly array $env,
        private readonly array $resolvers,
        array $tags,
        array $modules = [],
        private readonly bool $keysOnly = false,
        private readonly ?\Closure $envValue = null,
    ) {
        // `!module` closes over this load's own mapping; a consumer tag
        // of the same name still overrides it, as it may any built-in.
        $this->tags = array_merge(
            self::BUILT_IN_TAGS,
            ['module' => static fn (mixed $argument): mixed => self::tagModule($argument, $modules)],
            $tags,
        );
    }

    /**
     * Resolves `$tree` fully and converts it to native PHP values.
     *
     * Options: `vars` (a `VarEnv`, or a plain name => value array
     * treated as entirely public), `env`, `resolvers`, `tags`, `modules`
     * (written module name => what `!module` gives back for it), and `raw`
     * (keep internal `MapNode` maps rather than converting to arrays --
     * for the conformance runner, which must tell an empty map from an
     * empty list), and `envValue` (what a `${...}` written as a value
     * resolves to -- see `Cooper` and `EnvReference`).
     *
     * @param array{vars?: VarEnv|array<string, mixed>, env?: array<string, string>, resolvers?: array<string, callable>, tags?: array<string, callable>, modules?: array<string, mixed>, raw?: bool, envValue?: \Closure(EnvReference, \Closure(): mixed): mixed|null} $options
     * @return array{0: mixed, 1: list<string>} the resolved value and every `${NAME}` looked up
     * @throws CooperError (stage `resolve`)
     */
    public static function resolve(MapNode $tree, array $options = []): array
    {
        $vars = $options['vars'] ?? [];
        if (!$vars instanceof VarEnv) {
            $vars = new VarEnv($vars);
        }
        $resolver = new self(
            $tree,
            $vars->public,
            $vars->privateByScope,
            $options['env'] ?? self::systemEnv(),
            $options['resolvers'] ?? [],
            $options['tags'] ?? [],
            $options['modules'] ?? [],
            false,
            $options['envValue'] ?? null,
        );
        $resolved = $resolver->value($tree);
        if (!($options['raw'] ?? false)) {
            $resolved = Native::convert($resolved);
        }

        return [$resolved, array_map('strval', array_keys($resolver->envNames))];
    }

    /**
     * A resolver for interpolated *keys*, consulted while merging --
     * before the tree, resolvers, or tags exist -- so only `@{...}` and
     * `${...}` may appear.
     *
     * @param array<string, string> $env
     */
    public static function forKeys(VarEnv $vars, array $env): self
    {
        return new self(new MapNode(), $vars->public, $vars->privateByScope, $env, [], [], [], true);
    }

    /**
     * Resolves one interpolated key segment -- under the rules a built
     * `%{...}` key follows: a string, non-empty, without a `.` (it
     * would silently split the path), and never built from a secret.
     *
     * @throws CooperError (stage `resolve`)
     */
    public function key(InterpText $segment): string
    {
        $resolved = $this->value($segment);

        return $this->checkKey($resolved, 'interpolated key');
    }

    /**
     * @return list<string> every `${NAME}` this resolver looked up
     */
    public function envNames(): array
    {
        return array_map('strval', array_keys($this->envNames));
    }

    /**
     * @return array<string, string>
     */
    public static function systemEnv(): array
    {
        return getenv();
    }

    private function value(mixed $value): mixed
    {
        return match (true) {
            $value instanceof VarRef => $this->varRef($value),
            $value instanceof EnvRef => $this->envRef($value),
            $value instanceof ConfigRef => $this->configRef($value),
            $value instanceof ResolverRef => $this->resolverRef($value),
            $value instanceof TaggedRef => $this->taggedRef($value),
            $value instanceof InterpText => $this->text($value->segments),
            $value instanceof Layered => $this->layered($value),
            $value instanceof ListEdit => $this->listEdit($value),
            $value instanceof Block => $this->block($value),
            $value instanceof CooperSecret => $this->secret($value),
            $value instanceof MapNode => $value->map(fn (mixed $item, string $key): mixed => $this->within($key, $item)),
            $value instanceof CooperTuple => new CooperTuple($this->elements($value->items)),
            is_array($value) => $this->elements($value),
            default => $value,
        };
    }

    /**
     * `$value` resolved as the entry at `$key` of whatever is being
     * resolved now.
     */
    private function within(string|int $key, mixed $value): mixed
    {
        $this->at[] = $key;
        try {
            return $this->value($value);
        } finally {
            array_pop($this->at);
        }
    }

    /**
     * @param array<array-key, mixed> $items
     * @return array<array-key, mixed>
     */
    private function elements(array $items): array
    {
        $out = [];
        foreach ($items as $index => $item) {
            $out[$index] = $this->within($index, $item);
        }

        return $out;
    }

    /**
     * Where resolution is, as a key path is written: `db.hosts[1]`.
     */
    private function where(): string
    {
        $where = '';
        foreach ($this->at as $segment) {
            $where .= is_int($segment) ? "[{$segment}]" : ($where === '' ? $segment : ".{$segment}");
        }

        return $where === '' ? '(the document root)' : $where;
    }

    /**
     * A secret's own value may still be unresolved (`*password =
     * ${DB_PASSWORD}`); resolve it and re-wrap. A secret that resolves to
     * another secret collapses into one, the outer blanket marker
     * winning over any inner partial redaction.
     */
    private function secret(CooperSecret $secret): CooperSecret
    {
        $resolved = $this->value($secret->reveal());

        return new CooperSecret($resolved instanceof CooperSecret ? $resolved->reveal() : $resolved);
    }

    // ---- @{...} -------------------------------------------------------------

    private function varRef(VarRef $ref): mixed
    {
        if ($ref->bound !== null) {
            // A loop binding's value attached to the reference (see
            // `Loop`): resolved, then indexed, defaulted, and filtered
            // like any other value.
            [$found, $value] = self::index($this->value($ref->bound[0]), $ref->index);

            return $this->finish($found, $value, $ref->suffix, $ref->filters, '@{' . (is_string($ref->name) ? $ref->name : '"..."') . '}');
        }

        $name = is_string($ref->name) ? $ref->name : $this->refName($ref->name, '@{...}');
        $label = "@{{$name}}";
        $private = $ref->scope !== null && array_key_exists($name, $this->privateVars[$ref->scope] ?? []);
        $binding = [$private ? (string) $ref->scope : 'public', $name];

        if (in_array($binding, $this->varInProgress, true)) {
            $chain = [...array_map(static fn (array $b): string => $b[1], $this->varInProgress), $name];

            throw self::error('circular @{...} reference: ' . implode(' -> ', $chain));
        }

        if ($private) {
            $raw = $this->privateVars[(string) $ref->scope][$name];
        } elseif (array_key_exists($name, $this->vars)) {
            $raw = $this->vars[$name];
        } else {
            return $this->finish(false, null, $ref->suffix, $ref->filters, $label);
        }

        $this->varInProgress[] = $binding;
        $resolved = $this->value($raw);
        array_pop($this->varInProgress);

        [$found, $value] = self::index($resolved, $ref->index);

        return $this->finish($found, $value, $ref->suffix, $ref->filters, $label);
    }

    // ---- ${...} -------------------------------------------------------------

    /**
     * A `${...}` in value position: the `envValue` option's to answer
     * when one is given (see `asked()`), else read from the environment.
     * An interpolated key's resolver never asks -- a key shapes the
     * document, and is always read at load time.
     */
    private function envRef(EnvRef $ref): mixed
    {
        if ($this->envValue === null || $this->keysOnly) {
            return $this->readEnv($ref);
        }

        return $this->asked($ref, null, fn (): mixed => $this->readEnv($ref));
    }

    /**
     * What the `envValue` option makes of `$ref` -- tagged `$tag` when
     * written directly as that tag's argument -- given `$resolve`, the
     * value Cooper itself would resolve. The name is built first, so a
     * built name reaches the option as the name it built.
     *
     * @param \Closure(): mixed $resolve
     */
    private function asked(EnvRef $ref, ?string $tag, \Closure $resolve): mixed
    {
        assert($this->envValue !== null);
        $name = is_string($ref->name) ? $ref->name : $this->refName($ref->name, '${...}');
        $suffix = $ref->suffix;
        $reference = new EnvReference(
            $name,
            $ref->list,
            $ref->index,
            $suffix?->kind,
            array_map(static fn (Filter $filter): string => $filter->name, $ref->filters),
            $tag,
            fn (): mixed => $suffix === null ? null : Native::convert($this->value($suffix->value)),
        );

        try {
            return ($this->envValue)($reference, $resolve);
        } catch (CooperError $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw self::error("envValue failed for \${{$name}}: " . $e->getMessage(), $e);
        }
    }

    /**
     * A `${...}` read from the environment, the one way there is without
     * the `envValue` option.
     */
    private function readEnv(EnvRef $ref): mixed
    {
        $name = is_string($ref->name) ? $ref->name : $this->refName($ref->name, '${...}');
        $this->envNames[$name] = true;
        $raw = $this->env[$name] ?? '';

        $found = false;
        $value = null;
        if ($raw !== '') {
            if ($ref->list) {
                [$found, $value] = [true, self::splitList($raw)];
            } elseif ($ref->index !== null) {
                [$found, $value] = self::index(self::splitList($raw), $ref->index);
            } else {
                [$found, $value] = [true, $raw];
            }
        }

        return $this->finish($found, $value, $ref->suffix, $ref->filters, "\${{$name}}");
    }

    /**
     * @return list<string>
     */
    private static function splitList(string $raw): array
    {
        return array_map(Filters::trim(...), preg_split('/[,;]/', $raw) ?: []);
    }

    /**
     * A built reference name (`${"TOKEN_@{id}"}`, CASC.md §7.2). It must
     * resolve to a string shaped like a written-out name, and may not
     * come from a secret: names appear in error messages, unredacted.
     */
    private function refName(InterpText $name, string $label): string
    {
        $resolved = $this->value($name);
        if ($resolved instanceof CooperSecret) {
            throw self::error("{$label} name may not be built from a secret value");
        }
        if (!is_string($resolved)) {
            throw self::error("{$label} name must resolve to a string, got: " . Display::inspect($resolved));
        }
        if (preg_match(self::REF_NAME, $resolved) !== 1) {
            throw self::error("{$label} resolved to " . Display::inspect($resolved) . ', which is not a valid name');
        }

        return $resolved;
    }

    // ---- %{...} -------------------------------------------------------------

    private function configRef(ConfigRef $ref): mixed
    {
        if ($this->keysOnly) {
            throw self::error('an interpolated key may only reference @{...} and ${...} -- %{...} needs the finished tree');
        }

        $path = array_map(
            fn (string|InterpText $segment): string => is_string($segment)
                ? $segment
                : $this->checkKey($this->value($segment), '%{...} key'),
            $ref->path,
        );
        $label = '%{' . implode('.', $path) . '}';

        [$found, $value] = $this->path($path);
        if ($found) {
            [$found, $value] = self::index($value, $ref->index);
        }

        return $this->finish($found, $value, $ref->suffix, $ref->filters, $label);
    }

    /**
     * A built key is not an identifier -- `%{tokens."supervisor-1"}` is
     * an ordinary quoted key -- but it may not be empty (it would
     * address the wrong depth), dotted (it would become two segments),
     * or a secret.
     */
    private function checkKey(mixed $resolved, string $label): string
    {
        if ($resolved instanceof CooperSecret) {
            throw self::error("{$label} may not be built from a secret value");
        }
        if (!is_string($resolved)) {
            throw self::error("{$label} must resolve to a string, got: " . Display::inspect($resolved));
        }
        if ($resolved === '') {
            throw self::error("{$label} resolved to an empty string");
        }
        if (str_contains($resolved, '.')) {
            throw self::error("{$label} resolved to " . Display::inspect($resolved) . ', which would split into more than one path segment');
        }

        return $resolved;
    }

    /**
     * `$path`'s value in the final tree, resolving (and memoizing) it on
     * first reach. `$inProgress` is ordered so a cycle is reported in
     * traversal order.
     *
     * @param list<string> $path
     * @return array{0: bool, 1: mixed} [found, value]
     */
    private function path(array $path): array
    {
        $key = implode("\0", $path);
        if (array_key_exists($key, $this->cache)) {
            return [true, $this->cache[$key]];
        }
        if (in_array($path, $this->inProgress, true)) {
            $chain = array_map(static fn (array $p): string => implode('.', $p), [...$this->inProgress, $path]);

            throw self::error('circular %{...} reference: ' . implode(' -> ', $chain));
        }

        // In progress from before the fetch: reaching `$path` may resolve
        // a node along the way (see `fetch()`), and one that leads back
        // here -- `a = %{a.b}` -- is part of the cycle.
        $this->inProgress[] = $path;
        try {
            [$found, $raw] = $this->fetch($this->tree, $path);
            if (!$found) {
                return [false, null];
            }

            // Resolved out of turn, for a reference: while it is, the key
            // being resolved is the one the reference reached.
            $at = $this->at;
            $this->at = $path;
            try {
                $resolved = $this->value($raw);
            } finally {
                $this->at = $at;
            }
        } finally {
            array_pop($this->inProgress);
        }

        $this->cache[$key] = $resolved;

        return [true, $resolved];
    }

    /**
     * Walks `$path` through the unresolved tree's maps, resolving a
     * `Layered` value it runs through before continuing into it.
     *
     * Any other unresolved node the path runs through with segments
     * still left -- a reference, a loop's copied block element, a secret,
     * a tagged value -- is resolved too: CASC.md §7.3 resolves against
     * the final tree, in which `origin = %{origins.eu}` *is* the
     * `origins.eu` block, so `%{origin.code}` must find its `code`. Such
     * a node is resolved through `path()` under the segments walked so
     * far, so it is memoised once and a reference into its own value
     * (`a = %{a.b}`) is reported as the cycle it is. Its value is fully
     * resolved, so the rest of the walk goes through maps only -- plain
     * or secret, see `fetchResolved()`; a value resolving to anything but
     * a map leaves the path undefined.
     *
     * @param list<string> $path
     * @return array{0: bool, 1: mixed} [found, value]
     */
    private function fetch(mixed $tree, array $path): array
    {
        $walked = [];
        foreach ($path as $i => $segment) {
            if ($tree instanceof Layered) {
                $tree = $this->layered($tree);
            }
            if ($tree instanceof MapNode) {
                if (!$tree->has($segment)) {
                    return [false, null];
                }
                $tree = $tree->get($segment);
                $walked[] = $segment;
                continue;
            }
            // Any object, not a list of the ones that can stand for a
            // map: a leaf (a date, an address) resolves to itself and is
            // undefined below like any other non-map.
            if (!is_object($tree) || $walked === []) {
                return [false, null];
            }
            [$found, $resolved] = $this->path($walked);
            if (!$found) {
                return [false, null];
            }

            return self::fetchResolved($resolved, array_slice($path, $i));
        }

        return [true, $tree];
    }

    /**
     * The rest of a path through a value already resolved: plain maps,
     * and a secret one (`*site = @{s}` copying a block element), whose
     * value found inside stays secret -- secrecy travels with the value,
     * as `index()` keeps it for an element of a secret list. A list or a
     * scalar ends the path as undefined.
     *
     * @param list<string> $path
     * @return array{0: bool, 1: mixed} [found, value]
     */
    private static function fetchResolved(mixed $value, array $path): array
    {
        if ($path === []) {
            return [true, $value];
        }
        if ($value instanceof CooperSecret) {
            [$found, $inner] = self::fetchResolved($value->reveal(), $path);
            if (!$found) {
                return [false, null];
            }

            return [true, $inner instanceof CooperSecret ? $inner : new CooperSecret($inner)];
        }
        $segment = $path[0];
        if (!$value instanceof MapNode || !$value->has($segment)) {
            return [false, null];
        }

        return self::fetchResolved($value->get($segment), array_slice($path, 1));
    }

    // ---- Layered (a `for ... from` template plus overrides) -----------------

    private function layered(Layered $layered): mixed
    {
        if ($this->keysOnly) {
            throw self::error('an interpolated key may only reference @{...} and ${...}');
        }

        return self::deepMerge($this->value($layered->base), $this->value($layered->overrides));
    }

    /**
     * The overrides on top of a template's copy. A key marked `Absent`
     * (`-key` in the loop body) is removed from the copy; one with no
     * base to apply to keeps its overrides, with any `Absent` inside them
     * dropped.
     */
    private static function deepMerge(mixed $base, mixed $override): mixed
    {
        if (!$override instanceof MapNode) {
            return $override;
        }
        $from = $base instanceof MapNode ? $base : new MapNode();
        $merged = $from;
        foreach ($override->entries() as $key => $value) {
            $key = (string) $key;
            if ($value instanceof Absent) {
                $merged = $merged->without($key);
                continue;
            }
            $merged = $merged->with($key, $from->has($key) ? self::deepMerge($from->get($key), $value) : self::deepMerge(null, $value));
        }

        return $merged;
    }

    // ---- Block (a map written as a list or tuple element) -------------------

    /**
     * A block written as a list or tuple element (`[{ path = "^/admin"
     * }]`, CASC.md §6.10): its keys are resolved -- they may interpolate,
     * which is why it waited until now -- under the rules every
     * interpolated key follows, its entries merged into a map the way a
     * document's are, and that map resolved like any other. An
     * interpolated key is a string resolved before the tree exists, so
     * one there is refused.
     */
    private function block(Block $block): mixed
    {
        if ($this->keysOnly) {
            throw self::error('an interpolated key may only reference @{...} and ${...} -- it is resolved before the tree, resolvers, and tags it would need');
        }

        return $this->value(Merge::assemble($block->entries, $this->key(...)));
    }

    // ---- ListEdit (a `+key`/`-key` applied once it resolves) ------------------

    private function listEdit(ListEdit $edit): mixed
    {
        $label = '"' . ($edit->op === Op::APPEND ? '+' : '-') . implode('.', $edit->path) . '"';
        $base = $this->value($edit->base);

        // A template without this key: `+key` is a plain assignment,
        // `-key` leaves it absent.
        if ($base instanceof Absent) {
            return $edit->op === Op::APPEND ? $this->value($edit->operand) : $base;
        }

        $secret = $base instanceof CooperSecret;
        $base = $secret ? $base->reveal() : $base;
        if ($base instanceof CooperTuple) {
            throw self::error("{$label} targets a tuple -- tuples are never merged, only replaced wholesale (CASC.md §8.3)");
        }
        if (!is_array($base)) {
            throw self::error("{$label} needs a list at that path, found " . Display::inspect($base));
        }

        $operand = $this->value($edit->operand);
        if ($operand instanceof CooperSecret) {
            [$secret, $operand] = [true, $operand->reveal()];
        }
        $result = Merge::edit($edit->op, $base, $operand);

        return $secret ? new CooperSecret($result) : $result;
    }

    // ---- !{resolver:payload} --------------------------------------------------

    private function resolverRef(ResolverRef $ref): mixed
    {
        if ($this->keysOnly) {
            throw self::error('an interpolated key may only reference @{...} and ${...}');
        }
        $resolver = $this->resolvers[$ref->name] ?? null;
        if ($resolver === null) {
            throw self::error('unregistered resolver ' . Display::inspect($ref->name));
        }

        try {
            return $resolver($ref->payload);
        } catch (CooperError $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw self::error(
                'resolver ' . Display::inspect($ref->name) . ' failed for payload ' . Display::inspect($ref->payload) . ': ' . $e->getMessage(),
                $e,
            );
        }
    }

    // ---- !Name(arg) -----------------------------------------------------------

    private function taggedRef(TaggedRef $ref): mixed
    {
        if ($this->keysOnly) {
            throw self::error('an interpolated key may only reference @{...} and ${...}');
        }
        // Checked before anything is read: a refused argument must not be
        // resolved, or handed to the `envValue` option, at all.
        if (($this->tags[$ref->name] ?? null) instanceof LiteralTag && !$ref->literal) {
            throw $this->located($ref, "!{$ref->name}(...) takes only a string literal written in the document "
                . '-- "...", \'...\' or \"\"\"...\"\"\" with nothing interpolated -- not a reference, a variable, another tag or any other value '
                . "(at {$this->where()})");
        }
        // A `${...}` written directly as the argument is the `envValue`
        // option's to answer as a whole (`!int(${PORT})`): a runtime
        // placeholder cannot pass through a tag that must read its value.
        // Resolving it as Cooper would reads the variable directly, not
        // through the option a second time.
        if ($this->envValue !== null && $ref->arg instanceof EnvRef) {
            $arg = $ref->arg;

            return $this->asked($arg, $ref->name, fn (): mixed => $this->tagged($ref, $this->readEnv($arg)));
        }

        return $this->tagged($ref, $this->value($ref->arg));
    }

    /**
     * `$ref`'s tag applied to `$argument`, already resolved.
     */
    private function tagged(TaggedRef $ref, mixed $argument): mixed
    {
        $tag = $this->tags[$ref->name] ?? null;
        if ($tag === null) {
            throw self::error("unregistered tag !{$ref->name}(...)");
        }

        // A secret-sourced argument is unwrapped for the tag, and the
        // result re-wrapped: a value derived from a secret is a secret.
        if ($argument instanceof CooperSecret) {
            return new CooperSecret($this->applyTag($tag, $ref, $argument->reveal()));
        }

        return $this->applyTag($tag, $ref, $argument);
    }

    private function applyTag(callable $tag, TaggedRef $ref, mixed $argument): mixed
    {
        try {
            return $tag($argument);
        } catch (CooperError $e) {
            throw $e;
        } catch (\Throwable $e) {
            // A literal tag ran what the document says at that spot, so
            // the failure is the document's, and is located there.
            if ($tag instanceof LiteralTag) {
                throw $this->located($ref, "!{$ref->name}(...) failed at {$this->where()}: " . $e->getMessage(), $e);
            }

            throw self::error("!{$ref->name}(...) failed: " . $e->getMessage(), $e);
        }
    }

    /**
     * An error about the tag `$ref`, located where it is written.
     */
    private function located(TaggedRef $ref, string $message, ?\Throwable $previous = null): CooperError
    {
        return new CooperError($message, CooperError::STAGE_RESOLVE, $ref->line, $ref->column, $ref->file, $previous);
    }

    /**
     * @internal built-in `!int`
     */
    public static function tagInt(mixed $argument): int|CooperInteger
    {
        if (is_int($argument) || $argument instanceof CooperInteger) {
            return $argument;
        }
        if (is_string($argument) && preg_match('/\A[+-]?\d+\z/', Filters::trim($argument)) === 1) {
            return Literals::integer(Filters::trim($argument));
        }

        throw new \InvalidArgumentException(is_string($argument)
            ? 'not an integer: ' . Display::inspect($argument)
            : 'cannot convert ' . Display::inspect($argument) . ' to an integer');
    }

    /**
     * @internal built-in `!float`
     */
    public static function tagFloat(mixed $argument): float
    {
        if (is_float($argument)) {
            return $argument;
        }
        if (is_int($argument)) {
            return (float) $argument;
        }
        if ($argument instanceof CooperInteger) {
            return $argument->toFloat();
        }
        if (is_string($argument) && preg_match('/\A[+-]?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?\z/', Filters::trim($argument)) === 1) {
            return (float) Filters::trim($argument);
        }

        throw new \InvalidArgumentException(is_string($argument)
            ? 'not a float: ' . Display::inspect($argument)
            : 'cannot convert ' . Display::inspect($argument) . ' to a float');
    }

    /**
     * @internal built-in `!bool`
     *
     * Reads the spellings a boolean arrives in from an environment (CASC.md
     * §7.5): `true`/`false`, `1`/`0`, `yes`/`no`, `on`/`off` -- lower case
     * only, as `true`/`false` always were. Only the first pair once was, so
     * the commonest `.env` spelling of all, `DEBUG=1`, failed the load.
     * `match` compares strictly, so the integer `1` is still refused.
     */
    public static function tagBool(mixed $argument): bool
    {
        return match ($argument) {
            true, 'true', '1', 'yes', 'on' => true,
            false, 'false', '0', 'no', 'off' => false,
            default => throw new \InvalidArgumentException('not a boolean: ' . Display::inspect($argument)),
        };
    }

    /**
     * @internal built-in `!duration`
     */
    public static function tagDuration(mixed $argument): CooperDuration
    {
        if (!is_string($argument)) {
            throw new \InvalidArgumentException('cannot convert ' . Display::inspect($argument) . ' to a duration');
        }

        return new CooperDuration(Literals::duration($argument));
    }

    /**
     * @internal built-in `!bytes`
     */
    public static function tagBytes(mixed $argument): CooperBytes
    {
        if (!is_string($argument)) {
            throw new \InvalidArgumentException('cannot convert ' . Display::inspect($argument) . ' to a byte size');
        }

        return new CooperBytes(Literals::bytes($argument));
    }

    /**
     * @internal built-in `!trim`
     */
    public static function tagTrim(mixed $argument): string
    {
        return Filters::trim(self::stringArgument($argument, 'trim'));
    }

    /**
     * @internal built-in `!downcase`
     */
    public static function tagDowncase(mixed $argument): string
    {
        return Filters::downcase(self::stringArgument($argument, 'downcase'));
    }

    /**
     * @internal built-in `!upcase`
     */
    public static function tagUpcase(mixed $argument): string
    {
        return Filters::upcase(self::stringArgument($argument, 'upcase'));
    }

    /**
     * Built-in `!module` (CASC.md §7.5). The name is written the same way
     * for every Cooper implementation -- dot-separated PascalCase, after
     * trimming -- and anything else is an error, even a name the mapping
     * holds. The application's `modules` mapping is then asked first, by
     * the name exactly as written (case included); only a name it does
     * not hold is translated by convention into a class-string, `.`
     * becoming `\` and every segment kept as written
     * (`"Acme.Payments"` -> `"Acme\Payments"`).
     *
     * The class is never loaded or checked for existence: the name is
     * resolved, not loaded -- and autoloading has side effects a config
     * load should not trigger.
     *
     * @param array<string, mixed> $modules written name => what it means
     * @internal
     */
    public static function tagModule(mixed $argument, array $modules = []): mixed
    {
        if (!is_string($argument)) {
            throw new \InvalidArgumentException('cannot convert ' . Display::inspect($argument) . ' to a module: not a string');
        }
        $name = Filters::trim($argument);
        if (strlen($name) > self::MAX_MODULE_BYTES) {
            throw new \InvalidArgumentException('cannot convert ' . Display::inspect($argument) . ' to a module: longer than ' . self::MAX_MODULE_BYTES . ' bytes');
        }
        if (preg_match(self::MODULE_NAME, $name) !== 1) {
            throw new \InvalidArgumentException('cannot convert ' . Display::inspect($argument) . ' to a module: not a dot-separated PascalCase module name');
        }
        if (array_key_exists($name, $modules)) {
            return $modules[$name];
        }

        return str_replace('.', '\\', $name);
    }

    private static function stringArgument(mixed $argument, string $tag): string
    {
        if (!is_string($argument)) {
            throw new \InvalidArgumentException("cannot {$tag} " . Display::inspect($argument) . ': not a string');
        }

        return $argument;
    }

    // ---- suffixes, filters, indexing ------------------------------------------

    /**
     * Settles a reference's value through its suffix (CASC.md §7.2:
     * `:default`, `:+alt`, `:?"msg"`), then runs its filters on whatever
     * it ended up with.
     *
     * @param list<Filter> $filters
     */
    private function finish(bool $found, mixed $value, ?Suffix $suffix, array $filters, string $label): mixed
    {
        if ($found) {
            if ($suffix?->kind === Suffix::SUBSTITUTE) {
                $value = $this->value($suffix->value);
            }
        } else {
            $value = match ($suffix?->kind) {
                Suffix::DEFAULT => $this->value($suffix->value),
                Suffix::SUBSTITUTE => '',
                Suffix::REQUIRED => throw self::error(Display::toString($this->value($suffix->value))),
                default => throw self::error("undefined reference {$label}"),
            };
        }

        if ($filters === []) {
            return $value;
        }

        $chain = array_map(fn (Filter $filter): array => [
            $filter->name,
            $filter->argument instanceof InterpText ? Display::toString($this->value($filter->argument)) : $filter->argument,
        ], $filters);

        // A secret is filtered through: the real string is filtered and
        // the result is a secret again (CASC.md §4.3 -- the stored value
        // is unaffected by being secret), the way `!trim(%{pw})` already
        // behaves. Refusing it as "not a string", as this (and the
        // reference) once did, made a secret unfilterable.
        try {
            return $value instanceof CooperSecret
                ? new CooperSecret(Filters::apply($value->reveal(), $chain))
                : Filters::apply($value, $chain);
        } catch (\InvalidArgumentException $e) {
            throw self::error("{$label}: " . $e->getMessage(), $e);
        }
    }

    /**
     * Indexes into a list (a negative index counts from the end); into a
     * secret list it yields a secret element. Anything else, or an index
     * out of range, counts as "not found" so a default can apply.
     *
     * @return array{0: bool, 1: mixed}
     */
    private static function index(mixed $value, ?int $index): array
    {
        if ($index === null) {
            return [true, $value];
        }
        if ($value instanceof CooperSecret) {
            [$found, $item] = self::index($value->reveal(), $index);

            return [$found, $found ? new CooperSecret($item) : null];
        }
        if (!is_array($value)) {
            return [false, null];
        }
        $list = array_values($value);
        $position = $index < 0 ? count($list) + $index : $index;

        return array_key_exists($position, $list) ? [true, $list[$position]] : [false, null];
    }

    // ---- interpolated strings ---------------------------------------------------

    /**
     * Joins an interpolated string. Each segment contributes its real
     * text and its display text -- identical, except for a secret, whose
     * display text is its redacted form -- so only the secret *portion*
     * of the final string is redacted, and several secrets each redact
     * independently.
     *
     * @param list<mixed> $segments
     */
    private function text(array $segments): string|CooperSecret
    {
        $real = '';
        $shown = '';
        $secret = false;
        foreach ($segments as $segment) {
            $resolved = $this->value($segment);
            try {
                if ($resolved instanceof CooperSecret) {
                    $real .= Display::toString($resolved->reveal());
                    $shown .= (string) $resolved;
                    $secret = true;
                } else {
                    $text = Display::toString($resolved);
                    $real .= $text;
                    $shown .= $text;
                }
            } catch (\InvalidArgumentException $e) {
                throw self::error($e->getMessage(), $e);
            }
        }

        return $secret ? new CooperSecret($real, $shown) : $real;
    }

    private static function error(string $message, ?\Throwable $previous = null): CooperError
    {
        return new CooperError($message, CooperError::STAGE_RESOLVE, null, null, null, $previous);
    }
}

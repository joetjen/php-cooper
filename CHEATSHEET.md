# Cheatsheet

Quick reference for php-cooper's public API. See the
[tutorial](TUTORIAL.md) for a walkthrough, or [CASC.md](casc/CASC.md)
for the language itself.

## Load config

```php
use JOetjen\Cooper\Cooper;

Cooper::loadFile(string $path, array $options = []): array
Cooper::loadString(string $source, array $options = []): array
Cooper::loadFileTraced(string $path, array $options = []): LoadTrace
// ->config (what loadFile() returns), ->files (absolute paths read),
// ->globs (list<ImportGlob>: ->root, ->pattern, ->files, ->changed()),
// ->env (name => value it saw, null when unset/empty)
// throw JOetjen\Cooper\CooperError on any load failure,
// \InvalidArgumentException on a malformed $options array
```

`loadFile()` resolves bare imports relative to the file, detects an
import cycle back to it, and caches (see [Caching](#caching)).
`loadString()` takes the full CASC text, version header included; give
it a `root` if it uses bare imports. It never caches.

## Options

| Key | Type | Default | Effect |
|---|---|---|---|
| `env` | `array<string, string>` | `[]` | **Override**, not replacement, for `${...}`: wins for the names it defines, everything else falls through to `.env` files and the real environment. |
| `dotenv` | `bool` | `true` | `false` turns `.env` file loading off. |
| `dotenvEnv` | `?string` | `COOPER_ENV` (real env, `env`, then `.env`) | Which `.env.<env>` file to read -- `.env.dev`, `.env.prod`, ..., never `.env.production`; `null` reads none. |
| `dotenvFiles` | `list<string>` | `['.env', '.env.<env>', '.env.local']` | Replaces the file list outright. A relative entry resolves against `dotenvDir`; an absolute one is used as is. |
| `dotenvDir` | `string` | the project root: the Composer root package's directory, else the working directory | Where `.env` files are read from (`Dotenv::projectRoot()` gives the default). |
| `dotenvOverride` | `bool` | `false` | Put the files *above* the real environment. |
| `root` | `string` | file's directory / working directory | Where a bare `import` resolves. |
| `resolvers` | `array<string, callable(string): mixed>` | `[]` | One per `!{name:payload}` resolver. Return the value or throw. |
| `tags` | `array<string, callable(mixed): mixed>` | `[]` | `!Name(arg)` tags beyond the built-ins (may override one). Return or throw. A `LiteralTag` takes only a string literal written in the document. |
| `modules` | `array<string, mixed>` | `[]` | What `!module("Name")` gives back, by the name exactly as written (case-sensitive, trimmed). Consulted before the convention; any value. |
| `importSchemes` | `array<string, callable(string): string>` | `[]` | One per `import "scheme://rest"`; receives `rest`, returns CASC source. |
| `cache` | `bool` | `true` | `loadFile()` only. `false` bypasses the cache. |
| `envValue` | `callable(EnvReference, Closure(): mixed): mixed` | none | What a `${NAME}` written as a value resolves to -- for a framework keeping it a runtime placeholder. Gets the reference (name, `[]`/index, suffix, filters, a tag written directly around it) and a closure giving Cooper's own value. Guards, import paths and keys never reach it. |

An unregistered resolver, tag, or scheme is always a load-time error
naming it (CASC.md §9.4).

## Result values

| CASC | PHP |
|---|---|
| block / map | `array`, string keys (numeric-looking keys become `int`) |
| list `[...]` | list `array` |
| tuple `(...)` | `CooperTuple` -- `->items`, `count()`, `[i]`, `foreach`; read-only |
| `info`, `:info`, `:inf` | `CooperAtom` -- interned, `->name`, `CooperAtom::of('info')` |
| `nil` / `true` / `false` (also `:nil` / `:true` / `:false`) | `null` / `true` / `false` |
| `inf`, `+inf`, `-inf` | `INF`, `INF`, `-INF` |
| `42`, `0xFF`, `1_000` | `int`; beyond 64 bits a `CooperInteger` -- `->digits`, `(string)` |
| `3.14`, `5e+22` | `float` |
| `"..."`, `'...'`, `"""..."""` | `string` |
| `500ms`, `1h30m`, `!duration("5m")` | `CooperDuration` -- `->nanoseconds` (`int`, or `CooperInteger` beyond 64 bits), `toMilliseconds()`, `toSeconds()` |
| `512MiB`, `10GB`, `!bytes("1KiB")` | `CooperBytes` -- `->bytes` (`int`, or `CooperInteger` beyond 64 bits) |
| `127.0.0.1`, `10.0.0.0/8` | `CooperIPv4` |
| `::1`, `2001:db8::/32` | `CooperIPv6` |
| `1979-05-27T07:32:00.120Z`, `...+02:00` | `CooperDateTime` -- in UTC, `->instant`, `->precision` (fractional digits as written), `toDateTimeImmutable(?tz)` |
| `1979-05-27T07:32:00` | `CooperLocalDateTime` -- `->date`, `->time`, `toDateTimeImmutable(?tz)` |
| `1979-05-27` | `CooperDate` -- `->year`, `->month`, `->day`, `toDateTimeImmutable(?tz)` |
| `07:32:00.5` | `CooperTime` -- `->hour`, ..., `->microsecond` |
| `*key = ...` | `CooperSecret` around the value |

Every value class lives in `JOetjen\Cooper\Value`, has `equals()`, and
encodes sensibly with `json_encode()`.

## Built-in tags

| Tag | Accepts | Yields |
|---|---|---|
| `!int(x)` | int, or a string of digits (sign, surrounding whitespace ok) | `int` |
| `!float(x)` | float, int, or numeric string (`"1e3"`) | `float` |
| `!bool(x)` | bool, `"true"`/`"1"`/`"yes"`/`"on"`, `"false"`/`"0"`/`"no"`/`"off"` (lower case only) | `bool` |
| `!duration(x)` | duration text (`"1h30m"`) | `CooperDuration` |
| `!bytes(x)` | byte-size text (`"512MiB"`) | `CooperBytes` |
| `!trim(x)` / `!downcase(x)` / `!upcase(x)` | string | `string` |
| `!module(x)` | dot-separated PascalCase: `"App.Client"` | the `modules` entry for `"App.Client"`, else the class-string `"App\Client"` (never loaded) |

`!module` refuses anything but dot-separated PascalCase -- `crypto`,
`Foo.bar`, `Foo_Bar`, `Foo\Bar`, `./x.js` -- even a name `modules` maps:

```php
Cooper::loadFile('app.casc', ['modules' => ['Store' => \App\Cache\RedisStore::class]]);
// store = !module("Store")             => 'App\Cache\RedisStore' (mapped)
// client = !module("Acme.HTTPClient")  => 'Acme\HTTPClient'      (convention)
// s = "c=%{client}"                    => 'c=Acme.HTTPClient'     (a string reads the written name)
// t = "s=%{store}"                     => 's=App\Cache\RedisStore' (a mapped one reads as its value)
```

## Literal-only tags

```php
Cooper::loadFile('app.casc', ['tags' => ['php' => new LiteralTag(fn (string $code) => run($code))]]);
// a = !php("1 + 1")              ok: "...", '...' or """...""" written in the document
// b = !php(${CODE})              refused, naming the key and the file -- never called
// c = !php("x ${Y}"), !php(@{v}), !php(%{k}), !php(!trim("x"))   refused too
```

A host-specific integration feature, for a tag that runs its argument
as code: no value from the environment can become its argument. A
throwing literal tag also fails the load naming the key and the file.

## Filters

`@{...}`, `${...}`, and `%{...}` all take `| filter` clauses, applied
left to right after any `:default`/`:+alt` suffix has settled:
`trim`, `downcase`, `upcase`, `trim_prefix: "..."`, `trim_suffix:
"..."`. Arguments may be single-quoted -- the form that works inside an
interpolated string. A non-string value is an error, not a coercion.

## Reference suffixes

| Form | Meaning |
|---|---|
| `${NAME:default}` | `default` when unset or empty |
| `${NAME:+alt}` | `alt` when set, else `""` |
| `${NAME:?"message"}` | fail loading with `message` when unset |
| `${NAME[]}` / `${NAME[i]}` | split on `,`/`;` into a list / take element `i` |
| `@{list[i]}`, `%{path[i]}` | index a list (negative counts from the end) |
| `${?NAME} statement` | skip `statement` when `NAME` is unset or empty |
| `${"PREFIX_@{id}"}`, `@{"@{which}"}`, `%{a."k-@{id}"}` | a name or key built by interpolation |

## Secrets

```php
$secret = $config['database']['password'];   // CooperSecret
(string) $secret;          // "[~~REDACTED~~]" (or the partially redacted text)
json_encode($secret);      // "\"[~~REDACTED~~]\""
$secret->reveal();         // the real value
$secret->redacted;         // display text of a partial secret, or null
```

A `%{...}` copy or a `for ... from` template copy of a secret stays
secret; a tag applied to a secret, or an index into one, yields a
secret; a secret interpolated into a larger string redacts only its
own portion. `var_export()` and `serialize()` are not redacted.

## IP addresses

```php
CooperIPv4::parse('192.168.1.200/24');      // or new CooperIPv4([192, 168, 1, 200], 24)
$ip->address;        // [192, 168, 1, 200]
$ip->prefix;         // 24, or null
$ip->contains($other);
$ip->network();      // 192.168.1.0/24
$ip->broadcast();    // 192.168.1.255/24 (IPv4 only)
$ip->netmask();      // 255.255.255.0
$ip->firstHost();    // 192.168.1.1
$ip->lastHost();     // 192.168.1.254
```

`CooperIPv6` has the same API without `broadcast()`, and renders exactly
as Erlang's `:inet.ntoa/1` (`2001:db8::1`, `::ffff:1.2.3.4`).

## Caching

```php
use JOetjen\Cooper\Cache;
use JOetjen\Cooper\CacheEvent;

Cache::invalidate(string $path): void   // drop one file's entries
Cache::clear(): void                    // drop everything
Cache::listen(callable $listener): int  // receives CacheEvent
Cache::unlisten(int $id): void
```

`loadFile()` caches the pre-resolve tree in process memory, keyed by
file and root, valid while the file and its imports keep their
modification times, every import pattern (`parts/*.casc`) matches the
same files, and every tree-shaping environment variable (`${?NAME}`
guards, `${NAME}` in import paths and keys) keeps its value. `${...}`
values are re-read on every load.

| Event type | `$event->changed` | Fires |
|---|---|---|
| `CacheEvent::FILE_CHANGED` | the changed files | a cached entry is re-parsed because a contributing file changed (never on first load) |
| `CacheEvent::ENV_CHANGED` | the changed variable names | a cached entry is dropped because a tree-shaping variable changed |

## .env files

```text
.env < .env.<env> < .env.local < real environment < env option
```

Parsed by `vlucas/phpdotenv`, which Cooper depends on. Files are read
from `dotenvDir` (default: the project root -- the Composer root
package's directory -- wherever the process was started); missing files are skipped, malformed ones fail (stage `dotenv`).
`Dotenv::env(array $options)` returns the merged environment a load
would see.

`Dotenv::export(array $options)` -- for framework integrations standing
in for the framework's own `.env` loader, and only when called: writes
the names the files define, plus `COOPER_ENV`, into `$_ENV`, `$_SERVER`
and `putenv()` (each with the value `${NAME}` resolves to) and returns
what it wrote. A name already in `getenv()`, `$_ENV` or `$_SERVER`
(not `HTTP_*`) is left alone unless `dotenvOverride` is set. Idempotent.
Host-specific, not CASC; a load never touches the process environment.

```php
Dotenv::export();          // ['DB_HOST' => '127.0.0.1', 'COOPER_ENV' => 'dev']
getenv('DB_HOST');         // '127.0.0.1'
```

`${COOPER_ENV}` is always set: a real (non-empty) `COOPER_ENV` from any
layer wins, used as is; else `APP_ENV` from those layers, mapped onto
the shared names; else `dev`. One document selects per-environment files the same
way in every Cooper implementation:

```casc
import "env/${COOPER_ENV}.casc"
```

| `APP_ENV` | `COOPER_ENV` |
|---|---|
| `development`, `local` | `dev` |
| `testing` | `test` |
| `production` | `prod` |
| anything else (`dev`, `staging`, `test`, `prod`, `qa`, ...) | unchanged |

Exact and case-sensitive; a real `COOPER_ENV` is never mapped. The same
`COOPER_ENV`, as the real environment, `env` and `.env` set it, names
the `.env.<env>` file: `APP_ENV=production` reads `.env.prod`.

## Errors

`CooperError extends \RuntimeException`:

| Property | |
|---|---|
| `$stage` | which part of the pipeline failed (below) |
| `$reason` | the message without the location suffix |
| `$sourceLine`, `$sourceColumn` | 1-based CASC position, for syntax and literal errors |
| `$sourceFile` | the CASC file, when the source came from one |

| Stage | Raised for |
|---|---|
| `lexer` / `parser` | syntax the grammar rejects |
| `action` | a literal that parses but is invalid: an IP octet over 255, `2023-02-30`, `30m1h`, `-00:00` |
| `loop` | an index-only loop, mismatched list lengths, a `%{...}` or non-list iterable |
| `import` | an unreadable or unmatched file, an import cycle, an unregistered scheme, a bad `${...}` in a path |
| `merge` | `~`/`+`/`-` against a tuple, `+`/`-` against a non-list |
| `resolve` | an undefined reference, `:?"msg"`, a reference cycle, an unregistered resolver/tag, a failed tag or filter |
| `dotenv` | a malformed `.env` file |

## Pipeline stages

Only needed to run part of the pipeline directly (tests, tooling); all
under `JOetjen\Cooper\Pipeline` and marked `@internal`:

| Class | Stage |
|---|---|
| `Grammar` | parse + evaluate + merge (`run()`, `runTree()`, `runOps()`, `runFile()`) |
| `Evaluator`, `Loop`, `Loader` | statements to flat ops; loop expansion; imports |
| `Merge` | ops to a tree |
| `Resolver` | resolve every reference |

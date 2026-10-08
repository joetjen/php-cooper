# php-cooper

[![CI](https://github.com/joetjen/php-cooper/actions/workflows/ci.yml/badge.svg)](https://github.com/joetjen/php-cooper/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/joetjen/cooper.svg)](https://packagist.org/packages/joetjen/cooper)
[![Docs](https://img.shields.io/badge/docs-phpDocumentor-blue.svg)](https://joetjen.github.io/php-cooper/)

A PHP port of [`cooper`](https://github.com/joetjen/cooper), the Elixir
loader for [CASC](casc/CASC.md) config files -- a hierarchical,
extensible config language with imports, variables, string
interpolation, environment/config references, loops, and
consumer-registered resolvers and tags -- into native PHP values.

```text
# config.casc
#@version = 1.0

@region = "eu-west"

server {
  host = "0.0.0.0"
  port = 8080
}

database {
  *password = ${DB_PASSWORD}
  host = "db.@{region}.internal"
  pool_size = 10
  timeout = 500ms
}
```

```php
use JOetjen\Cooper\Cooper;

$config = Cooper::loadFile('config.casc', ['env' => ['DB_PASSWORD' => 'hunter2']]);

$config['server']['port'];                    // 8080
$config['database']['host'];                  // "db.eu-west.internal"
(string) $config['database']['password'];     // "[~~REDACTED~~]"
$config['database']['password']->reveal();    // "hunter2"
$config['database']['timeout']->nanoseconds;  // 500000000
```

**Status: feature-complete, pre-1.0.** The whole CASC language as the
Elixir original implements it -- every value type, imports (globs,
braces, `scheme://` loaders, `${NAME}` in a path), public/private
variables, loops, merge sigils, all five reference forms with suffixes
and filters, built-in and consumer tags, secrets with partial
redaction -- plus `.env` layering and file caching. See
[CHANGELOG.md](CHANGELOG.md).

## The value model

Blocks/maps come back as associative `array`s with string keys, lists
as list `array`s. Everything a PHP array cannot represent faithfully
gets a small value class under `JOetjen\Cooper\Value`, following the
`Cooper`-prefixed naming this user's PHP libraries share:

| CASC | PHP |
|---|---|
| block / map | `array` (string keys; PHP turns `"0"` into `0`) |
| list `[...]` | `array` (a list) |
| tuple `(...)` | `CooperTuple` -- never a list, CASC.md §6.11 |
| atom `info`, `:info` | `CooperAtom` (interned: `=== CooperAtom::of('info')`) |
| `nil`, `true`, `inf` (and `:nil`, `:true`, `:false`) | `null`, `bool`, `INF`/`-INF` |
| integer, float | `int`, or `CooperInteger` beyond 64 bits; `float` |
| `500ms`, `1h30m` | `CooperDuration` (nanoseconds: `int`, or `CooperInteger` beyond 64 bits) |
| `512MiB`, `10GB` | `CooperBytes` (bytes: `int`, or `CooperInteger` beyond 64 bits) |
| `127.0.0.1/8`, `::1` | `CooperIPv4`, `CooperIPv6` (CIDR math included) |
| `1979-05-27T07:32:00Z` | `CooperDateTime`, in UTC, fractional digits as written; `toDateTimeImmutable(?tz)` |
| `1979-05-27T07:32:00` / `1979-05-27` / `07:32:00` | `CooperLocalDateTime` / `CooperDate` / `CooperTime` |
| `*key = ...` | `CooperSecret` around the value |

Secrets redact themselves in string conversion, `json_encode()`,
`var_dump()`, and `print_r()`; `reveal()` is the one deliberate way
to the real value. The wrapping travels with the value through every
`%{...}` copy, and a secret interpolated into a larger string redacts
only its own portion.

## Where this port differs from the Elixir original

PHP-specific choices:

- **Exceptions, not tuples.** Every failure throws `CooperError`, whose
  `$stage` keeps the Elixir vocabulary (`parser`, `import`, `resolve`,
  ...) and which carries the CASC source line/column/file when known.
- **Callbacks return or throw.** A resolver, tag, or import-scheme
  callable returns its value directly and signals failure by throwing,
  instead of `{:ok, v}`/`{:error, reason}`.
- **Integers** are a native `int` when they fit in 64 bits, and a
  `CooperInteger` (every digit kept, `bcmath`-backed, as
  php-dextrin's) beyond -- the Elixir original's are arbitrary-precision
  throughout. A duration's or byte size's count follows the same rule.
- **`!module("Acme.Payments")`** yields a PHP class-string,
  `"Acme\Payments"`: the name is written the same way for every Cooper
  implementation -- dot-separated PascalCase, anything else a load
  error -- and each translates it into its own convention (CASC.md
  §7.5). The `modules` option maps a name exactly as written to
  whatever `!module` should give back for it instead
  (`['modules' => ['Store' => RedisStore::class]]`); the class is never
  loaded or checked.
- **`.env` support** parses with
  [`vlucas/phpdotenv`](https://github.com/vlucas/phpdotenv), a
  dependency of Cooper's (the Elixir original uses `dotenvy`). The
  `.env.<env>` file is named by `COOPER_ENV`, as in every Cooper:
  `.env.dev`, `.env.staging`, `.env.test`, `.env.prod` -- so Laravel's
  `APP_ENV=production` reads `.env.prod`, never `.env.production`. The
  files are read from `dotenvDir`, by default the project root: the
  Composer root package's directory (the Elixir original: the Mix
  project's), else the working directory.
- **`Dotenv::export()`** has no Elixir counterpart and is not CASC
  behaviour: it exists for framework integrations that replace the
  framework's own `.env` loader. Called explicitly -- never by a load,
  never by default -- it writes the names the `.env` files define, and
  `COOPER_ENV`, into `$_ENV`, `$_SERVER` and `putenv()`, with the value
  `${NAME}` resolves to, so code reading the process environment
  directly (Laravel's `env()`, Symfony's `APP_ENV`) sees them too. A
  variable the real environment already has is never overwritten,
  unless `dotenvOverride` is set; it returns what it wrote, and calling
  it twice changes nothing more.
- **`${COOPER_ENV}`** is always set (CASC.md §7.2): a real `COOPER_ENV`
  wins; unset or empty, it falls back to `APP_ENV` -- read from the same
  layers as every other name, so a `.env` file setting it counts -- else
  `"dev"`. The fallback is mapped onto the names every Cooper uses
  (`dev`, `staging`, `test`, `prod`) through CASC.md §7.2's one table,
  so Laravel's `APP_ENV` values work as they are: `development` and
  `local` become `dev`, `testing` becomes `test`, `production` becomes
  `prod`, and anything else (`staging`, `qa`, ...) is passed through. A
  real `COOPER_ENV` is never mapped. The Elixir original falls back to
  `MIX_ENV`, then the live `Mix.env/0`.
- **`Cooper::loadFileTraced()`** has no Elixir counterpart: it returns
  the config together with every file the load read and every
  environment variable it read (with the value it saw), for a caller
  that keeps the result where `Cache` cannot see it -- a compiled PHP
  file, which shared-nothing PHP wants, is the reason it exists.
- **The `envValue` load option** has no Elixir counterpart: it lets a
  host decide what a `${NAME}` written as a value resolves to (see
  `EnvReference`) -- for a framework that compiles configuration into a
  cache and must keep the environment a runtime placeholder
  (`joetjen/cooper-symfony` turns it into Symfony's `%env(NAME)%`).
  PHP's frameworks compile their configuration; the BEAM's read it at
  boot.
- **`LiteralTag`** has no Elixir counterpart: a tag registered as
  `new LiteralTag($fn)` takes only a string literal written in the
  document, and the load fails -- naming the tag, the key and the file
  -- when its argument is anything else (`${...}`, `@{...}`, `%{...}`,
  another tag, an interpolating string). It is for a framework
  integration's tag that runs its argument as code
  (`joetjen/cooper-laravel`'s `!php("""...""")`), so that no value from
  the environment can ever become code. Host-specific integration
  behaviour, not CASC's: a plain callable tag takes any argument.
- **Caching** lives in process memory, and a tree-shaping environment
  variable (a `${?NAME}` guard, `${NAME}` in an import path) is checked
  on every load instead of by a background poller -- so a guard's
  decision is never stale. Change events go to `Cache::listen()`
  callbacks instead of `:telemetry`.

Porting the Elixir original also found ten places where it (as of
0.4.0) contradicted CASC.md, and a corpus of conformance cases shared
by every Cooper implementation (`tests/conformance`, see its
`DIVERGENCES.md`) found fourteen more. All are fixed in the Elixir
original (unreleased at the time of writing), which is the reference
this port matches case for case:

- `foo "bar"` -- an assignment without `=` to a string, §5.3's own
  example -- is an assignment, not a failed `import`.
- A `#`-disabled statement (§5.6) has no effect at all.
- A merge sigil inside a block keeps its meaning (`a { +tags = [...] }`
  appends, `a { -b }` deletes).
- A bare `-key.path` followed by another complete statement is a delete
  (the rule CASC.md §5.7 now states), and `+info = [...]` appends to
  `info`.
- An interpolated key (`"region-@{name}" = ...`, §4.2) resolves outside
  `for` loops, from `@{...}` and `${...}`, and must be a non-empty
  string with no `.` inside one too; a loop binding substitutes into a
  `%{...}` path, a default, and a filter argument, and keeps the
  reference's filters, index, and suffix (`@{x | upcase}`); a `~key { }`
  in a loop body clears under the generated destination.
- Interpolating a list, map, or tuple is a clean `resolve` error; an
  impossible date (`2023-02-30`) a clean load error.
- A `${NAME}` in an import path counts as tree-shaping, like a guard.
- A value inside a string reads as CASC writes it (§7): `inf`/`-inf`,
  `nil`, every digit of a large integer.
- `${PORT:+1}` substitutes `1`; `${PORT:-1}` is a default of minus one.
- A private variable reads another private one, and shadows an imported
  public one of the same name in its own file only (§5.2).
- `+key = @{list}` appends the list's elements wherever `@list` is
  declared, and `+`/`-` in a `for ... from` body edit the template's
  copy (§5.5, §8.4); `+key = nil` appends `nil`, `-key = nil` removes
  it, and removal is strict (`1` is not `1.0`).
- A duration takes `_` digit separators (`1_000ms`, §6.8).
- A secret can be filtered (`%{pw | trim}`) and stays a secret.
- A comment may come before the `#@version` header.

Backslash-continued strings (§6.5) remain unimplemented here, as in the
Elixir original -- the spec gives no worked example to validate against.

## Documentation

- [QUICKSTART.md](QUICKSTART.md) -- install and load a first file.
- [TUTORIAL.md](TUTORIAL.md) -- the library and the CASC syntax it
  parses, built up one feature at a time.
- [EXAMPLES.md](EXAMPLES.md) -- secrets managers, per-environment
  overlays, testing config without disk or network, CIDR allowlists.
- [CHEATSHEET.md](CHEATSHEET.md) -- the public API and every option.
- [casc/](casc/) -- the implementation-independent CASC specification
  ([CASC.md](casc/CASC.md)), tutorial, examples, and cheatsheet, shared
  with the Elixir original.
- [API documentation](https://joetjen.github.io/php-cooper/) --
  generated with phpDocumentor.

## Installation

```sh
composer require joetjen/cooper
```

Requires PHP 8.2+ with `mbstring`.

## Development

```sh
composer install
composer run precommit   # PHPStan (level 8) + PHPUnit
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for how to propose changes.

## License

Apache-2.0 -- see [LICENSE](LICENSE).

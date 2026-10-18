# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **An adapter for the Cooper Conformance Suite**, the twin of the
  Elixir reference's `Cooper.Conformance`: `php bin/cooper-conformance
  REQUEST ANSWER`, its work in `ConformanceAdapter`. It reads a JSON
  request of `load` cases and answers each, in order, with the loaded
  value in the suite's typed JSON (`{"integer": "42"}`,
  `{"float": "2.0"}`, `{"keyword": "info"}`, `{"map": [...]}`, CASC's
  own values as tuples tagged `casc.duration`, `casc.secret`,
  `casc.module`, ...) or with the stage of the error that refused it.
  An unknown operation and a crash answer for their own case only; the
  command exits non-zero only when the request cannot be read or the
  answer cannot be written. Every case loads with the suite's standard
  options: its environment, no `.env`, the document's directory as the
  root, the resolvers `echo` and `fail`, the tag `twice` and the import
  scheme `mem`. A module answers by the name CASC wrote (`Foo.Bar`),
  not the class-string a load gives back.

### Changed

- The in-repo conformance corpus loads with the adapter's options and
  renders a module by its written name, so cases `163` and `920` match
  the reference's expected result and their `*.php.json` overrides are
  gone.

## [0.1.2] - 2026-10-14

### Fixed

- **A `%{...}` path is followed through a reference** (CASC.md §7.3).
  With `origin = %{origins.eu}`, `%{origin.code}` was an undefined
  reference: the path was looked up in the unresolved tree, and stopped
  at the reference it ran into. It resolves against the final tree, as
  §7.3 always said, so it finds `origins.eu.code` -- through a chain of
  references too, and through any other value that resolves to a map,
  such as a loop variable bound to a block element. A path continuing
  into a value that resolves to anything but a map is undefined, and
  takes its default if it has one. A path through its own key
  (`a = %{a.b}`) is a cycle, reported as `a.b -> a -> a.b`. A path into
  a secret block -- `*site = @{s}` copying a block element -- finds its
  value, and that value stays secret. Conformance cases
  `188-ref-through-ref`, `189-ref-through-own-key`,
  `190-ref-through-scalar` and `191-ref-into-secret-block`;
  `casc/CASC.md` updated from the reference.

## [0.1.1] - 2026-10-13

### Fixed

- **A name may begin with `_`** (CASC.md §4.1), as in the reference
  implementation. Keys, atoms and variable names share one rule, and it
  asked for a letter first, so `__sentry__` could not be written as an
  atom -- bare or as `:__sentry__` -- nor `__foo__` as a key or a
  variable. Conformance case `014-underscore-names`.

## [0.1.0] - 2026-10-08

### Added

- **`LiteralTag`**, a tag that takes only a string literal written in
  the document: registered as `'tags' => ['php' => new LiteralTag($fn)]`,
  it receives `!php("...")`, `!php('...')` and `!php("""...""")`,
  and the load fails before calling it -- naming the tag, the key
  (`at db.hosts[1]`) and the file and line -- when the argument is a
  reference, a variable (a loop's included), another tag, a string that
  interpolates, or any non-string value, or when the tag is
  interpolated into a string. A literal tag that throws fails the load
  naming the key and the file too. For a framework integration's tag
  that runs code (`joetjen/cooper-laravel`'s `!php`), so no
  environment value can become code. Host-specific; plain callable
  tags are unchanged.
- **The `envValue` load option** decides what a `${NAME}` written as a
  value resolves to, for a host that resolves the environment later
  than the load -- a framework compiling configuration into a cache,
  which must keep `${DB_PASSWORD}` a runtime placeholder. It receives an
  `EnvReference` (the name, the `[]`/index form, the suffix and its
  value on request, the filters, and a tag written directly around the
  reference, as in `!int(${PORT})`) and a closure giving the value
  Cooper would have resolved. A `${NAME}` that shapes the document --
  a `${?NAME}` guard, an import path, an interpolated key -- never
  reaches it, and a name it answered for without reading is not in a
  `LoadTrace`'s `$env`. Without the option nothing changes.
- **A list or tuple element may be a block -- a map** (CASC.md §6.10):
  `access_control = [{ path = "^/admin" }]`. Its keys interpolate, a
  loop binding reaches it, and `+`/`-` append or remove whole maps,
  compared by value. A list of maps could not be written before.
- **`Dotenv::export()`** writes what the `.env` files provided into the
  process environment -- `$_ENV`, `$_SERVER` and `putenv()` -- for
  framework integrations that replace the framework's own `.env`
  loader. Opt-in only: a load never calls it. It exports every name the
  files define and `COOPER_ENV`, each with the value `${NAME}`
  resolves to; never overwrites a variable the real environment
  already has (`getenv()`, `$_ENV`, or a non-`HTTP_*` `$_SERVER`
  entry) unless `dotenvOverride` is set; returns what it wrote; and is
  idempotent. Host-specific, not CASC behaviour.
- **`${COOPER_ENV}` is always set**: the one name a document reads the
  current environment by in every Cooper implementation (CASC.md §7.2).
  A real `COOPER_ENV` wins; unset or empty it falls back to `APP_ENV`
  (from the same `.env`/environment/`env` layers), then `"dev"`. The
  `APP_ENV` fallback is mapped onto the names every Cooper uses (`dev`,
  `staging`, `test`, `prod`) through CASC.md §7.2's one table --
  `development` and `local` become `dev`, `testing` becomes `test`,
  `production` becomes `prod`, anything else is passed through -- so
  Laravel's names work as they are. The cache's environment check sees
  the fallback too.
- **The `.env.<env>` file is named by `COOPER_ENV`**, as the real
  environment, `env` and the base `.env` set it: `.env.dev`,
  `.env.staging`, `.env.test` and `.env.prod`, the names every Cooper
  reads. Named by `APP_ENV` it read Laravel's `.env.production` and
  `.env.testing`. An explicit `dotenvEnv` still wins.
- **`Cooper::loadFileTraced()`** returns a `LoadTrace`: the config
  `loadFile()` returns, every file the load read, every filesystem
  import's expansion (`$globs`, each an `ImportGlob` whose `changed()`
  expands the pattern again), and every environment variable the
  document read with the value it saw -- what a cache kept outside this
  library (a compiled PHP file) needs to know when it has gone stale.
- `Cooper::loadFile()`/`Cooper::loadString()`: a PHP port of the Elixir
  [`cooper`](https://github.com/joetjen/cooper), loading CASC
  config files into native PHP values -- the whole language as the
  Elixir original implements it: every value type (CASC.md §6),
  dotted paths and blocks (§5.4), imports with globs, braces,
  `scheme://` loaders, and `${NAME}` in the path (§5.1), public and
  private variables with per-file visibility (§5.2), loops with
  parallel bindings and `from` templates (§5.5), disabled statements
  (§5.6), merge sigils (§5.7, §8), `@{...}`/`${...}`/`%{...}`/
  `!{...}`/`!Name(...)` references with suffixes, filters, and built
  names (§7), and `${?NAME}` guards.
- A hand-written lexer and backtracking parser reproducing the Elixir
  original's `casc.aether` grammar, maximal-munch token race included.
- Value classes for what a PHP array cannot represent: `CooperTuple`,
  `CooperAtom`, `CooperDuration`, `CooperBytes`, `CooperIPv4`/
  `CooperIPv6` (with CIDR math), `CooperDate`/`CooperTime`/
  `CooperLocalDateTime`, and `CooperSecret` (redacted in string
  conversion, `json_encode()`, `var_dump()`, `print_r()`; partial
  redaction inside interpolated strings).
- Built-in tags `!int`, `!float`, `!bool`, `!duration`, `!bytes`,
  `!trim`, `!downcase`, `!upcase`, and `!module` (yielding a PHP
  class-name string).
- `CooperError`, one exception type for every load failure, carrying
  the Elixir original's stage names and the CASC source location.
- `.env` layering (`Dotenv`), parsed by `vlucas/phpdotenv` -- a
  required dependency, as the Elixir original's `dotenvy` now is, so
  `.env` support is always there -- with the real environment
  outranking the files and `COOPER_ENV` selecting `.env.<env>`.
- `loadFile()` caching (`Cache`) of the pre-resolve tree, keyed by file
  modification times and the values of tree-shaping environment
  variables, with `CacheEvent::FILE_CHANGED`/`ENV_CHANGED` listeners.
- PHPUnit suite ported from the Elixir original's, plus property-style
  fuzz and surface-form-equivalence tests; `tests/SPEC_COVERAGE.md`
  traceability table; guides (`QUICKSTART.md`, `TUTORIAL.md`,
  `EXAMPLES.md`, `CHEATSHEET.md`); the CASC specification under
  `casc/`.
- The shared conformance corpus (`tests/conformance`): every case the
  Elixir reference's oracle ran, checked by `ConformanceTest`. Only the
  two cases whose result holds a module (`163`, `920`) carry a PHP
  override, holding the convention's class-strings for the same written
  names; see `tests/conformance/DIVERGENCES.md`.
- A `modules` load option (`array<string, mixed>`) on `loadFile()` and
  `loadString()`: what `!module("Name")` gives back, by the name exactly
  as written (case-sensitive, after trimming), consulted before the
  convention -- how a class outside it, or any other value, is reached
  (CASC.md §7.5). `loadFile()` honours each call's own mapping, cache or
  no cache.
- `CooperInteger`: an integer beyond PHP's 64-bit `int`, every digit
  kept (`bcmath`, now a required extension), as php-dextrin's.
- `CooperDateTime`: an offset date-time as an instant in UTC plus the
  number of fractional digits written, as Elixir's `DateTime` holds it;
  `toDateTimeImmutable(?tz)` for PHP's own type.
- Conformance cases 154-162: durations and byte sizes beyond 64 bits
  and beyond any float (literals, tags, strings, removal), and offset
  date-time precision (digits past the sixth, `.0`, partial-hour
  offsets, in a string); 170-178 (date-time fractions, offsets, `-00:00`,
  removal by precision, interpolation; user tags) vendored alongside.

### Changed

- **`!bool` reads `1`/`0`, `yes`/`no` and `on`/`off`** besides
  `true`/`false` (lower case only; anything else is still refused), so
  `DEBUG=1`, the commonest `.env` spelling of a boolean, no longer fails
  the load (CASC.md §7.5; conformance cases `186` and `187`).
- **An empty block is an empty map** (CASC.md §5.4): `w {}` and
  `w = {}` were no key at all, and `~w {}` removed the key. Written over
  a map that already exists it leaves that map as it is; `~w {}`
  empties it.
- **`!module` takes one written form, dot-separated PascalCase**
  (**breaking**, CASC.md §7.5): `Acme.Payments.StripeClient` gives
  `"Acme\Payments\StripeClient"`, every segment kept as written
  (`ASCO.HTTPClient` -> `"ASCO\HTTPClient"`). A backslash-separated
  name (`Foo\Bar`, `\Foo`), a lower-case segment (`crypto`,
  `Foo.bar`), an underscore (`Foo_Bar`), or a path is now a `resolve`
  error -- even when the `modules` mapping holds it -- so a document
  naming a module means the same module in every Cooper implementation.
  The class is still never loaded or checked at load; there was no
  such check to remove. Conformance cases 126 and 914 now expect an
  error, as the reference does, and lose their PHP overrides; 163-167
  are new.

What the conformance corpus showed differing from the reference, now
matching it (and CASC.md as amended alongside it):

- **An integer beyond 64 bits** is a `CooperInteger`, not a load error;
  `!int(...)` of one too, and it reads as its digits inside a string.
- **`:nil`, `:true`, `:false` are the values** `null`, `true`, `false`,
  no longer atoms (CASC.md §6.4). `:inf` stays an atom.
- **`inf` inside a string reads `inf`/`-inf`**, not `infinity`/
  `neg_infinity` (CASC.md §7).
- **`${PORT:+1}` substitutes `1`**; it was read as a default of `+1`.
  `${PORT:-1}` stays a default of minus one.
- **A private variable reads another private one** (`@*b = "v@{a}"`),
  and **shadows an imported public one** of the same name in its own
  file only, where it used to overwrite it.
- **`+key = @{list}`** with `@list` declared later appends the list's
  elements, and **`+`/`-` in a `for ... from` body** edit the template's
  copy: both wait for resolution (`Node\ListEdit`, `Node\Absent`).
- **`+key = nil` appends `nil`**, and `-key = nil` removes it; removal
  stays strict (`1` is not `1.0`).
- **A loop value keeps the reference's filters, index, and suffix**
  (`@{x | upcase}` in a body).
- **A key built from a loop binding** follows the interpolated-key rules:
  empty, or holding a `.`, is a `resolve` error. A loop iterable must
  name its variable, not build it.
- **A duration takes `_` separators** (`1_000ms`, `!duration("1_000ms")`).
- **A secret can be filtered** (`%{pw | trim}`) and stays a secret.
- **A duration or byte size beyond 64 bits loads**, its count a
  `CooperInteger` (`CooperDuration::$nanoseconds`, `CooperBytes::$bytes`
  are `int|CooperInteger`), in literals, `!duration`/`!bytes`, strings
  (`<digits>ns`, `<digits>B`), and strict removal; it was a load error.
- **A duration or byte size is computed exactly**, as the reference now
  computes it: the amount's digits times the unit, over 10^(fraction
  digits), rounded half away from zero -- no float, any size
  (`9007199254740993ns` is ...993, a 400-digit amount loads). Single
  units went through a float before, in the reference and here.
- **An offset date-time is a `CooperDateTime`**, no longer a
  `DateTimeImmutable` (**breaking**): it keeps the fractional digits as
  written (`.120`, `.0`) in its value, inside a string, and in strict
  removal, where `.5` and `.50` now differ.
- **Year `0000` is a date** (`0000-01-01`, and an offset date-time that
  normalizes into year `-0001` or `10000`); `checkdate()` refused it.
- **`-00:00` is not an offset** (RFC 3339's "unknown"); it was read as
  UTC.
- **`.env` files are read from the project root**, through a new
  `dotenvDir` option that is also the base of a relative `dotenvFiles`
  entry. They were read from the working directory, so an application
  started from anywhere but its own root read none of them. The default
  is the Composer root package's directory, else the working directory
  (`Dotenv::projectRoot()`), as the reference now defaults to the Mix
  project's.
- **The cache sees a file added where a glob import looks**: it
  fingerprinted only the files a load read, so an edited or deleted one
  was noticed and a new match for `import "parts/*.casc"` was not. Each
  import's expansion is now part of the fingerprint and is expanded
  again on every cache hit; the file it gained or lost is named in
  `CacheEvent::FILE_CHANGED`, as the reference now does.

### Compatibility

Matches the Elixir reference, [`cooper`](https://hex.pm/packages/cooper)
0.5.0, case for case on the shared conformance corpus. The reference has fixed every place it contradicted CASC.md
that porting it or the corpus surfaced -- see `README.md`. What remains
different is inherent to PHP: `!module(...)` yields a class-string
where the reference yields a module atom, and an integer (a duration's or byte size's count included) is
a native `int` until it no longer fits.

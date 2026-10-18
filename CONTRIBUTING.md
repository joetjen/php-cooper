# Contributing to php-cooper

Thanks for considering a contribution. This document covers what you
need to know before opening an issue or a pull request.

## Getting started

```sh
git clone <this repository>
cd php-cooper
composer install
composer test
```

That should complete with no failures on a clean checkout. If it
doesn't, please open an issue before doing anything else -- that's a
bug in its own right.

## Project layout

- `src/Cooper.php` -- the public API: static `loadFile()`/`loadString()`.
- `src/CooperError.php` -- the one exception type every load failure
  throws, with the Elixir original's stage vocabulary.
- `src/Cache.php`, `src/CacheEvent.php` -- `loadFile()`'s in-memory
  cache and its change events.
- `src/LoadTrace.php` -- what `loadFileTraced()` returns: the config
  and the files and environment variables it depended on.
- `src/Dotenv.php` -- `.env` layering for `${...}` (parsing via
  `vlucas/phpdotenv`).
- `src/Values.php` -- structural equality over loaded values.
- `src/Value/` -- the public value classes (`CooperSecret`,
  `CooperTuple`, `CooperAtom`, `CooperDuration`, `CooperBytes`,
  `CooperIPv4`/`CooperIPv6`, `CooperDate`/`CooperTime`/
  `CooperLocalDateTime`/`CooperDateTime`).
- `src/Syntax/` -- a hand-written maximal-munch `Lexer` and a
  backtracking recursive-descent `Parser` reproducing the Elixir
  original's `casc.aether` grammar rule for rule (no parser-generator
  dependency -- there is no PHP Ichor), the `Ast/` they build,
  `InterpParser` for references inside double-quoted strings, and
  `Literals` for number/duration/byte-size/string conversions.
- `src/Pipeline/` -- one class per Elixir pipeline module: `Evaluator`
  (`Cooper.Actions`), `Loop`, `Loader`, `Scope`, `Merge`, `Resolver`,
  plus `Grammar` (runs them), `Display` (interpolated text, matching
  Elixir's `to_string/1`), `Filters`, `Native`, `Paths`, `Options`.
- `src/Node/` -- the unresolved pipeline data (`Op`, `VarRef`,
  `MapNode`, ...), the counterparts of `Cooper.Op`/`Cooper.Ref.*`.
- `tests/` -- PHPUnit, mirroring `src/`'s own layout; `tests/fixtures/`
  holds CASC and `.env` fixtures (most copied from the Elixir suite);
  `tests/ConformanceAdapterTest.php` tests the Cooper Conformance
  Suite adapter (`src/ConformanceAdapter.php`, `bin/cooper-conformance`);
  `tests/SPEC_COVERAGE.md` maps every CASC.md section to its tests.
- `casc/` -- the implementation-independent CASC specification, copied
  verbatim from the Elixir original's `guides/casc/`.
- `QUICKSTART.md`/`TUTORIAL.md`/`EXAMPLES.md`/`CHEATSHEET.md` -- this
  project's own PHP-API-level guides.

## Making a change

1. **Tests first, or at least alongside.** A parser- or
   pipeline-level change should come with a test exercising actual
   input/output behavior, not just "does this parse."
2. **Check the Elixir original.** A change to how the *language*
   behaves (rather than a PHP-specific detail) should match `cooper` --
   or, where `cooper` contradicts `casc/CASC.md`, be listed in
   `README.md`'s "Where this port differs" section. Behaviour is
   checked across implementations by the Cooper Conformance Suite in
   the praxis repository (`libs_prx/cooper_conformance`, run by
   assize), so a behaviour change needs a case there too. This repo's
   adapter for it is `php bin/cooper-conformance REQUEST ANSWER`.
3. **Where inputs form a space bigger than a handful of examples
   usefully covers**, prefer a property-based/fuzz-style test (a
   hand-rolled, seeded randomized loop inside PHPUnit -- no dedicated
   property-testing package is used here) over enumerating more
   example cases by hand.
4. **Run the full verification pass before opening a PR:**

   ```sh
   composer run precommit
   ```

   Expands to `vendor/bin/phpstan analyse src --level=8` followed by
   the full PHPUnit suite.
5. **Match the existing code style.** `declare(strict_types=1)` at the
   top of every PHP file, native PHP 8 type declarations everywhere PHP
   itself can express them (not just PHPDoc), a PHPDoc block on every
   public class/method explaining *why* when that's non-obvious.
   Public value classes under `src/Value/` keep the `Cooper`-prefixed
   name even inside their own namespace.
6. **Keep documentation current.** Update PHPDoc, `README.md`,
   `CHANGELOG.md` (`[Unreleased]`), `tests/SPEC_COVERAGE.md`, and any
   guide the change touches in the same commit -- stale docs are worse
   than none. Before documenting any example, actually run it.

## Reporting bugs

Please include: the CASC source (or a minimal excerpt reproducing the
issue), the options you passed, what you expected, and what actually
happened -- including the full `CooperError` (message, stage, and source
location) if one was thrown.

## License

By contributing, you agree that your contributions will be licensed
under the project's [Apache License 2.0](LICENSE).

# AGENTS.md

Instructions for AI agents working in this PHP codebase.

## Communication

- Every response starts with the user's first name. Determine it from `git config user.name` (take the first name); if that's unavailable or ambiguous, ask once. Remember the answer for the rest of the session rather than re-deriving or re-asking.

## Before every commit

- Run `composer run precommit` and make sure it passes. No exceptions. It expands to `vendor/bin/phpstan analyse src --level=8` followed by `phpunit`.

## Tests

- Tests must stay current with behavior: a change to what code does needs its tests updated in the same commit, not "later" — a passing suite that no longer exercises real current behavior is worse than a failing one.
- Add tests for new behavior as you write it, not as a follow-up.
- Put new tests in the file that matches what you're touching, mirroring `src/`'s own layout under `tests/`. Add a new test file only when a change doesn't fit any existing one.
- Where inputs form a space bigger than a handful of examples usefully covers (parsers, encoders/decoders, merge/normalization logic, anything with an invariant that should hold for *all* inputs, not just the ones you thought of), prefer a property-based/fuzz-style test over enumerating more example cases by hand — hand-rolled randomized loops inside a PHPUnit test, following `abstractcli`'s and `php-dextrin`'s own convention (seeded `mt_srand()` so a failure reproduces), since no dedicated property-testing package is used here. Use ordinary example-based tests for fixed, specific scenarios and regressions.
- If you're not sure whether something is a "real" behavior or an accidental quirk, write a test that pins down the current behavior either way and say so in the test name/comment, rather than silently "fixing" it.

## Documentation

- Treat every documentation surface touched by a change as part of that change, not optional polish — PHPDoc blocks, README, guides, changelog, code comments explaining non-obvious behavior. None of it is allowed to go stale.
- Every public class needs a class-level PHPDoc block. Every public method needs a `@param`/`@return`/`@throws`-annotated PHPDoc block.
- Update PHPDoc whenever behavior changes — stale docs are worse than none.
- Keep external docs in sync with code changes in the same commit/PR: `README.md`, `QUICKSTART.md`, `TUTORIAL.md`, `CHEATSHEET.md`, `EXAMPLES.md`, `tests/SPEC_COVERAGE.md`, and anything under `casc/`.
- Before documenting *any* example or claim about behavior, run it — trust the running code over existing prose, not the other way around.
- Update `CHANGELOG.md` for every user-facing change, following [Keep a Changelog](https://keepachangelog.com/):
  - Add entries under `[Unreleased]` as you work.
  - Move them under a version heading on release, and remove the (now-empty) `[Unreleased]` section — a published release's changelog must not contain one.

## Generated/checked-in code

- If a file is produced by a generator and checked into the repo, never hand-edit it. Edit the source it's generated from, then re-run the generator and re-run the test suite. Say so at the top of the generated file and of its hand-written source, so this isn't tribal knowledge.

## Parity across implementations

- This project mirrors `cooper` (Elixir) -- the same CASC language (`casc/CASC.md`, copied verbatim from `cooper`'s `guides/casc/`), the same pipeline stages, the same error stages, and the same interpolated text for the same value. A behavior change here that also applies to the language itself (not a PHP-specific implementation detail) is worth checking against the Elixir original's behavior -- running a probe through `mix run` in `../cooper` is the quickest way.
- Behaviour is checked across implementations by the Cooper Conformance Suite in the praxis repository (`libs_prx/cooper_conformance`, run by assize); this repository holds no corpus of its own. A behaviour change needs a case there. This repo's adapter for it is `php bin/cooper-conformance REQUEST ANSWER` (`src/ConformanceAdapter.php`, tested by `tests/ConformanceAdapterTest.php`).
- Where this port deliberately departs from the Elixir original, it does so only where the original contradicts `casc/CASC.md` or where PHP forces a different call. Every such departure is listed in `README.md` ("Where this port differs") and explained at the point of difference in the code; keep both current.

## Static analysis

- `vendor/bin/phpstan analyse src --level=8` is part of `composer run precommit` — keep it clean. A new low-confidence finding is not automatically wrong, but isn't automatically fine either; give it a specific justification (a code comment or a targeted baseline entry naming the exact reason), never a blanket ignore.

## Coding style

- Follow `.editorconfig` (4-space indent for PHP, matching PSR-12).
- Target PHP 8.2+ (`composer.json`'s `require.php`) — readonly properties, enums, first-class callable syntax, and union/intersection types are all fair game.
- Every public value class under `src/Value/` keeps the `Cooper`-prefixed name even inside its own namespace (`JOetjen\Cooper\Value\CooperSecret`, not `...\Secret`) — matches this user's existing PHP libraries' own convention (`JOetjen\Dextrin\Value\DXNSymbol`, `JOetjen\GraphQL\GraphQLQueryOperation`). Internal pipeline classes (`src/Node/`, `src/Syntax/`, `src/Pipeline/`) are marked `@internal` and are not prefixed.

## Traceability

- `tests/SPEC_COVERAGE.md` maps every section of `casc/CASC.md` to the tests covering it. Update it in the same commit as the change it tracks, not as an afterthought.

## Git workflow

- Use [git flow](https://nvie.com/posts/a-successful-git-branching-model/): `main` (releases), `develop` (integration), `feature/*`, `release/*`, `hotfix/*`, `support/*`.
- No direct commits to `main` or `develop`. Branch, then merge/PR back. Base pull requests on `develop` unless you're specifically preparing a release.
- Before starting work, check out onto a git flow branch (`feature/*`, `release/*`, `hotfix/*`, `support/*`) — never work directly on `develop`, unless explicitly told to.

## Commits

- Use [Conventional Commits](https://www.conventionalcommits.org/): `<type>[optional scope]: <description>`.
- Common types: `feat`, `fix`, `docs`, `style`, `refactor`, `perf`, `test`, `chore`, `build`, `ci`.
- Breaking changes: `!` after type/scope, or a `BREAKING CHANGE:` footer.

## Versioning

- Use [Semantic Versioning](https://semver.org/): `MAJOR.MINOR.PATCH`.
- `MAJOR` — breaking changes. `MINOR` — backward-compatible features. `PATCH` — backward-compatible fixes.
- Bump the version in `composer.json` (if a `version` field is ever added) and in tags to match, matching the changelog entry when tagging a release.

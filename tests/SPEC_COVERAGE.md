# Spec coverage

Traceability table mapping every section of
[`CASC.md`](../casc/CASC.md) to the test(s) covering it -- the PHP
counterpart of the Elixir original's own `test/SPEC_COVERAGE.md`. Kept
honest rather than complete-looking: the one genuine gap is called out
at the bottom.

Legend: ✅ covered · ⚠️ partially covered / known gap · N/A out of scope
per the spec itself.

| § | Topic | Status | Test(s) |
|---|---|---|---|
| 1 | Overview | ✅ | Exercised throughout; no standalone test (a summary section). |
| 2 | Mandatory header | ✅ | `Pipeline/GrammarTest` (header forms, a comment before it, mismatched keyword, missing header); `SpecCoverageTest` ("a missing version header") |
| 3.1 | Whitespace | ✅ | `Syntax/LexerTest` (trivia); `Pipeline/GrammarTest` (statements sharing a line) |
| 3.2 | Comments | ✅ | `Pipeline/GrammarTest` (`# comment` vs `#TODO`); `Syntax/LexerTest` (`#` before a letter/`*`/`@` is a token) |
| 4.1 | Key names, atoms, variable names | ✅ | `Pipeline/EvaluatorTest` (atoms); `Pipeline/GrammarTest` (contextual keywords as keys); `Syntax/LexerTest` (identifier punctuation); conformance case `014-underscore-names` (a name beginning with `_`: key, variable, atom) |
| 4.2 | Quoted key segments | ✅ | `Pipeline/GrammarTest` (double/single-quoted, dotted position); `Pipeline/MergeTest` (interpolated keys outside loops, and their rules); `Pipeline/LoopTest` (the same rules for a key built from a binding) |
| 4.3 | Secret keys | ✅ | `Pipeline/GrammarTest` (all three prefix positions); `CooperTest`, `Value/CooperSecretTest` (wrapping, every redacting display surface); `Pipeline/ResolverTest` (secrecy travels through `%{...}`, `for ... from`, tags, indexing; partial redaction); `Pipeline/MergeTest` (later flag wins, delete forgets) |
| 5.1 | Imports | ✅ | `Pipeline/LoaderTest` (relative resolution, visibility, globs/braces, cycles, unregistered scheme, no match, `${NAME}` in a path with every rejection, scheme loader failure, a scheme cycle and a scheme importing itself, errors naming the imported file); `CacheTest` (import fingerprinting, env-selected import refresh) |
| 5.2 | Variable declarations | ✅ | `Pipeline/EvaluatorTest` (public/private markers); `Pipeline/LoaderTest` (resolved visibility in both directions, one private reading another, a private shadowing an imported public in its own file only, same-named privates independent, transitive) |
| 5.3 | Assignments (`=` optional) | ✅ | `Pipeline/GrammarTest` (the four surface forms; `foo "bar"`, which the reference's 0.4.0 rejected) |
| 5.4 | Key paths and blocks | ✅ | `Pipeline/GrammarTest`; `Pipeline/MergeTest` (deep merge at 3+ levels); `Pipeline/SurfaceFormPropertyTest` (random trees, every surface form identical); `Pipeline/MergeTest` (an empty block is an empty map, and leaves a map already there as it is) |
| 5.5 | Loops | ✅ | `Pipeline/LoopTest` (both worked examples, mismatched lengths, index-only, two indexes, index placed last, iterable validation, bindings in keys, `%{...}` paths, defaults, outer variables in keys, `~` inside the body, secret destination, filters/index/suffix on a bound value, a binding in a filter argument, `+`/`-` editing a `from` template's copy, built-key rules, a built iterable name refused, a private iterable shadowing) |
| 5.6 | Disabled statements | ✅ | `Pipeline/GrammarTest`; `Pipeline/EvaluatorTest` (never evaluated: no variable, no import, no literal error); `SpecCoverageTest` (`#~*`) |
| 5.7 | Merge control sigils + ordering | ✅ | `Pipeline/EvaluatorTest` (op level, sigils inside blocks keep meaning); `Pipeline/GrammarTest` (`-key` then a new line; `+info`); `SpecCoverageTest` (`~*`, `#~*`) |
| 6.1-6.2 | Nil, booleans | ✅ | `Pipeline/EvaluatorTest` |
| 6.3 | Numbers | ✅ | `Pipeline/EvaluatorTest` (every form, beyond 64 bits a `CooperInteger`); `Syntax/LiteralsTest` (random round trips in every base, 64-bit limits, every digit kept beyond them in every base) |
| 6.4 | Atoms | ✅ | `Pipeline/EvaluatorTest` (bare, `:`-prefixed, `:nil`/`:true`/`:false` as the values, `:inf` as an atom); `Value/ValueObjectsTest` (interning) |
| 6.5 | Strings | ⚠️ | `Pipeline/EvaluatorTest` (escapes, `\u`, single-quoted, triple-quoted dedent); `SpecCoverageTest` (triple-quoted does not interpolate); `Syntax/LiteralsTest`. **Gap:** backslash continuation, see below. |
| 6.6 | Dates and times | ✅ | `Pipeline/EvaluatorTest` (all four forms, offset normalized to UTC, fractional digits kept as written, impossible date); `Value/ValueObjectsTest` (`CooperDateTime`: precision, truncation past six digits, year 0000 and 10000, `-00:00` refused); `Pipeline/ResolverTest` (precision inside a string) |
| 6.7 | IP addresses | ✅ | `Pipeline/EvaluatorTest` (literals, invalid octet and prefix); `Value/CooperIPv4Test`, `Value/CooperIPv6Test` (validation, rendering as `:inet.ntoa/1`, CIDR math, random-input properties) |
| 6.8 | Durations | ✅ | `Pipeline/EvaluatorTest` (beyond 64 bits a `CooperInteger` count); `SpecCoverageTest` (unit table); `Syntax/LiteralsTest` (edges, the exact rounded product for random amounts and fractions, exact compound sums, every digit beyond 64 bits and beyond any float, `_` separators); `Pipeline/ResolverTest` (`!duration`, in a string); `Syntax/LexerTest`, `Pipeline/GrammarTest` (`1_000ms` is one duration) |
| 6.9 | Byte sizes | ✅ | `Pipeline/EvaluatorTest`; `SpecCoverageTest` (decimal and binary unit tables); `Syntax/LiteralsTest` (exact, every digit beyond 64 bits) |
| 6.10 | Lists | ✅ | `Pipeline/EvaluatorTest` (separators, mixed types, empty); `Pipeline/MergeTest` (maps inside lists and tuples: interpolated keys, loop bindings, secrets, `+`/`-` by value) |
| 6.11 | Tuples | ✅ | `Pipeline/EvaluatorTest`; `CooperTest` (a real `CooperTuple` end to end); `Value/ValueObjectsTest` |
| 7.1 | Variables (`@{}`) | ✅ | `Pipeline/EvaluatorTest` (unresolved shape); `Pipeline/ResolverTest` (suffixes, indexing past either end, value that is itself a reference, cycles, every value's string form) |
| 7.2 | Environment expansion (`${}`) | ✅ | `Pipeline/ResolverTest` (worked example, always a string, unset/empty, list and index, `:+1` a substitute and `:-1` a default, an interpolating `:?` message); `Pipeline/EvaluatorTest` (the sign after `:` decides); `SpecCoverageTest` (`${?NAME}`); `Pipeline/FiltersTest` (every filter, chaining, after the suffix, both quote styles, bare/interpolated parity, errors, a secret filtered and kept secret); `Pipeline/BuiltNamesTest` (built names and keys, every guardrail); `DotenvTest` (`${COOPER_ENV}`: a set one wins, empty/unset falls back to `APP_ENV` from every layer, else `dev`; the fallback's shared-name table, a real one never mapped; property); `CacheTest` (the fallback is what the env check sees); conformance case 168 |
| 7.3 | Config references (`%{}`) | ✅ | `Pipeline/ResolverTest` (worked example, final-tree timing, cycles, through a `Layered` value, a path followed through a reference, a chain of them, or a loop variable bound to a block element, a path into a secret block element whose found value stays secret, a missing key behind one taking its default, a reference to a scalar undefined below it, a path through its own key a cycle); `ConformanceTest` (cases 188-191) |
| 7.4 | Extensible resolution (`!{}`) | ✅ | `Pipeline/EvaluatorTest` (first colon, nested braces); `Pipeline/ResolverTest` (dispatch, unregistered, failing resolver) |
| 7.5 | Tagged values (`!Name()`) | ✅ | `Pipeline/ResolverTest` (built-ins, failures, consumer tag, unregistered); `Pipeline/FiltersTest` (normalizing tags); `Pipeline/ModuleTagTest` (`!module`: PascalCase acceptance and the class-string convention, every rejection incl. a mapped non-PascalCase name, the exact-case `modules` mapping through `loadString`/cached `loadFile`, no load-time class check); conformance 126, 163-167, 914, 920 |
| 8.1 | Blocks and maps | ✅ | `Pipeline/MergeTest` |
| 8.2 | Lists | ✅ | `Pipeline/MergeTest` (and `+`/`-` operands: `nil` as one element, strict removal, a later-declared reference, a list still a reference, a tuple refused) |
| 8.3 | Tuples | ✅ | `Pipeline/MergeTest` (plain replace; `~`/`+`/`-` errors) |
| 8.4 | Overriding the default | ✅ | `Pipeline/MergeTest` (worked example, position kept, remove by value -- a huge duration by its exact count, an offset date-time by instant and precision -- append wraps); `CooperTest` |
| 8.5 | Deferred: merge-by-key | N/A | Out of scope per the spec itself. |
| 9.1-9.3 | Extensibility | ✅ | `Pipeline/ResolverTest`; `Pipeline/LoaderTest`; `CooperTest` (tag, resolver, and scheme together) |
| 9.4 | Failure semantics | ✅ | Every unregistered tag/resolver/scheme test above names the offender |
| 10 | Minimal valid file | ✅ | `Pipeline/GrammarTest`; `CooperTest` |

Library features beyond the spec: `.env` layering (`DotenvTest`,
including a random-layer precedence property), caching (`CacheTest`,
including a random write-sequence property), interpolated text parity
with Elixir (`Pipeline/DisplayTest`, floats generated by Elixir itself),
and robustness (`Syntax/ParserFuzzTest`: random and mutated input only
ever fails with `CooperError`).

Every section is also exercised end to end by the shared conformance
corpus (`ConformanceTest`, `tests/conformance`), each case compared with
the Elixir reference's own result.

## Open design questions CASC.md doesn't answer

| # | Question | Resolution | Test |
|---|---|---|---|
| 1 | Do triple-quoted strings interpolate? | No (as the Elixir original) | `SpecCoverageTest` |
| 2 | Does `~key` replace every leaf, or the whole subtree? | Whole subtree, kept in place | `Pipeline/MergeTest` |
| 3 | Is public-variable visibility transitive across imports? | Yes | `Pipeline/LoaderTest` |
| 4 | `-key = [...]`: value or identity equality? | Strict value equality (`1` ≠ `1.0`) | `Pipeline/MergeTest` |
| 5 | Secret flag when a later plain write overwrites a secret path? | Later op's flag wins | `Pipeline/MergeTest` |
| 6 | Base unit for durations? | Nanoseconds | `SpecCoverageTest` |
| 7 | Meaning of an inner sigil inside a sigil'd block? | Inner non-merge sigil wins; plain inner ops take the outer one | `Pipeline/EvaluatorTest` |
| 8 | What does a value read as inside a string? | As CASC writes it: `nil`, `inf`, `500000000ns`, `2048B`, shortest float, every digit of a large integer (now stated in CASC.md §7) | `Pipeline/ResolverTest`, `Pipeline/DisplayTest` |
| 9 | Do loop-built keys follow the interpolated-key rules? | Yes (now stated in CASC.md §4.2) | `Pipeline/LoopTest` |
| 10 | Is a `+`/`-` operand that is not a list one element, `nil` included? | Yes, compared strictly (now stated in CASC.md §8.4) | `Pipeline/MergeTest` |

## Known gap

**Backslash-continued strings** (§6.5's fifth string form) are not
implemented, as in the Elixir original: CASC.md gives no worked result
for the form, so there is nothing concrete to validate an
implementation against without guessing its exact semantics.

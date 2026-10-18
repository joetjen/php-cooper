# Divergences from the Elixir reference

`php-cooper` is checked case by case against the Elixir implementation,
[`cooper`](https://github.com/joetjen/cooper): `tests/ConformanceTest.php`
loads every `cases/*.casc` the way the reference's oracle does
(`tests/Support/Conformance.php`) and compares the result with
`<case>.expected.json`, which the oracle (`node-cooper/scripts/oracle.exs`)
wrote by running the same file through `cooper` itself.

The corpus is shared with every Cooper implementation and vendored here
unchanged: the `*.casc` files, `imports/`, and the `*.expected.json` files
come from `node-cooper/test/conformance/cases` (its `*.node.json`
overrides are Node's own and are not copied). An error is compared by
stage only; a result structurally, with map pairs sorted by key and
nothing else normalized. A `dateTime` was once compared only as the same
instant, because this port's `DateTimeImmutable` dropped the fractional
precision the reference keeps (`…00.120Z`); `CooperDateTime` keeps it,
and it is compared exactly now.

The reference is the authority. A case may differ from it only where
CASC.md leaves the result to the host language; such a case would have
a `<case>.php.json` holding this port's result, which the test prefers
to the expected file, and be listed below. There is none: every case
matches the reference exactly.

## A module in the result

CASC.md §7.5 makes a module name one written form for every
implementation -- dot-separated PascalCase -- and has each translate it
into its own host's convention; this port's is a class-string
(`Acme.Payments` -> `"Acme\Payments"`), which nothing tells from an
ordinary string. The corpus therefore loads with the conformance
adapter's options (`ConformanceAdapter`), whose `!module` is the
built-in one with its result marked, and renders a module by the name
CASC wrote, as the oracle does (`{"module": "Foo.Bar"}`). Cases `163`
and `920` once carried a `*.php.json` override holding the class-string
instead; they compare against the reference's expected file now, like
every other case.

Every case that refuses a module name -- `126` (`crypto`), `164`
(`Foo.bar`), `165` (`Foo_Bar`), `166` (`Foo..Bar`), `167` (a number),
`914` (`./handlers/thing.js`) -- is a `resolve` error here as in the
reference.

## Integers

One difference is inherent to PHP and needs no override, because the
canonical rendering cannot see it:

- **Integers** are a native `int` when they fit in 64 bits, and a
  `CooperInteger` (`bcmath`-backed, every digit kept) beyond -- the
  reference's integers are arbitrary-precision throughout. A duration's
  or byte size's count follows the same rule (cases 154-157, 162); it
  was once held to 64 bits, and a total beyond that was a load error.

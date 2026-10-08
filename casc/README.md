# CASC specification

The files in this directory are the implementation-independent CASC
language documents, copied verbatim from the Elixir original's
[`guides/casc/`](https://github.com/joetjen/cooper/tree/main/guides/casc)
so every Cooper port reads against the same text:

- [CASC.md](CASC.md) -- the specification.
- [TUTORIAL.md](TUTORIAL.md) -- the language, introduced step by step.
- [CASC_EXAMPLES.md](CASC_EXAMPLES.md) -- worked examples.
- [CASC_CHEATSHEET.md](CASC_CHEATSHEET.md) -- syntax at a glance.

Result values in them are written as Elixir terms (`%{"key" => value}`,
`:info`, `{:duration, 500_000_000}`), and a few remarks describe the
Elixir implementation specifically (for instance how `!module` maps a
name to an Elixir module). For what the same values are in PHP, see
the value table in the [cheatsheet](../CHEATSHEET.md#result-values);
for where this port's behavior differs from the Elixir original, see
the [README](../README.md#where-this-port-differs-from-the-elixir-original).

Do not edit these files here -- change them in `cooper` and copy them
over, so the ports cannot drift apart.

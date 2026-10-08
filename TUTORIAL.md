# Tutorial

This tutorial builds up a small "orders service" config, feature by
feature, until it exercises every part of CASC that php-cooper
implements: loading, values, variables, the environment, secrets,
errors, imports and test-time injection, merging, loops, references,
`.env` files, caching, and guards. For the full language reference see
[CASC.md](casc/CASC.md); this tutorial introduces only as much of it as
the example needs.

Results are shown as PHP literals in comments. Every example loads with
`'dotenv' => false` implied, so no `.env` file on your machine changes
what you see.

## 1. The minimal file

Every CASC file starts with a version header:

```text
#@version = 1.0
```

That alone is a complete, valid file:

```php
use JOetjen\Cooper\Cooper;

Cooper::loadString("#@version = 1.0");
// => []
```

`Cooper::loadString()` parses CASC text directly; `Cooper::loadFile()`
reads a file first -- use it for real config files. Both return an
`array` and throw `CooperError` on failure (see [§6](#6-errors)).

## 2. Plain values and blocks

```text
#@version = 1.0

app_name = "orders"
replicas = 3
level = info

server {
  host = "0.0.0.0"
  port = 8080
}
```

```php
Cooper::loadString($source);
// => [
//   'app_name' => 'orders',
//   'replicas' => 3,
//   'level' => CooperAtom::of('info'),
//   'server' => ['host' => '0.0.0.0', 'port' => 8080],
// ]
```

`server { host = ... }` and `server.host = ...` are equivalent -- pick
whichever reads better. A bare identifier like `info` is an *atom*,
which comes back as an interned `CooperAtom`, so
`$config['level'] === CooperAtom::of('info')` works.

CASC has a few literal types beyond the usual scalars, each with its
own value class under `JOetjen\Cooper\Value`: durations (`500ms`,
`1h30m` -- `CooperDuration`, in nanoseconds), byte sizes (`512MiB` --
`CooperBytes`), dates and times, and IP addresses. Tuples, `(52.52,
13.405)`, are `CooperTuple`, never a list.

IP addresses are a real type, validated at load time, with CIDR math:

```text
#@version = 1.0

allowed_networks = [10.0.0.0/8, 192.168.1.0/24]
```

```php
use JOetjen\Cooper\Value\CooperIPv4;

[$office, $vpn] = Cooper::loadString($source)['allowed_networks'];
$office->contains(CooperIPv4::parse('10.1.2.3'));   // true
(string) $vpn->broadcast();                         // "192.168.1.255/24"
```

## 3. Variables and interpolation

`@name = value` declares a variable. It is not part of the result, only
referable via `@{name}`:

```text
#@version = 1.0

@region = "eu-west"

endpoint = "https://api.@{region}.example.com"
```

```php
Cooper::loadString($source);
// => ['endpoint' => 'https://api.eu-west.example.com']
```

`@{name}` also works as a whole value (`region = @{other}`), keeping the
variable's own type. `@*name` declares a *private* variable, visible
only inside the file that declares it (see [§7](#7-imports-and-test-time-injection)).

## 4. Environment references

`${NAME}` reads the environment. It always resolves to a string --
CASC never guesses that text "looks like" a number:

```text
#@version = 1.0

region = ${REGION}
max_retries = !int(${MAX_RETRIES:3})
allowed_hosts = ${ALLOWED_HOSTS[]:["localhost"]}
```

```php
Cooper::loadString($source, ['env' => ['REGION' => 'eu-west']]);
// => ['region' => 'eu-west', 'max_retries' => 3, 'allowed_hosts' => ['localhost']]
```

- `${NAME:default}` falls back to `default`, parsed as an ordinary CASC
  value -- a bare `3` really is the integer `3`.
- `!int(...)` is a **tagged value**: it converts its argument. `!int`,
  `!float`, `!bool`, `!duration`, `!bytes`, `!trim`, `!downcase`,
  `!upcase`, and `!module` are built in; register more with the `tags`
  option.
- `${NAME[]}` splits a value on `,`/`;` into a list.
- `| filter` clauses normalize a value: `${SCHEME | trim_suffix: "://" | downcase}`.

## 5. Secrets

Prefixing a key segment with `*` marks it secret:

```text
#@version = 1.0

database {
  *password = ${DB_PASSWORD}
  host = "db.internal"
}
url = "postgres://%{database.host}?password=%{database.password}"
```

```php
$config = Cooper::loadString($source, ['env' => ['DB_PASSWORD' => 'hunter2']]);

(string) $config['database']['password'];     // "[~~REDACTED~~]"
$config['database']['password']->reveal();    // "hunter2"
(string) $config['url'];                      // "postgres://db.internal?password=[~~REDACTED~~]"
$config['url']->reveal();                     // "postgres://db.internal?password=hunter2"
json_encode($config['database']);             // {"password":"[~~REDACTED~~]","host":"db.internal"}
```

A `CooperSecret` redacts itself everywhere it can -- string conversion,
`json_encode()`, `var_dump()`, `print_r()` -- so logging a config by
accident does not leak it. `reveal()` is the only way to the real
value. The wrapping travels with the value: the `%{...}` reference above
copied the secret into `url`, where only the secret *portion* is
redacted.

## 6. Errors

Every failure throws `JOetjen\Cooper\CooperError`. Its `$stage` says
roughly where it went wrong (`lexer`, `parser`, `action`, `loop`,
`import`, `merge`, `resolve`, `dotenv`), and for syntax errors
`$sourceLine`/`$sourceColumn`/`$sourceFile` point at the CASC source:

```php
use JOetjen\Cooper\CooperError;

try {
    Cooper::loadString("#@version = 1.0\nvalue = !{missing:x}");
} catch (CooperError $e) {
    $e->stage;          // "resolve"
    $e->getMessage();   // 'unregistered resolver "missing"'
}
```

Unregistered resolvers, tags, and import schemes, `%{...}` cycles, and
mismatched loop lists are all load-time errors naming the offender --
php-cooper never silently drops a reference or guesses at intent. A
malformed *options* array throws `\InvalidArgumentException` instead.

## 7. Imports and test-time injection

`import "path"` splices another file's statements in at that point.
Later statements -- including the importer's own -- override earlier
ones, and the imported file's *public* `@name` variables become visible
to the importer (and the importer's to it):

```text
#@version = 1.0

import "defaults.casc"

server.port = 9090
```

A bare path resolves relative to the importing file; `loadString()`
needs a `root` option for that. Globs (`import "conf.d/*.casc"`) and
braces (`import "{base,local}.casc"`) expand against the filesystem, in
lexicographic order, and a path may select a file by environment:
`import "env/${COOPER_ENV}.casc"` -- `COOPER_ENV` is always set, from
`APP_ENV` when it is not set itself.

A `scheme://` import goes to a loader you register -- which is what
makes config that imports from somewhere else testable without the
filesystem or the network:

```php
$source = <<<'CASC'
    #@version = 1.0
    import "mem://extra"
    app_name = "orders"
    CASC;

Cooper::loadString($source, [
    'importSchemes' => ['mem' => fn (string $rest): string => "#@version = 1.0\nregion = \"eu-west\"\n"],
]);
// => ['region' => 'eu-west', 'app_name' => 'orders']
```

`resolvers` and `tags` work the same way: plain callables that return a
value or throw. `env` is different -- it is an *override*, not an
isolation layer: a name it defines always wins, but any other `${...}`
still falls through to `.env` files and the real environment (see
[§11](#11-env-files)).

## 8. Merge control and loops

Later statements override earlier ones and maps deep-merge. A leading
sigil changes that:

```text
#@version = 1.0

server { host = "0.0.0.0", port = 8080, tls { min_version = "1.2" } }
~server { port = 9090 }

tags = ["a", "b", "c"]
+tags = ["d"]
-tags = ["b"]
```

```php
Cooper::loadString($source);
// => ['server' => ['port' => 9090], 'tags' => ['a', 'c', 'd']]
```

`~key { ... }` replaces the *whole* subtree; `+`/`-` append and remove
list elements by value; `-key.path` alone deletes a path. Tuples are
never merged, only replaced -- `+`/`-`/`~` against one is an error.

`for` generates many entries from one template:

```text
#@version = 1.0

defaults.replica {
  cpu = 1
  memory_mb = 512
}

@instances = ["a", "b", "c"]
for @instance in @{instances} from defaults.replica as replicas."@{instance}" {
  cpu = 2
}
```

```php
Cooper::loadString($source)['replicas'];
// => [
//   'a' => ['cpu' => 2, 'memory_mb' => 512],
//   'b' => ['cpu' => 2, 'memory_mb' => 512],
//   'c' => ['cpu' => 2, 'memory_mb' => 512],
// ]
```

Each `replicas.<instance>` starts as a copy of `defaults.replica` --
resolved against the *final* tree -- with the loop body on top. Several
`@name in ...` bindings iterate in parallel (zipped), and a bare
`@idx` binding counts iterations; see [CASC.md §5.5](casc/CASC.md).

## 9. Config references

`%{path}` refers to another value in the same, fully merged tree --
resolved after every import and merge, wherever it was written:

```text
#@version = 1.0

server.host = "api.internal"
server.port = 8080
health_check.url = "http://%{server.host}:%{server.port}/health"
admin_email = %{contact.admin:"ops@example.com"}
```

```php
Cooper::loadString($source);
// => [
//   'server' => ['host' => 'api.internal', 'port' => 8080],
//   'health_check' => ['url' => 'http://api.internal:8080/health'],
//   'admin_email' => 'ops@example.com',
// ]
```

A reference cycle is a load-time error naming the whole cycle.

## 10. Putting it together

```text
#@version = 1.0

@region = ${REGION:"eu-west"}

server {
  host = "0.0.0.0"
  port = !int(${PORT:8080})
}

database {
  *password = !{vault:secret/db/password}
  host = "db.@{region}.internal"
  timeout = 500ms
}

endpoints.health = "http://%{server.host}:%{server.port}/health"
```

```php
$config = Cooper::loadFile('config.casc', [
    'resolvers' => ['vault' => fn (string $path): string => $vault->read($path)],
]);
```

## 11. .env files

`${...}` reads `.env` files from the project root automatically (the
`dotenvDir` option points elsewhere). Later layers win:

1. `.env`
2. `.env.<env>` -- `<env>` is the `dotenvEnv` option if given, else
   `COOPER_ENV` as the real environment, the `env` option and `.env` set
   it (unset, `APP_ENV` by its shared name, else `dev`): `.env.dev`,
   `.env.staging`, `.env.test` or `.env.prod`, the names every Cooper
   reads. `APP_ENV=production` reads `.env.prod`, not `.env.production`;
   Laravel's `.env.testing` is `.env.test` here
3. `.env.local`
4. the real environment (`getenv()`)
5. the `env` option

**The real environment outranks the files** -- a deployment's own
variables are never silently shadowed by a file lying in the working
directory. Pass `'dotenvOverride' => true` to put the files on top, for
example to shadow something exported in your shell.

`'dotenv' => false` turns the file layers off; `'dotenvFiles'` replaces
the file list outright. A missing file is never an error, a malformed
one is (stage `dotenv`).

## 12. Caching

`Cooper::loadFile()` caches the parsed, merged tree by default -- in
process memory, so it pays off in a long-running worker (RoadRunner,
Swoole, FrankenPHP worker mode, queue consumers) or when one request
loads the same file several times. An entry is reused while the file
and everything it imports keep their modification times. `${...}`
values are never cached -- they are re-read on every load:

```text
#@version = 1.0
port = ${PORT:"8080"}
```

```php
Cooper::loadFile('config.casc')['port'];   // "8080"
putenv('PORT=9090');
Cooper::loadFile('config.casc')['port'];   // "9090" -- no file change needed
```

`'cache' => false` bypasses the cache for one call;
`Cache::invalidate($path)` and `Cache::clear()` drop entries.

Some environment reads shape the tree itself rather than a value in it:
a `${?NAME}` guard (§13) or `${NAME}` in an import path. Their values
are remembered with the cache entry and checked on every load; if one
changed, the entry is dropped and the file re-parsed.

To react to changes, listen for cache events:

```php
use JOetjen\Cooper\Cache;
use JOetjen\Cooper\CacheEvent;

Cache::listen(function (CacheEvent $event): void {
    // $event->type is CacheEvent::FILE_CHANGED (files in $event->changed)
    // or CacheEvent::ENV_CHANGED (variable names in $event->changed)
    error_log("config {$event->path} changed: " . implode(', ', $event->changed));
});
```

Listeners run synchronously, during the load that notices the change.

## 13. Guards

`${?NAME}` guards the *one* statement after it -- write them on the
same line:

```text
#@version = 1.0

${?FEATURE_FLAG} beta_enabled = true
log_level = info
```

```php
Cooper::loadString($source, ['env' => ['FEATURE_FLAG' => '']]);
// => ['log_level' => CooperAtom::of('info')]
Cooper::loadString($source, ['env' => ['FEATURE_FLAG' => '1']]);
// => ['beta_enabled' => true, 'log_level' => CooperAtom::of('info')]
```

Unset and empty count the same. A skipped statement is never evaluated
at all, so nothing inside it can fail just for being skipped. A guard
can wrap any statement -- a block, a loop, an import:

```text
${?ENABLE_METRICS} metrics { port = 9090, path = "/metrics" }
```

From here, [CASC.md](casc/CASC.md) is the full language reference and
the [cheatsheet](CHEATSHEET.md) a fast lookup for the PHP API.

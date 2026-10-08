# Examples

The [tutorial](TUTORIAL.md) builds one config up from nothing. This
page walks through several more, each focused on something you are
likely to use php-cooper for. For examples about CASC *syntax* rather
than the PHP around it, see [CASC_EXAMPLES.md](casc/CASC_EXAMPLES.md).

## Application config with a real secrets manager

Config loaded once at boot, with secrets fetched from wherever your
organization keeps them -- never landing in source control or a log
line.

```text
#@version = 1.0

database {
  *password = !{vault:secret/data/orders-db#password}
  host = "db.internal"
  pool_size = !int(${DB_POOL_SIZE:10})
}
```

```php
use JOetjen\Cooper\Cooper;
use JOetjen\Cooper\CooperError;

final class AppConfig
{
    /** @return array<array-key, mixed> */
    public static function load(VaultClient $vault): array
    {
        try {
            return Cooper::loadFile(__DIR__ . '/../config/app.casc', [
                'resolvers' => [
                    'vault' => static fn (string $path): string => $vault->read($path)
                        ?? throw new \RuntimeException("no secret at {$path}"),
                ],
            ]);
        } catch (CooperError $e) {
            throw new \RuntimeException("invalid config: {$e->getMessage()}", previous: $e);
        }
    }
}
```

`$config['database']['password']` comes back as a `CooperSecret` --
safe to keep in a container or pass around; `->reveal()` is the one
deliberate step to use the real value, e.g. when building a DSN.

## Layering base config with per-environment overlays

```text
# config/base.casc
#@version = 1.0

server { host = "0.0.0.0", port = 8080 }
log_level = info
feature_flags.new_checkout = false
```

```text
# config/prod.casc
#@version = 1.0

import "base.casc"

~server { port = 443 }
log_level = warning
feature_flags.new_checkout = true
```

```php
Cooper::loadFile('config/prod.casc');
// => [
//   'server' => ['port' => 443],
//   'log_level' => CooperAtom::of('warning'),
//   'feature_flags' => ['new_checkout' => true],
// ]
```

`import` splices `base.casc` in first; every statement after it --
including `~server { ... }`, which replaces the block rather than
merging into it -- overrides the base. One entry file can also pick its
overlay by environment:

```text
#@version = 1.0
import "base.casc"
import "env/${COOPER_ENV}.casc"
```

For per-environment *values* rather than structure -- a password that
differs per environment -- a `.env.<env>` file does the same job without
a second `.casc` file; see the tutorial's [§11](TUTORIAL.md#11-env-files).

## Reloading in a long-running worker

A RoadRunner/Swoole/FrankenPHP worker loads config once and keeps it.
Calling `loadFile()` per request is cheap -- a cache hit costs one
`stat` per contributing file plus `${...}` resolution -- and picks up a
changed file on the next request:

```php
use JOetjen\Cooper\Cache;
use JOetjen\Cooper\CacheEvent;

Cache::listen(static function (CacheEvent $event) use ($logger): void {
    $logger->info('config reloaded', ['path' => $event->path, 'changed' => $event->changed]);
});

while ($request = $worker->waitRequest()) {
    $config = Cooper::loadFile('/etc/orders/app.casc');
    $worker->respond(handle($request, $config));
}
```

## Testing config-loading code without disk or network

Every option that would otherwise reach the filesystem, the real
environment, or an external service is a plain callable or array, so
config loading is unit-testable like any other function:

```php
use JOetjen\Cooper\Cooper;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testTheProdOverlayRaisesThePool(): void
    {
        $source = <<<'CASC'
            #@version = 1.0

            import "mem://base"

            pool_size = !int(${POOL_SIZE:20})
            CASC;

        $config = Cooper::loadString($source, [
            'importSchemes' => ['mem' => static fn (string $name): string => match ($name) {
                'base' => "#@version = 1.0\npool_size = 5\ndebug_routes = true\n",
            }],
            'env' => ['POOL_SIZE' => '50'],
            'dotenv' => false,
        ]);

        self::assertSame(['pool_size' => 50, 'debug_routes' => true], $config);
    }
}
```

No file named `base.casc` exists anywhere -- `mem://base` resolves in
memory. `POOL_SIZE` is guaranteed to be `"50"` because `env` always wins
for a name it defines; `'dotenv' => false` keeps any `.env` file out of
the test.

## IP allowlisting with real CIDR math

```text
#@version = 1.0

allowed_networks = [10.0.0.0/8, 192.168.1.0/24, 203.0.113.7/32]
```

```php
use JOetjen\Cooper\Value\CooperIPv4;

function allowed(array $config, CooperIPv4 $candidate): bool
{
    foreach ($config['allowed_networks'] as $network) {
        if ($network->contains($candidate)) {
            return true;
        }
    }

    return false;
}

allowed($config, CooperIPv4::parse('10.1.2.3'));      // true
allowed($config, CooperIPv4::parse('203.0.113.8'));   // false
```

Every address is validated when the file loads -- a malformed address
or an out-of-range prefix is a load error, never a request-time crash
-- and `contains()` does real network-membership math.

## Generating per-shard config from one template

```text
#@version = 1.0

defaults.shard {
  replicas = 1
  memory_limit = 512MiB
}

@shard_names = ["orders", "inventory", "billing"]
@shard_replicas = [3, 2, 1]

for @name in @{shard_names}, @replicas in @{shard_replicas}
  from defaults.shard as shards."@{name}" {
  replicas = @{replicas}
}
```

```php
$shards = Cooper::loadString($source)['shards'];

$shards['inventory']['replicas'];               // 2
$shards['inventory']['memory_limit']->bytes;    // 536870912
```

Each shard starts as a full copy of `defaults.shard`, with `replicas`
taken from the parallel `@shard_replicas` list -- a fourth shard is one
more entry in each list, not a fourth copied block.

## One environment variable per tenant

CASC cannot enumerate the environment, but it can build a variable's
*name* (CASC.md §7.2):

```text
#@version = 1.0

@tenants = ["acme", "globex"]

for @tenant in @{tenants} as tokens {
  "@{tenant}" = ${"TOKEN_@{tenant}"}
}
```

```php
Cooper::loadString($source, ['env' => ['TOKEN_acme' => 't-1', 'TOKEN_globex' => 't-2']]);
// => ['tokens' => ['acme' => 't-1', 'globex' => 't-2']]
```

## A consumer-defined tag

The built-in tags cover coercion; `tags` extends the same `!Name(arg)`
syntax with your own vocabulary:

```text
#@version = 1.0

request_id = !uuid("550e8400-e29b-41d4-a716-446655440000")
```

```php
$tags = [
    'uuid' => static function (mixed $arg): string {
        if (!is_string($arg) || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $arg) !== 1) {
            throw new \InvalidArgumentException('not a UUID');
        }

        return strtolower($arg);
    },
];

Cooper::loadString($source, ['tags' => $tags]);
// => ['request_id' => '550e8400-e29b-41d4-a716-446655440000']
```

Without `tags`, a file using `!uuid(...)` fails to load with an error
naming `uuid` -- never silently passed through as a string. A tag that
throws fails the load with `!uuid(...) failed: not a UUID`.

## Turning a load failure into an actionable message

A small CLI check for CI, printing the file, line, and stage instead of
a stack trace:

```php
#!/usr/bin/env php
<?php

use JOetjen\Cooper\Cooper;
use JOetjen\Cooper\CooperError;

require __DIR__ . '/../vendor/autoload.php';

$path = $argv[1] ?? 'config/app.casc';

try {
    Cooper::loadFile($path, ['cache' => false]);
    echo "{$path}: OK\n";
} catch (CooperError $e) {
    $where = ($e->sourceFile ?? $path) . ($e->sourceLine !== null ? ":{$e->sourceLine}" : '');
    fwrite(STDERR, "{$where} [{$e->stage}]: {$e->reason}\n");
    exit(1);
}
```

`$sourceFile` names the imported file when the error is inside one, so
the message points at the file that actually needs fixing.

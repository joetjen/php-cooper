# Quickstart

```sh
composer require joetjen/cooper
```

Write a config file:

```text
# config/app.casc
#@version = 1.0

server {
  host = "0.0.0.0"
  port = !int(${PORT:8080})
}

*database.password = ${DB_PASSWORD}
```

Load it:

```php
use JOetjen\Cooper\Cooper;
use JOetjen\Cooper\CooperError;

try {
    $config = Cooper::loadFile(__DIR__ . '/config/app.casc');
} catch (CooperError $e) {
    fwrite(STDERR, "invalid config [{$e->stage}]: {$e->getMessage()}\n");
    exit(1);
}

$config['server']['port'];                     // 8080, or $PORT as an int
$config['database']['password']->reveal();     // the real $DB_PASSWORD
```

`${PORT}` reads the environment -- `.env`, `.env.<COOPER_ENV>` (`.env.dev`, `.env.prod`, ...), and
`.env.local` from the project root, with the real environment on top. `*`-prefixed keys come back
wrapped in `CooperSecret`, which prints as `[~~REDACTED~~]` until you
call `reveal()`.

Next: the [tutorial](TUTORIAL.md) walks through the rest of the
language; the [cheatsheet](CHEATSHEET.md) lists every option.

<?php

declare(strict_types=1);

namespace JOetjen\Cooper;

use Composer\InstalledVersions;
use JOetjen\Cooper\Pipeline\Options;
use JOetjen\Cooper\Pipeline\Paths;

/**
 * Builds the environment `${...}` references (CASC.md §7.2) read --
 * the Elixir original's `Cooper.Dotenv`. `Cooper` calls it on every
 * load, cached or not, so `${...}` values are always current.
 *
 * ## Layering
 *
 * Lowest precedence first, later winning:
 *
 *  1. `.env`
 *  2. `.env.<env>` -- `<env>` is the `dotenvEnv` option when given
 *     (`null` meaning "no per-environment file"); otherwise `COOPER_ENV`
 *     (see below) as the real environment, `env` and the base `.env`
 *     set it -- so `.env.dev`, `.env.staging`, `.env.test` and
 *     `.env.prod` in every Cooper, matching `env/${COOPER_ENV}.casc`.
 *     Naming it by `APP_ENV`, as this once did, read Laravel's
 *     `.env.production` or `.env.testing` beside documents that read
 *     `prod` or `test`.
 *  3. `.env.local`
 *  4. the real process environment (`getenv()`)
 *  5. the `env` option -- always the final override.
 *
 * **The real environment outranks the files.** A deployment sets
 * variables in the environment it controls; a file in the working
 * directory must not silently beat them (Ruby's and Node's dotenv, and
 * phpdotenv's own immutable mode, behave the same). Pass
 * `dotenvOverride: true` to put the files on top instead.
 *
 * `dotenvFiles` replaces the default file list (layers 1-3) outright.
 * A missing file is silently skipped. Paths are relative to `dotenvDir`,
 * which defaults to the **project root** -- deliberately not `root` (the
 * CASC file's directory): `.env` files live at the project root wherever
 * the CASC file sits and wherever the process was started. The project
 * root is the Composer root package's directory, else the working
 * directory (see `projectRoot()`). An absolute `dotenvFiles` entry is
 * used as it is.
 *
 * **`env` is an override layer, not a replacement**: a name it does not
 * set still falls through to the files and the real environment.
 *
 * ## `COOPER_ENV`
 *
 * The one name a document reads the current environment by, in every
 * Cooper implementation (CASC.md §7.2): `import "env/${COOPER_ENV}.casc"`.
 * Every layer above is consulted first, so a real `COOPER_ENV` -- in the
 * environment, a `.env` file, or `env` -- always wins. Unset or empty, it
 * falls back to this host's own name for the same thing: `APP_ENV` from
 * those same layers, else `"dev"`. Every Cooper names its environments
 * `dev`, `staging`, `test` and `prod`, so in that fallback only, the
 * host's own spellings are mapped onto them through the one table CASC.md
 * §7.2 gives every implementation -- `development` and `local` -> `dev`,
 * `testing` -> `test`, `production` -> `prod`, exact and case-sensitive --
 * and Laravel's `local`/`testing`/`production` read the same
 * `env/dev.casc` as every other host. Any other `APP_ENV` (`staging`,
 * `qa`, ...) is passed through as is, and a real `COOPER_ENV` is never
 * mapped: whoever set it chose the name. `APP_ENV` itself is left alone.
 * Read before `.env.<env>`, the same value names that file (see
 * "Layering").
 *
 * Because it is part of the map every load resolves against, the
 * cache's environment check (`Cache`) sees the fallback too: a tree an
 * `import "${COOPER_ENV}.casc"` shaped goes stale when `APP_ENV` changes.
 *
 * File loading (layers 1-3) is on by default; `dotenv: false` turns it
 * off. The files are parsed by `vlucas/phpdotenv`, which Cooper requires:
 * as an optional package, the default silently read nothing in any
 * application that had not added it itself.
 *
 * ## Exporting to the process environment
 *
 * Neither `env()` nor a load ever changes the process environment: the
 * layering only builds the map `${...}` resolves against. `export()`
 * exists for one caller -- a framework integration that replaces the
 * framework's own `.env` loader, after which code reading the process
 * environment directly (`$_ENV`, `$_SERVER`, `getenv()`, Laravel's
 * `env()`, Symfony's `APP_ENV`/`APP_DEBUG`) must still find what the
 * files say. It is host-specific behaviour of this library, not CASC's.
 */
final class Dotenv
{
    private const BASE_FILE = '.env';
    private const LOCAL_FILE = '.env.local';

    /**
     * Host environment names, and the shared ones (`dev`, `staging`,
     * `test`, `prod`) they map onto -- the table CASC.md §7.2 gives every
     * Cooper, so it must stay identical to theirs. Only the `APP_ENV`
     * fallback consults it.
     */
    private const SHARED_ENV_NAMES = [
        'development' => 'dev',
        'local' => 'dev',
        'testing' => 'test',
        'production' => 'prod',
    ];

    private function __construct()
    {
    }

    /**
     * The environment for `$options` (the same options array `Cooper`
     * takes; only `env`, `dotenv`, `dotenvEnv`, `dotenvFiles`,
     * `dotenvDir`, and `dotenvOverride` matter here).
     *
     * @param array<string, mixed> $options
     * @return array<string, string> name => value
     * @throws CooperError (stage `dotenv`) on a malformed `.env` file
     * @throws \InvalidArgumentException on malformed options
     */
    public static function env(array $options = []): array
    {
        return self::fromOptions(Options::from($options));
    }

    /**
     * @internal `env()` for already validated options
     * @return array<string, string>
     */
    public static function fromOptions(Options $options): array
    {
        return self::withCooperEnv(self::layered($options)[0]);
    }

    /**
     * Writes what the `.env` files provided into the process environment
     * -- `$_ENV`, `$_SERVER` and `putenv()` -- for code that reads it
     * directly instead of through `${...}`. Never called by a load: only
     * a caller that asks for it, typically a framework integration
     * standing in for the framework's own `.env` loader, at bootstrap.
     *
     * What is exported: every name the files define, and `COOPER_ENV`
     * (always, so code outside CASC sees the environment name the
     * configuration is loaded for, fallback included). Each gets the
     * value `${NAME}` resolves to under the same `$options` -- `env`
     * included -- so the process environment and a load never disagree.
     * A name only the `env` option sets is not exported: it is the
     * caller's override for this load, not the files'.
     *
     * **The real environment is never overwritten**, the precedence the
     * layering uses: a name `getenv()`, `$_ENV` or `$_SERVER` already
     * holds is left alone. The superglobals count because a web server
     * can set a variable there without `getenv()` seeing it (Symfony's
     * own loader checks the same places); a `$_SERVER` entry named
     * `HTTP_*` does not, since it is a request header a client chose.
     * `dotenvOverride: true` puts the files on top here too, and every
     * name is written.
     *
     * Idempotent: what one call wrote is the real environment to the
     * next, which writes nothing (or, under `dotenvOverride`, the same
     * values again). From then on, every load in the process sees those
     * values as the real environment -- a `.env` file edited later is
     * outranked by them, as by any other real variable.
     *
     * @param array<string, mixed> $options the options array `Cooper` takes (see `env()`)
     * @return array<string, string> name => value, every variable this call wrote
     * @throws CooperError (stage `dotenv`) on a malformed `.env` file
     * @throws \InvalidArgumentException on malformed options
     */
    public static function export(array $options = []): array
    {
        $opts = Options::from($options);
        [$layered, $fileNames] = self::layered($opts);
        $env = self::withCooperEnv($layered);

        $exported = [];
        foreach ([...$fileNames, 'COOPER_ENV'] as $name) {
            if (array_key_exists($name, $exported) || !isset($env[$name])) {
                continue;
            }
            if (!$opts->dotenvOverride && self::inProcessEnvironment($name)) {
                continue;
            }
            $value = $env[$name];
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
            $exported[$name] = $value;
        }

        return $exported;
    }

    /**
     * Whether the real process environment already holds `$name`: any
     * of `getenv()`, `$_ENV`, or `$_SERVER` -- except a `$_SERVER`
     * `HTTP_*` entry, which is a request header (see `export()`).
     */
    private static function inProcessEnvironment(string $name): bool
    {
        return getenv($name) !== false
            || array_key_exists($name, $_ENV)
            || (array_key_exists($name, $_SERVER) && !str_starts_with($name, 'HTTP_'));
    }

    /**
     * Where `.env` files are read from unless `dotenvDir` says otherwise:
     * the Composer root package's directory -- the application, when
     * Cooper is one of its dependencies -- else the working directory.
     * Reading them from the working directory only, as this once did,
     * found none when an application was started from anywhere but its
     * own root (a subdirectory, a worker, a cron job).
     *
     * @return string an absolute directory
     */
    public static function projectRoot(): string
    {
        if (class_exists(InstalledVersions::class)) {
            $installPath = InstalledVersions::getRootPackage()['install_path'];
            $real = realpath($installPath);
            if ($real !== false && is_dir($real)) {
                return $real;
            }
        }

        return (string) getcwd();
    }

    /**
     * `$env` with `COOPER_ENV` guaranteed: kept when present (set and
     * non-empty), else `APP_ENV` when present, mapped onto the shared
     * names, else `"dev"`. See "`COOPER_ENV`" above.
     *
     * @internal public for its tests, as the Elixir original's `with_cooper_env/1` is
     * @param array<string, string> $env a finished layering
     * @return array<string, string>
     */
    public static function withCooperEnv(array $env): array
    {
        if (self::present($env['COOPER_ENV'] ?? null)) {
            return $env;
        }
        $appEnv = $env['APP_ENV'] ?? '';
        $env['COOPER_ENV'] = $appEnv === '' ? 'dev' : (self::SHARED_ENV_NAMES[$appEnv] ?? $appEnv);

        return $env;
    }

    private static function present(?string $value): bool
    {
        return $value !== null && $value !== '';
    }

    /**
     * Layers 1-5, before `COOPER_ENV` is filled in, and which names the
     * files (layers 1-3) defined -- what `export()` writes.
     *
     * @return array{0: array<string, string>, 1: list<string>}
     */
    private static function layered(Options $options): array
    {
        $system = getenv();
        if ($options->dotenv === false) {
            return [array_replace($system, $options->env), []];
        }

        $files = [];
        foreach (self::files($options, $system) as $file) {
            $files = array_replace($files, self::parseFile($file));
        }

        return [self::onTop($options, $system, $files), array_map('strval', array_keys($files))];
    }

    /**
     * The real environment, the files and `env` stacked as `$options`
     * says: `env` always last, the files above the real environment only
     * under `dotenvOverride`.
     *
     * @param array<string, string> $system
     * @param array<string, string> $files
     * @return array<string, string>
     */
    private static function onTop(Options $options, array $system, array $files): array
    {
        $layered = $options->dotenvOverride ? array_replace($system, $files) : array_replace($files, $system);

        return array_replace($layered, $options->env);
    }

    /**
     * The files to layer, lowest precedence first, as absolute paths.
     *
     * @param array<string, string> $system
     * @return list<string>
     */
    private static function files(Options $options, array $system): array
    {
        // A relative `dotenvDir` is against the working directory, as
        // every other relative path option is.
        $dir = Paths::expand($options->dotenvDir ?? self::projectRoot());
        if ($options->dotenvFiles !== null) {
            $files = $options->dotenvFiles;
        } else {
            $env = $options->dotenvEnvGiven ? $options->dotenvEnv : self::nameEnv($options, $system, $dir);
            $files = $env === null || $env === ''
                ? [self::BASE_FILE, self::LOCAL_FILE]
                : [self::BASE_FILE, ".env.{$env}", self::LOCAL_FILE];
        }

        return array_map(static fn (string $file): string => Paths::expand($file, $dir), $files);
    }

    /**
     * `COOPER_ENV` as it stands before `.env.<env>` is read -- from the
     * real environment, `env` and the base `.env` file, in the order the
     * layering puts them -- which names the file to read next. The files
     * after it cannot name it: they are not read yet.
     *
     * @param array<string, string> $system
     * @param string $dir where `.env` is read from
     */
    private static function nameEnv(Options $options, array $system, string $dir): string
    {
        $base = self::parseFile(Paths::expand(self::BASE_FILE, $dir));

        return self::withCooperEnv(self::onTop($options, $system, $base))['COOPER_ENV'];
    }

    /**
     * @return array<string, string>
     */
    private static function parseFile(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $content = @file_get_contents($file);
        if ($content === false) {
            throw new CooperError("dotenv loading failed: could not read {$file}", CooperError::STAGE_DOTENV);
        }

        try {
            $parsed = \Dotenv\Dotenv::parse($content);
        } catch (\Throwable $e) {
            throw new CooperError("dotenv loading failed: {$file}: " . $e->getMessage(), CooperError::STAGE_DOTENV, null, null, $file, $e);
        }

        return array_filter($parsed, 'is_string');
    }
}

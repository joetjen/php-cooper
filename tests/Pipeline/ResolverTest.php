<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Tests\Pipeline;

use JOetjen\Cooper\CooperError;
use JOetjen\Cooper\Pipeline\Grammar;
use JOetjen\Cooper\Pipeline\Resolver;
use JOetjen\Cooper\Tests\Support\CooperTestCase;
use JOetjen\Cooper\Value\CooperAtom;
use JOetjen\Cooper\Value\CooperBytes;
use JOetjen\Cooper\Value\CooperDuration;
use JOetjen\Cooper\Value\CooperInteger;
use JOetjen\Cooper\Value\CooperSecret;

/**
 * CASC.md §7's reference resolution -- ported from the Elixir suite's
 * `resolver_test.exs`.
 */
final class ResolverTest extends CooperTestCase
{
    // ---- @{...} ---------------------------------------------------------------

    public function testAVariableResolves(): void
    {
        self::assertSame(['greeting' => 'hello world'], self::resolve("@name = \"world\"\ngreeting = \"hello @{name}\""));
        self::assertSame(['port' => 8080], self::resolve("@port = 8080\nport = @{port}"));
    }

    public function testNonStringValuesStringifyInsideAString(): void
    {
        self::assertSame(
            ['v' => 'timeout=500000000ns size=536870912B ip=127.0.0.1/32 f=1.0 n=nil i=inf a=info'],
            self::resolve("@t = 500ms\n@b = 512MiB\n@ip = 127.0.0.1/32\n@f = 1.0\n@n = nil\n@i = inf\n@a = info\nv = \"timeout=@{t} size=@{b} ip=@{ip} f=@{f} n=@{n} i=@{i} a=@{a}\""),
        );
    }

    public function testDateValuesStringifyAsTheElixirOriginalPrints(): void
    {
        self::assertSame(
            ['v' => '1979-05-27 07:32:00 07:32:00.5 1979-05-27 05:32:00Z'],
            self::resolve("@l = 1979-05-27T07:32:00\n@t = 07:32:00.5\n@o = 1979-05-27T07:32:00+02:00\nv = \"@{l} @{t} @{o}\""),
        );
    }

    public function testAnOffsetDateTimeStringifiesWithThePrecisionItWasWrittenWith(): void
    {
        self::assertSame(
            ['v' => '1979-05-27 09:32:00.120Z 1979-05-27 07:32:00.0Z'],
            self::resolve("@a = 1979-05-27T07:32:00.120-02:00\n@b = 1979-05-27T07:32:00.0Z\nv = \"@{a} @{b}\""),
        );
    }

    public function testADurationOrByteSizeBeyond64BitsStringifiesInItsBaseUnit(): void
    {
        self::assertSame(
            ['v' => '25920000000000000000000ns 22517998136852480000000B'],
            self::resolve("@d = 300000000d\n@b = 20000000PiB\nv = \"@{d} @{b}\""),
        );
    }

    public function testAListOrTupleCannotBeInterpolated(): void
    {
        // The reference once turned a list into raw bytes and crashed on a tuple.
        self::assertStringContainsString('cannot interpolate [1, 2]', self::resolveError("@x = [1, 2]\nv = \"@{x}\"")->getMessage());
        self::assertStringContainsString('cannot interpolate {1, 2}', self::resolveError("@x = (1, 2)\nv = \"a@{x}\"")->getMessage());
    }

    public function testSuffixes(): void
    {
        self::assertSame(['v' => 42], self::resolve('v = @{missing:42}'));
        self::assertSame(['v' => 'alt'], self::resolve("@x = \"anything\"\nv = \"@{x:+alt}\""));
        self::assertSame(['v' => ''], self::resolve('v = "@{missing:+alt}"'));
        $error = self::resolveError('v = @{missing:?"missing name"}');
        self::assertSame(CooperError::STAGE_RESOLVE, $error->stage);
        self::assertSame('missing name', $error->getMessage());
    }

    public function testAnUndefinedVariableIsAnError(): void
    {
        self::assertSame('undefined reference @{missing}', self::resolveError('v = @{missing}')->getMessage());
    }

    public function testIndexing(): void
    {
        self::assertSame(['v' => 'b'], self::resolve("@items = [\"a\", \"b\", \"c\"]\nv = @{items[1]}"));
        self::assertSame(['v' => 2], self::resolve("@l = [1, 2]\nv = @{l[-1]}"));
        self::assertSame(['v' => 'none'], self::resolve("@l = [1, 2]\nv = @{l[5]:\"none\"}"));
    }

    public function testAVariableWhoseValueIsAReferenceResolvesFirst(): void
    {
        self::assertSame(['host' => 'db.eu-west.internal'], self::resolve("@region = \${REGION:\"eu-west\"}\nhost = \"db.@{region}.internal\""));
    }

    public function testVariableCyclesAreErrorsNamingTheChain(): void
    {
        self::assertSame('circular @{...} reference: a -> a', self::resolveError("@a = @{a}\nv = @{a}")->getMessage());
        self::assertSame('circular @{...} reference: a -> b -> a', self::resolveError("@a = @{b}\n@b = @{a}\nv = @{a}")->getMessage());
    }

    // ---- ${...} ---------------------------------------------------------------

    public function testTheEnvironmentWorkedExample(): void
    {
        self::assertSame(
            ['region' => 'eu-west', 'max_retries' => 3, 'allowed_hosts' => ['localhost']],
            self::resolve("region = \${REGION}\nmax_retries = !int(\${MAX_RETRIES:3})\nallowed_hosts = \${ALLOWED_HOSTS[]:[\"localhost\"]}", ['env' => ['REGION' => 'eu-west']]),
        );
    }

    public function testAnEnvironmentValueIsAlwaysAString(): void
    {
        self::assertSame(['v' => '8080'], self::resolve('v = ${PORT}', ['env' => ['PORT' => '8080']]));
    }

    public function testUnsetAndEmptyAreAlike(): void
    {
        self::assertSame('undefined reference ${MISSING}', self::resolveError('v = ${MISSING}')->getMessage());
        self::assertSame(['v' => CooperAtom::of('fallback')], self::resolve('v = ${EMPTY:fallback}', ['env' => ['EMPTY' => '']]));
    }

    public function testListAndIndexForms(): void
    {
        $env = ['HOSTS' => 'a.example, b.example; c.example'];

        self::assertSame(['v' => ['a.example', 'b.example', 'c.example']], self::resolve('v = ${HOSTS[]}', ['env' => $env]));
        self::assertSame(['v' => 'b.example'], self::resolve('v = ${HOSTS[1]}', ['env' => $env]));
        self::assertSame(['v' => 'd'], self::resolve('v = ${HOSTS[9]:"d"}', ['env' => $env]));
    }

    // ---- %{...} ---------------------------------------------------------------

    public function testAConfigReferenceResolvesAgainstTheFinalTree(): void
    {
        self::assertSame(['a' => 1, 'b' => 1], self::resolve("a = %{b}\nb = 1"));
        self::assertSame(['a' => ['x' => 2], 'b' => 2], self::resolve("a.x = 1\nb = %{a.x}\n~a { x = 2 }"));
    }

    public function testAConfigCycleIsAnErrorWithItsPath(): void
    {
        $message = self::resolveError("a = %{b}\nb = %{c}\nc = %{a}")->getMessage();

        self::assertMatchesRegularExpression('/\Acircular %\{\.\.\.\} reference: (a|b|c) -> (a|b|c) -> (a|b|c) -> \1\z/', $message);
    }

    public function testAPathIsFollowedThroughAValueThatIsItselfAReference(): void
    {
        $resolved = self::resolve("origins.eu.code = \"EU\"\norigins.us.code = \"US\"\norigin = %{origins.eu}\ncode = %{origin.code}");

        self::assertIsArray($resolved);
        self::assertSame('EU', $resolved['code']);
    }

    public function testAPathIsFollowedThroughAChainOfReferences(): void
    {
        $resolved = self::resolve("a.b.c = 1\nx = %{a}\ny = %{x.b}\nz = %{y.c}");

        self::assertIsArray($resolved);
        self::assertSame(1, $resolved['z']);
    }

    public function testAPathIsFollowedThroughALoopVariableBoundToABlockElement(): void
    {
        $resolved = self::resolve("@origins = [{ code = \"EU\" }]\nfor @o in @{origins} as picked.first {\n  origin = @{o}\n}\ncode = %{picked.first.origin.code}");

        self::assertIsArray($resolved);
        self::assertSame('EU', $resolved['code']);
    }

    public function testAPathIsFollowedIntoASecretBlockElementAndWhatItFindsStaysSecret(): void
    {
        $resolved = self::resolve("@sites = [{ host = \"a\" }]\nfor @s in @{sites} as picked.first {\n  *site = @{s}\n}\nhost = %{picked.first.site.host}");

        self::assertIsArray($resolved);
        self::assertInstanceOf(CooperSecret::class, $resolved['host']);
        self::assertSame('a', $resolved['host']->reveal());
    }

    public function testAKeyMissingBehindAReferenceTakesTheDefault(): void
    {
        $resolved = self::resolve("origins.eu.code = \"EU\"\norigin = %{origins.eu}\nname = %{origin.name:\"none\"}");

        self::assertIsArray($resolved);
        self::assertSame('none', $resolved['name']);
    }

    public function testAPathCannotBeFollowedIntoAReferenceToAScalar(): void
    {
        self::assertSame('undefined reference %{b.d}', self::resolveError("a = 1\nb = %{a}\nc = %{b.d}")->getMessage());
    }

    public function testAReferenceIntoItsOwnValueIsACycle(): void
    {
        self::assertSame('circular %{...} reference: a.b -> a -> a.b', self::resolveError('a = %{a.b}')->getMessage());
    }

    // ---- !{resolver:payload} -----------------------------------------------------

    public function testAResolverGetsThePayloadVerbatim(): void
    {
        $resolvers = ['vault' => static fn (string $payload): string => "resolved:{$payload}"];

        self::assertSame(['v' => 'resolved:secret/db/password'], self::resolve('v = !{vault:secret/db/password}', ['resolvers' => $resolvers]));
        self::assertSame(['v' => 'resolved:{a:{b:{c:1}}}'], self::resolve('v = !{vault:{a:{b:{c:1}}}}', ['resolvers' => $resolvers]));
    }

    public function testAnUnregisteredResolverIsAnErrorNamingIt(): void
    {
        self::assertSame('unregistered resolver "vault"', self::resolveError('v = !{vault:x}')->getMessage());
    }

    public function testAFailingResolverIsAResolveError(): void
    {
        $resolvers = ['vault' => static fn (): never => throw new \RuntimeException('sealed')];

        self::assertSame('resolver "vault" failed for payload "x": sealed', self::resolveError('v = !{vault:x}', ['resolvers' => $resolvers])->getMessage());
    }

    // ---- !Name(arg) ---------------------------------------------------------------

    public function testBuiltInTags(): void
    {
        self::assertSame(['v' => 42], self::resolve('v = !int("42")'));
        self::assertSame(['v' => 42], self::resolve('v = !int(" +42 ")'));
        self::assertSame(['v' => 3.5], self::resolve('v = !float("3.5")'));
        self::assertSame(['v' => 1000.0], self::resolve('v = !float("1e3")'));
        self::assertSame(['v' => 7.0], self::resolve('v = !float(7)'));
        self::assertSame(['v' => true], self::resolve('v = !bool("true")'));
        self::assertEquals(['v' => new CooperDuration(300_000_000_000)], self::resolve('v = !duration("5m")'));
        self::assertEquals(['v' => new CooperDuration(1_000_000_000)], self::resolve('v = !duration("1_000ms")'));
        self::assertEquals(['v' => new CooperBytes(536_870_912)], self::resolve('v = !bytes("512MiB")'));
        self::assertEquals(['v' => new CooperDuration(new CooperInteger('25920000000000000000000'))], self::resolve('v = !duration("300000000d")'));
        self::assertEquals(['v' => new CooperBytes(new CooperInteger('22517998136852480000000'))], self::resolve('v = !bytes("20000000PiB")'));
    }

    public function testBuiltInTagFailures(): void
    {
        self::assertSame('!int(...) failed: not an integer: "4x"', self::resolveError('v = !int("4x")')->getMessage());
        self::assertSame('!float(...) failed: not a float: ".5"', self::resolveError('v = !float(".5")')->getMessage());
        self::assertSame('!bool(...) failed: not a boolean: "TRUE"', self::resolveError('v = !bool("TRUE")')->getMessage());
        self::assertSame('!duration(...) failed: invalid duration literal "_1ms"', self::resolveError('v = !duration("_1ms")')->getMessage());
        self::assertStringContainsString('cannot convert 5 to a byte size', self::resolveError('v = !bytes(5)')->getMessage());
    }

    public function testBoolReadsEverySpellingABooleanArrivesInFromAnEnvironment(): void
    {
        $spellings = ['true' => true, '1' => true, 'yes' => true, 'on' => true, 'false' => false, '0' => false, 'no' => false, 'off' => false];
        foreach ($spellings as $text => $value) {
            self::assertSame(['v' => $value], self::resolve('v = !bool(${B})', ['env' => ['B' => (string) $text]]), (string) $text);
        }
    }

    public function testBoolRefusesAnythingElseUpperCaseIncluded(): void
    {
        foreach (['TRUE', 'Yes', '2', 'y', ''] as $text) {
            self::assertSame('!bool(...) failed: not a boolean: "' . $text . '"', self::resolveError('v = !bool("' . $text . '")')->getMessage(), $text);
        }
    }

    public function testThePortDebugExample(): void
    {
        self::assertSame(['port' => 8080, 'debug' => false], self::resolve("port = !int(\${PORT:8080})\ndebug = !bool(\${DEBUG:false})"));
    }

    public function testAConsumerTag(): void
    {
        $tags = ['shout' => static fn (string $arg): string => strtoupper($arg)];

        self::assertSame(['v' => 'HI'], self::resolve('v = !shout("hi")', ['tags' => $tags]));
    }

    public function testAnUnregisteredTagIsAnErrorNamingIt(): void
    {
        self::assertSame('unregistered tag !uuid(...)', self::resolveError('v = !uuid("x")')->getMessage());
    }

    // ---- a module in a string (CASC.md §7) ------------------------------------------

    // A module reads as the name the document wrote, in every
    // implementation -- not as this host's spelling of it, a class-string
    // with `\` for every dot.

    public function testAModuleInterpolatesAsTheNameCascWrote(): void
    {
        self::assertSame(['s' => 'x-Foo.Bar'], self::resolve('s = "x-!module(\"Foo.Bar\")"'));
    }

    public function testAndSoDoesOneReachedThroughAReference(): void
    {
        self::assertSame('m=ASCO.HTTPClient', self::resolve("m = !module(\"ASCO.HTTPClient\")\ns = \"m=%{m}\"")['s']);
    }

    public function testAndSoDoesOneHeldInAVariable(): void
    {
        self::assertSame(['s' => 'm=Acme.Client'], self::resolve("@m = !module(\"Acme.Client\")\ns = \"m=@{m}\""));
    }

    // A mapped module is whatever the application mapped it to, so it
    // reads as that value does -- the one place the written name is not kept.
    public function testAModuleTheApplicationMappedReadsAsTheModuleItMapsTo(): void
    {
        self::assertSame(['s' => 'x-crypto'], self::resolve('s = "x-!module(\"Crypto\")"', ['modules' => ['Crypto' => 'crypto']]));
    }

    public function testAModuleReferencedAsAWholeValueIsStillItsClassString(): void
    {
        self::assertSame(
            ['m' => 'Foo\\Bar', 'n' => 'Foo\\Bar', 'l' => ['Foo\\Bar']],
            self::resolve("m = !module(\"Foo.Bar\")\nn = %{m}\nl = [%{m}]"),
        );
    }

    public function testASecretModuleIsStillASecretClassString(): void
    {
        $value = self::resolve('*m = !module("Foo.Bar")')['m'];

        self::assertInstanceOf(CooperSecret::class, $value);
        self::assertSame('Foo\\Bar', $value->reveal());
    }

    // Pinned current behaviour: a tag or a filter given a module gets the
    // class-string, as it did before a module read as its written name.
    public function testATagOrAFilterGivenAModuleGetsItsClassString(): void
    {
        self::assertSame(
            ['m' => 'Foo\\Bar', 'a' => 'FOO\\BAR', 'b' => 'foo\\bar'],
            self::resolve("m = !module(\"Foo.Bar\")\na = !upcase(%{m})\nb = %{m | downcase}"),
        );
    }

    public function testAModuleIsRemovedFromAListByTheSameModule(): void
    {
        self::assertSame(
            ['l' => ['B']],
            self::resolve("@l = [!module(\"A.B\"), !module(\"B\")]\nl = @{l}\n-l = [!module(\"A.B\")]"),
        );
    }

    // ---- for ... from, and the combined fixture ----------------------------------

    public function testLoopTemplateBaseAndOverridesMergePerReplica(): void
    {
        self::assertSame(
            [
                'defaults' => ['replica' => ['cpu' => 1, 'memory_mb' => 512]],
                'replicas' => [
                    'a' => ['cpu' => 2, 'memory_mb' => 512],
                    'b' => ['cpu' => 2, 'memory_mb' => 512],
                    'c' => ['cpu' => 2, 'memory_mb' => 512],
                ],
            ],
            self::resolve("defaults.replica { cpu = 1, memory_mb = 512 }\n\n@instances = [\"a\", \"b\", \"c\"]\nfor @instance in @{instances} from defaults.replica as replicas.\"@{instance}\" {\n  cpu = 2\n}"),
        );
    }

    public function testAReferenceThroughALayeredValue(): void
    {
        self::assertSame(512, self::resolve("defaults.replica { cpu = 1, memory_mb = 512 }\nfor @i in [\"a\"] from defaults.replica as replicas.\"@{i}\" { cpu = 2 }\nm = %{replicas.a.memory_mb}")['m']);
    }

    public function testCombinedFixture(): void
    {
        $dir = self::fixture('resolver');
        $result = Grammar::runTree((string) file_get_contents("{$dir}/main.casc"), ['root' => $dir, 'env' => []]);
        [$resolved] = Resolver::resolve($result->tree, ['vars' => $result->vars, 'env' => ['EXTRA_TAG' => 'prod']]);

        self::assertSame(
            [
                'base' => ['name' => 'base-service'],
                'service' => ['name' => 'base-service', 'tag' => 'prod'],
                'replicas' => ['0' => ['cpu' => 1], '1' => ['cpu' => 1]],
            ],
            $resolved,
        );
    }

    // ---- secrecy travels with the value --------------------------------------------

    public function testAConfigReferenceToASecretIsWrapped(): void
    {
        $result = self::resolve("database { *password = \"hunter2\", host = \"db.internal\" }\ncopy = %{database.password}");

        self::assertEquals(new CooperSecret('hunter2'), $result['copy']);
    }

    public function testALoopTemplateCopyingASecretWrapsEveryCopy(): void
    {
        $result = self::resolve("defaults.creds { *token = \"topsecret\" }\n@ids = [\"a\", \"b\"]\nfor @id in @{ids} from defaults.creds as replicas.\"@{id}\" {}");

        self::assertEquals(new CooperSecret('topsecret'), $result['replicas']['a']['token']);
        self::assertEquals(new CooperSecret('topsecret'), $result['replicas']['b']['token']);
    }

    public function testATagOnASecretArgumentReWrapsTheResult(): void
    {
        self::assertEquals(new CooperSecret(8443), self::resolve("*port_secret = \"8443\"\ntagged = !int(%{port_secret})")['tagged']);
    }

    public function testIndexingASecretListReWrapsTheElement(): void
    {
        self::assertEquals(new CooperSecret('b'), self::resolve("*items = [\"a\", \"b\", \"c\"]\nindexed = %{items[1]}")['indexed']);
    }

    public function testASecretEmbeddedInAStringIsRedactedInPlace(): void
    {
        $url = self::resolve("database { *password = \"hunter2\", host = \"db.internal\" }\nurl = \"postgres://user:%{database.password}@%{database.host}/app\"")['url'];

        self::assertInstanceOf(CooperSecret::class, $url);
        self::assertSame('postgres://user:[~~REDACTED~~]@db.internal/app', (string) $url);
        self::assertSame('postgres://user:hunter2@db.internal/app', $url->reveal());
    }

    public function testSeveralSecretsInOneStringRedactIndependently(): void
    {
        $conn = self::resolve("*user = \"admin\"\n*pass = \"hunter2\"\nconn = \"user=%{user};pass=%{pass};host=localhost\"")['conn'];

        self::assertSame('user=[~~REDACTED~~];pass=[~~REDACTED~~];host=localhost', (string) $conn);
        self::assertSame('user=admin;pass=hunter2;host=localhost', $conn->reveal());
    }

    public function testAStringWithoutSecretsIsPlain(): void
    {
        self::assertSame(['v' => 'hello world'], self::resolve('v = "hello @{name}"', ['vars' => ['name' => 'world']]));
    }

    public function testAWholeSecretAroundAPartialOneUsesTheBlanketMarker(): void
    {
        $value = self::resolve("*user = \"admin\"\n*conn = \"user=%{user}\"")['conn'];

        self::assertSame('[~~REDACTED~~]', (string) $value);
        self::assertSame('user=admin', $value->reveal());
    }

    // ---- where references contradicted CASC.md ----------------------------------

    public function testColonPlusOneSubstitutesTheNumberItIsNoSignedDefault(): void
    {
        // CASC.md §7.2: `:+` always introduces a substitute. The lexer
        // folds `:+1` into ":" and the integer `+1`; the reference once
        // read that as a default of 1 -- the opposite meaning.
        self::assertSame(['set' => 1, 'unset' => ''], self::resolve("set = \${PORT:+1}\nunset = \${NOPE:+1}", ['env' => ['PORT' => '9090']]));
        self::assertSame(['set' => INF, 'unset' => ''], self::resolve("set = \${PORT:+inf}\nunset = \${NOPE:+inf}", ['env' => ['PORT' => '9090']]));
    }

    public function testColonMinusOneIsADefaultOfMinusOne(): void
    {
        self::assertSame(['v' => -1, 'w' => -INF], self::resolve("v = \${NOPE:-1}\nw = \${NOPE:-inf}"));
    }

    public function testTheMessageOfARequiredSuffixInterpolatesAsEveryDoubleQuotedStringDoes(): void
    {
        $error = self::resolveError("@m = \"M\"\na = @{n:?\"need @{m}\"}");

        self::assertSame(CooperError::STAGE_RESOLVE, $error->stage);
        self::assertSame('need M', $error->getMessage());
    }

    public function testASecretInARequiredMessageShowsRedacted(): void
    {
        self::assertSame('need [~~REDACTED~~]', self::loadError("*s = \"S\"\na = @{n:?\"need %{s}\"}")->getMessage());
    }

    public function testInfinityReadsAsCascSpellsItInsideAString(): void
    {
        self::assertSame(['v' => 'inf -inf'], self::resolve("@a = inf\n@b = -inf\nv = \"@{a} @{b}\""));
    }

    public function testNilReadsAsCascSpellsItInsideAString(): void
    {
        self::assertSame(['v' => '[nil]'], self::resolve("@n = nil\nv = \"[@{n}]\""));
    }

    public function testAnIntegerBeyond64BitsReadsAsItsDigitsInsideAString(): void
    {
        self::assertSame(['v' => 'n=18446744073709551616'], self::resolve("@n = 18446744073709551616\nv = \"n=@{n}\""));
    }

    public function testAnIndexPastTheStartOfAListIsMissingAndTakesTheDefault(): void
    {
        self::assertSame(['a' => 3, 'b' => 'none'], self::resolve("@l = [1, 2, 3]\na = @{l[-1]}\nb = @{l[-4]:\"none\"}"));
    }

    public function testIntBeyond64BitsKeepsEveryDigit(): void
    {
        $value = self::resolve('v = !int("-99999999999999999999")')['v'];

        self::assertInstanceOf(CooperInteger::class, $value);
        self::assertSame('-99999999999999999999', (string) $value);
    }
}

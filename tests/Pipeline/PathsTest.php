<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Tests\Pipeline;

use JOetjen\Cooper\Pipeline\Paths;
use JOetjen\Cooper\Tests\Support\CooperTestCase;

final class PathsTest extends CooperTestCase
{
    public function testExpand(): void
    {
        self::assertSame('/a/c', Paths::expand('/a/./b/../c//'));
        self::assertSame('/base/x', Paths::expand('x', '/base'));
        self::assertSame('/', Paths::expand('/..'));
    }

    public function testBraceExpansion(): void
    {
        self::assertSame(['a/x', 'a/y', 'b/x', 'b/y'], Paths::expandBraces('{a,b}/{x,y}'));
        self::assertSame(['plain'], Paths::expandBraces('plain'));
    }

    public function testWildcards(): void
    {
        $dir = $this->scratchDir('glob');
        foreach (['a.casc', 'b.casc', 'c.txt', 'sub/d.casc', 'sub/deep/e.casc', '.hidden.casc', 'sub/.h/f.casc'] as $file) {
            @mkdir(dirname("{$dir}/{$file}"), 0777, true);
            touch("{$dir}/{$file}");
        }
        $relative = static fn (array $paths): array => array_map(static fn (string $p): string => substr($p, strlen($dir) + 1), $paths);

        self::assertSame(['a.casc', 'b.casc'], $relative(Paths::wildcard("{$dir}/*.casc")));
        self::assertSame(['a.casc', 'b.casc', 'sub/d.casc', 'sub/deep/e.casc'], $relative(Paths::wildcard("{$dir}/**.casc")));
        self::assertSame(['a.casc', 'b.casc', 'sub/d.casc', 'sub/deep/e.casc'], $relative(Paths::wildcard("{$dir}/**/*.casc")));
        self::assertSame(['sub/deep/e.casc'], $relative(Paths::wildcard("{$dir}/sub/**/e.casc")));
        self::assertSame(['a.casc'], $relative(Paths::wildcard("{$dir}/[a]?casc")));
        self::assertSame(['.hidden.casc'], $relative(Paths::wildcard("{$dir}/.*.casc")));
        self::assertSame(['c.txt'], $relative(Paths::wildcard("{$dir}/c.txt")));
        self::assertSame([], Paths::wildcard("{$dir}/missing/*.casc"));
    }
}

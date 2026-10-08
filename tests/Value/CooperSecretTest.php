<?php

declare(strict_types=1);

namespace JOetjen\Cooper\Tests\Value;

use JOetjen\Cooper\Value\CooperSecret;
use PHPUnit\Framework\TestCase;

/**
 * Ported from the Elixir suite's `secret_test.exs`, plus the PHP
 * display surfaces (`json_encode`, `var_dump`, `print_r`).
 */
final class CooperSecretTest extends TestCase
{
    public function testAWholeValueSecretShowsTheBlanketMarker(): void
    {
        $secret = new CooperSecret('hunter2');

        self::assertSame('[~~REDACTED~~]', (string) $secret);
        self::assertSame('"[~~REDACTED~~]"', json_encode($secret));
    }

    public function testNoDisplaySurfaceLeaksTheValue(): void
    {
        $secret = new CooperSecret('hunter2');

        ob_start();
        var_dump($secret);
        $dumped = (string) ob_get_clean();

        self::assertStringNotContainsString('hunter2', $dumped);
        self::assertStringNotContainsString('hunter2', print_r($secret, true));
        self::assertStringNotContainsString('hunter2', (string) json_encode(['nested' => $secret]));
        self::assertStringContainsString('[~~REDACTED~~]', $dumped);
    }

    public function testRevealGivesBackTheRealValueOfAnyType(): void
    {
        self::assertSame('hunter2', (new CooperSecret('hunter2'))->reveal());
        self::assertSame(42, (new CooperSecret(42))->reveal());
        self::assertSame(['a' => 1], (new CooperSecret(['a' => 1]))->reveal());
    }

    public function testAPartiallyRedactedSecretShowsItsRedactedText(): void
    {
        $secret = new CooperSecret('conn=hunter2', 'conn=[~~REDACTED~~]');

        self::assertSame('conn=[~~REDACTED~~]', (string) $secret);
        self::assertSame('conn=hunter2', $secret->reveal());
    }
}

<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Tests;

use PHPUnit\Framework\TestCase;
use Tresbien\Drupatch\Plugin;

final class PluginTest extends TestCase
{
    public function testTheHookRunsWhenTheSiteSaysNothing(): void
    {
        self::assertTrue(Plugin::hookEnabled([]));
        self::assertTrue(Plugin::hookEnabled(['drupatch' => ['endpoint' => 'https://example.com']]));
    }

    public function testASiteCanTurnTheHookOff(): void
    {
        self::assertFalse(Plugin::hookEnabled(['drupatch' => ['hook' => false]]));
    }

    public function testTurningItOnIsTheSameAsSayingNothing(): void
    {
        self::assertTrue(Plugin::hookEnabled(['drupatch' => ['hook' => true]]));
    }

    // Only false turns it off. A string or a number is a value nobody
    // meant, and silently losing the report would be worse than keeping it.
    public function testAValueThatIsNotFalseLeavesTheHookAlone(): void
    {
        self::assertTrue(Plugin::hookEnabled(['drupatch' => ['hook' => 'false']]));
        self::assertTrue(Plugin::hookEnabled(['drupatch' => ['hook' => 0]]));
    }

    public function testExtraThatIsNotAMapIsIgnored(): void
    {
        self::assertTrue(Plugin::hookEnabled(['drupatch' => 'off']));
    }
}

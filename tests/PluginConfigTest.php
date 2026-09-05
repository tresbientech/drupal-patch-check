<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TresBienTech\Drupatch\Plugin;

/**
 * The options a site sets under extra.drupal-patch-check.
 */
#[CoversClass(Plugin::class)]
class PluginConfigTest extends TestCase
{
    public function testASiteThatNamesNoDirectoryGetsPatches(): void
    {
        self::assertSame('patches', Plugin::patchDirectory([]));
        self::assertSame('patches', Plugin::patchDirectory(['drupal-patch-check' => ['hook' => true]]));
    }

    public function testTheNamedDirectoryIsReadAsWritten(): void
    {
        self::assertSame('patchs', Plugin::patchDirectory(['drupal-patch-check' => ['patch-directory' => '  patchs  ']]));
    }

    public function testAValueThatNamesNoDirectoryIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('patch-directory is where an adopted patch is written');

        Plugin::patchDirectory(['drupal-patch-check' => ['patch-directory' => true]]);
    }

    public function testAnEmptyValueIsRefused(): void
    {
        $this->expectException(RuntimeException::class);

        Plugin::patchDirectory(['drupal-patch-check' => ['patch-directory' => '   ']]);
    }
}

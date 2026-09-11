<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Manager;

#[CoversClass(Manager::class)]
class ManagerTest extends TestCase
{
    public function testTheLineComesFromTheFirstNumber(): void
    {
        self::assertTrue(Manager::ofVersion('1.7.3')->isOne());
        self::assertTrue(Manager::ofVersion('v1.7.3')->isOne());
        self::assertTrue(Manager::ofVersion('2.0.0')->isTwo());
        self::assertTrue(Manager::ofVersion('2.1.0-beta1')->isTwo());
    }

    public function testASiteWithNoManagerIsNeitherLine(): void
    {
        foreach (['', '3.0.0', 'dev-main', 'nonsense'] as $version) {
            $manager = Manager::ofVersion($version);
            self::assertFalse($manager->isOne(), $version);
            self::assertFalse($manager->isTwo(), $version);
        }
    }

    public function testEachLineReadsItsOwnPatchesFileKey(): void
    {
        $extra = [
            'patches-file' => 'one.json',
            'composer-patches' => ['patches-file' => 'two.json'],
        ];

        self::assertSame('one.json', Manager::ofVersion('1.7.3')->patchesFile($extra));
        self::assertSame('two.json', Manager::ofVersion('2.0.0')->patchesFile($extra));
    }

    public function testALineReadingOnlyTheOtherKeyFindsNoFile(): void
    {
        self::assertSame('', Manager::ofVersion('2.0.0')->patchesFile(['patches-file' => 'one.json']));
        self::assertSame('', Manager::ofVersion('1.7.3')->patchesFile(['composer-patches' => ['patches-file' => 'two.json']]));
    }

    // A file written the recommended way is found before the manager it was
    // written for is installed.
    public function testASiteWithNoManagerReadsTheTwoKeyFirst(): void
    {
        $none = Manager::ofVersion('');

        self::assertSame('two.json', $none->patchesFile(['patches-file' => 'one.json', 'composer-patches' => ['patches-file' => 'two.json']]));
        self::assertSame('one.json', $none->patchesFile(['patches-file' => 'one.json']));
    }

    public function testAKeySetToAnythingButAPathNamesNoFile(): void
    {
        foreach ([null, '', '  ', 42, ['patches.json'], true] as $value) {
            self::assertSame('', Manager::ofVersion('2.0.0')->patchesFile(['composer-patches' => ['patches-file' => $value]]));
        }
    }
}

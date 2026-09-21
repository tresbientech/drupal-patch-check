<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Command\AddCommand;
use TresBienTech\Drupatch\Command\PatchCommand;
use TresBienTech\Drupatch\Command\RerollCommand;
use TresBienTech\Drupatch\Command\UpgradeCommand;

/**
 * Every command that re-rolls takes the same flag on the files a release package leaves out.
 */
class FromSourceFlagTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<PatchCommand>}>
     */
    public static function rerolling(): iterable
    {
        yield 'reroll' => [RerollCommand::class];
        yield 'add' => [AddCommand::class];
        yield 'upgrade' => [UpgradeCommand::class];
    }

    /**
     * @param class-string<PatchCommand> $class
     */
    #[DataProvider('rerolling')]
    public function testTheCommandTakesTheFlag(string $class): void
    {
        $definition = (new $class())->getDefinition();

        self::assertTrue($definition->hasOption('from-source'));
        self::assertFalse($definition->getOption('from-source')->acceptValue());
    }
}

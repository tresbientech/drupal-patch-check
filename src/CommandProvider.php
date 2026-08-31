<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;

/**
 * Registers the commands the plugin adds to composer.
 */
class CommandProvider implements CommandProviderCapability
{
    /**
     * @return array<int, \Composer\Command\BaseCommand>
     */
    public function getCommands(): array
    {
        return [new CheckCommand()];
    }
}

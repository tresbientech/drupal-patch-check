<?php

declare(strict_types=1);

namespace Tresbien\Drupatch;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Tresbien\Drupatch\Command\CheckCommand;

/**
 * Registers the commands the plugin adds to composer.
 */
final class CommandProvider implements CommandProviderCapability
{
    /**
     * @return array<int, \Composer\Command\BaseCommand>
     */
    public function getCommands(): array
    {
        return [new CheckCommand()];
    }
}

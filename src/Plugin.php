<?php

declare(strict_types=1);

namespace Tresbien\Drupatch;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Throwable;
use Tresbien\Drupatch\Plan\Client;
use Tresbien\Drupatch\Render\HookReport;

/**
 * Prints a patch verdict tally after every composer update.
 *
 * The hook writes no file and returns whatever happens, so a diagnostic
 * cannot break an install.
 */
final class Plugin implements PluginInterface, EventSubscriberInterface, Capable
{
    private Composer $composer;

    private IOInterface $io;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    /**
     * @return array<string, string>
     */
    public function getCapabilities(): array
    {
        return [CommandProviderCapability::class => CommandProvider::class];
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [ScriptEvents::POST_UPDATE_CMD => 'onPostUpdate'];
    }

    public function onPostUpdate(Event $event): void
    {
        try {
            $site = Site::atWorkingDirectory($this->composer);
            foreach ($site->patches()->notes as $note) {
                $this->io->write('<comment>drupatch: '.$note.'</comment>');
            }
            if (!$site->hasPatches()) {
                return;
            }
            $client = Client::fromComposer($this->composer, $this->io);
            $plan = $client->plan($site->composerJson(), $site->composerLock(), $site->patches());
            foreach (HookReport::lines($plan) as $line) {
                $this->io->write($line);
            }
        } catch (Throwable $e) {
            $this->io->write('<comment>drupatch: '.$e->getMessage().'</comment>');
        }
    }
}

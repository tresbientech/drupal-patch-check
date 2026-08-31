<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Throwable;
use TresBienTech\Drupatch\Render\HookReport;

/**
 * Prints a patch verdict tally after every composer update.
 */
class Plugin implements PluginInterface, EventSubscriberInterface, Capable
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

    /**
     * Whether the site wants the report after an update; only a literal false turns it off.
     *
     * @param array<mixed> $extra the root package's extra
     */
    public static function hookEnabled(array $extra): bool
    {
        return false !== ($extra['drupatch']['hook'] ?? true);
    }

    public function onPostUpdate(Event $event): void
    {
        if (!self::hookEnabled($this->composer->getPackage()->getExtra())) {
            return;
        }
        try {
            $site = Site::atWorkingDirectory($this->composer);
            foreach ($site->patches()->notes as $note) {
                $this->io->write('<comment>drupatch: '.$note.'</comment>');
            }
            if (!$site->hasPatches()) {
                return;
            }
            $client = Client::fromComposer($this->composer, $this->io);
            $plan = $client->plan($site->composerJson(), $site->composerLock(), $site->patches(), '', false, [], Candidates::declaredCore($this->composer, $site->checkable()));
            foreach (HookReport::lines($plan) as $line) {
                $this->io->write($line);
            }
        } catch (Throwable $e) {
            $this->io->write('<comment>drupatch: '.$e->getMessage().'</comment>');
        }
    }
}

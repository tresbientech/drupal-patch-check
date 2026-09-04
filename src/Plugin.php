<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch;

use Composer\Cache;
use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Factory;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Throwable;
use TresBienTech\Drupatch\Render\HookReport;
use TresBienTech\Drupatch\Render\Report;

/**
 * Prints a patch verdict tally after a composer update the site opted into.
 */
class Plugin implements PluginInterface, EventSubscriberInterface, Capable
{
    /** The root package's extra key holding `hook`. */
    private const EXTRA = 'drupal-patch-check';

    private const NOTICE = [
        'Drupal Patch Check is installed. It sends your patch data to api.tresbien.tech.',
        'Review what is sent by running '.Report::COMMAND.' --dry-run.',
        'Your submission is cached on the server to improve the service.',
    ];

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
        return [
            ScriptEvents::POST_UPDATE_CMD => 'onPostUpdate',
            PackageEvents::POST_PACKAGE_INSTALL => 'onPackageInstall',
        ];
    }

    /**
     * Whether the site wants the report after an update; only a literal true turns it on.
     *
     * @param array<mixed> $extra the root package's extra
     */
    public static function hookEnabled(array $extra): bool
    {
        return true === ($extra[self::EXTRA]['hook'] ?? null);
    }

    /**
     * Prints the disclosure when composer installs this plugin, and nothing else.
     */
    public function onPackageInstall(PackageEvent $event): void
    {
        $operation = $event->getOperation();
        if (!$operation instanceof InstallOperation) {
            return;
        }
        if (Client::PACKAGE === $operation->getPackage()->getName()) {
            $this->printNotice();
        }
    }

    /**
     * The notice is for a person who has not decided about the hook yet, once per site.
     */
    private function printNotice(): void
    {
        if (!$this->io->isInteractive()) {
            return;
        }
        if (isset($this->composer->getPackage()->getExtra()[self::EXTRA]['hook'])) {
            return;
        }
        $cache = $this->noticeCache();
        $marker = 'notice.'.\sha1(self::rootPath());
        if (null !== $cache && false !== $cache->read($marker)) {
            return;
        }
        foreach (self::NOTICE as $line) {
            $this->io->write('<info>'.$line.'</info>');
        }
        $cache?->write($marker, '');
    }

    /**
     * Where the marker lives; null when composer has no cache directory to keep it in.
     */
    private function noticeCache(): ?Cache
    {
        $dir = (string) $this->composer->getConfig()->get('cache-dir');

        return '' === $dir ? null : new Cache($this->io, $dir.\DIRECTORY_SEPARATOR.PatchText::CACHE_DIR);
    }

    /**
     * The site the marker is keyed by, read from composer's own path rather than the file.
     */
    private static function rootPath(): string
    {
        $path = Factory::getComposerFile();
        $real = \realpath($path);

        return \dirname(false === $real ? $path : $real);
    }

    public function onPostUpdate(Event $event): void
    {
        if (!self::hookEnabled($this->composer->getPackage()->getExtra())) {
            return;
        }
        try {
            $site = Site::atWorkingDirectory($this->composer, $this->io);
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

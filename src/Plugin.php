<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch;

use Composer\Cache;
use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Throwable;
use TresBienTech\Drupatch\Command\CommandProvider;
use TresBienTech\Drupatch\Fetch\PatchText;
use TresBienTech\Drupatch\Read\PatchConfig;
use TresBienTech\Drupatch\Read\Scope;
use TresBienTech\Drupatch\Read\Site;
use TresBienTech\Drupatch\Render\Report;
use TresBienTech\Drupatch\Service\Client;
use TresBienTech\Drupatch\Source\MergeRequest;

/**
 * Prints a patch verdict tally after a composer update the site opted into.
 */
class Plugin implements PluginInterface, EventSubscriberInterface, Capable
{
    public const EXTRA = 'drupal-patch-check';

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
            ScriptEvents::POST_INSTALL_CMD => 'onPostInstall',
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
            $this->printMove();
        }
    }

    /**
     * The move prompt, which no setting turns off: the hook key says what a site wants printed after an update, and says nothing about which patch manager it runs.
     */
    private function printMove(): void
    {
        $this->once('move', Report::print(Report::movePrompt(Manager::fromComposer($this->composer))));
    }

    /**
     * The notice is for a person who has not decided about the hook yet, once per site.
     */
    private function printNotice(): void
    {
        if (isset($this->composer->getPackage()->getExtra()[self::EXTRA]['hook'])) {
            return;
        }
        $this->once('notice', Report::print(Report::notice()));
    }

    /**
     * Prints lines the first time this site sees them, and never again. A run nobody is watching prints none.
     *
     * @param string       $key   what the marker is named for, so one block printing does not silence another
     * @param list<string> $lines
     */
    private function once(string $key, array $lines): void
    {
        if ([] === $lines || !$this->io->isInteractive()) {
            return;
        }
        $cache = $this->noticeCache();
        $marker = $key.'.'.\sha1(Site::rootDirectory());
        if (null !== $cache && false !== $cache->read($marker)) {
            return;
        }
        $this->io->write($lines);
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
     * An install downloads every declared patch afresh, so it says what the site declares. The verdict report is the update's.
     */
    public function onPostInstall(Event $event): void
    {
        $this->warnUnpinned($this->composer->getPackage()->getExtra(), Manager::fromComposer($this->composer));
    }

    /**
     * What a site is told, whatever it configured: a declaration anyone with a drupal.org account can push to.
     *
     * @param array<string, mixed> $extra the root package's extra
     */
    private function warnUnpinned(array $extra, Manager $manager): void
    {
        $root = Site::rootDirectory();
        $this->io->write(Report::print(Report::unpinnedWarning($manager, \count(MergeRequest::among(\array_column(PatchConfig::declared($extra, $root, $manager), 'source'))))));
    }

    public function onPostUpdate(Event $event): void
    {
        try {
            $extra = $this->composer->getPackage()->getExtra();
            $manager = Manager::fromComposer($this->composer);
            $this->warnUnpinned($extra, $manager);
            if (!self::hookEnabled($extra)) {
                return;
            }
            $request = Site::read($this->composer, $this->io)->request();
            foreach ($request->patches->notes as $note) {
                $this->io->write(Report::message($note, 'comment'));
            }
            if ($request->patches->isEmpty()) {
                return;
            }
            $client = Client::fromComposer($this->composer, $this->io);
            // The hook prints every verdict as the service sent it, whichever
            // patch manager the site runs.
            $plan = $client->plan($request, null, Scope::whole());
            $this->io->write(Report::print(Report::hook($plan)));
        } catch (Throwable $e) {
            $this->io->write(Report::message($e->getMessage(), 'comment'));
        }
    }
}

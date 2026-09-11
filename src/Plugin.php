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
use RuntimeException;
use Throwable;
use TresBienTech\Drupatch\Command\CommandProvider;
use TresBienTech\Drupatch\Fetch\PatchText;
use TresBienTech\Drupatch\Fetch\Vendoring;
use TresBienTech\Drupatch\Read\Candidates;
use TresBienTech\Drupatch\Read\PatchConfig;
use TresBienTech\Drupatch\Read\Site;
use TresBienTech\Drupatch\Render\HookReport;
use TresBienTech\Drupatch\Render\Report;
use TresBienTech\Drupatch\Service\Client;
use TresBienTech\Drupatch\Source\MergeRequest;

/**
 * Prints a patch verdict tally after a composer update the site opted into.
 */
class Plugin implements PluginInterface, EventSubscriberInterface, Capable
{
    public const EXTRA = 'drupal-patch-check';

    private const NOTICE = [
        'Drupal Patch Check is installed. It sends your patch data to api.tresbien.tech.',
        'Review what is sent by running '.Report::COMMAND.' --dry-run.',
    ];

    /** What a site on 1.x of the patch manager is told once. */
    private const MOVE = [
        'This site runs cweagans/composer-patches 1.x. 2.x is the line to be on.',
        'It applies with git apply alone and locks a hash per patch.',
        'See what moving costs: composer '.Manager::UPGRADE.' --dry-run',
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
     * Reads the directory a copied patch goes to, or the default when the site names none.
     *
     * @param array<mixed> $extra the root package's extra
     *
     * @throws RuntimeException when the key is set to anything but a non-empty string
     */
    public static function patchDirectory(array $extra): string
    {
        if (!isset($extra[self::EXTRA]['patch-directory'])) {
            return Vendoring::DIRECTORY;
        }
        $directory = $extra[self::EXTRA]['patch-directory'];
        if (!\is_string($directory) || '' === \trim($directory)) {
            throw new RuntimeException('extra.'.self::EXTRA.'.patch-directory is where a copied patch is written; it has to be a directory under the site root');
        }

        return \trim($directory);
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
     * What a site on 1.x is told once, empty for a site already on 2.x or with no patch manager.
     *
     * @return list<string>
     */
    public static function movePrompt(Manager $manager): array
    {
        return $manager->isOne() ? self::MOVE : [];
    }

    /**
     * The move prompt, which no setting turns off: the hook key says what a site wants printed after an update, and says nothing about which patch manager it runs.
     */
    private function printMove(): void
    {
        $lines = self::movePrompt(Manager::fromComposer($this->composer));
        $this->once('move', \array_map(static fn (string $line): string => '<comment>'.$line.'</comment>', $lines));
    }

    /**
     * The notice is for a person who has not decided about the hook yet, once per site.
     */
    private function printNotice(): void
    {
        if (isset($this->composer->getPackage()->getExtra()[self::EXTRA]['hook'])) {
            return;
        }
        $this->once('notice', \array_map(static fn (string $line): string => '<info>'.$line.'</info>', self::NOTICE));
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
        $marker = $key.'.'.\sha1(self::rootPath());
        if (null !== $cache && false !== $cache->read($marker)) {
            return;
        }
        foreach ($lines as $line) {
            $this->io->write($line);
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

    /**
     * An install downloads every declared patch afresh, so it says what the site declares. The verdict report is the update's.
     */
    public function onPostInstall(Event $event): void
    {
        $this->warnUnpinned($this->composer->getPackage()->getExtra(), Manager::fromComposer($this->composer));
    }

    /**
     * What a site on 1.x is told, whatever it configured: a declaration anyone with a drupal.org account can push to. 2.x records a hash per patch and refuses bytes that moved, so it earns no warning.
     *
     * @param array<string, mixed> $extra the root package's extra
     */
    private function warnUnpinned(array $extra, Manager $manager): void
    {
        if ($manager->isTwo()) {
            return;
        }
        $root = Site::rootDirectory();
        foreach (Report::unpinnedWarning(\count(MergeRequest::among(PatchConfig::declared($extra, $root, $manager)))) as $line) {
            $this->io->write($line);
        }
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
            $site = Site::atWorkingDirectory($this->composer, $this->io, $manager);
            foreach ($site->patches->notes as $note) {
                $this->io->write('<comment>'.Text::t('drupatch: @message', ['@message' => $note]).'</comment>');
            }
            if ($site->patches->isEmpty()) {
                return;
            }
            $client = Client::fromComposer($this->composer, $this->io);
            $plan = $client->plan($site->composerJson, $site->composerLock, $site->patches, '', false, [], Candidates::declaredCore($this->composer, $site->checkable));
            foreach (HookReport::lines($plan) as $line) {
                $this->io->write($line);
            }
        } catch (Throwable $e) {
            $this->io->write('<comment>'.Text::t('drupatch: @message', ['@message' => $e->getMessage()]).'</comment>');
        }
    }
}

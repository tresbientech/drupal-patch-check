<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Read;

use Closure;
use Composer\Composer;
use Composer\Factory;
use Composer\IO\IOInterface;
use RuntimeException;
use TresBienTech\Drupatch\Fetch\Candidates;
use TresBienTech\Drupatch\Fetch\PatchText;
use TresBienTech\Drupatch\Fetch\Vendoring;
use TresBienTech\Drupatch\Manager;
use TresBienTech\Drupatch\Plugin;
use TresBienTech\Drupatch\Service\Client;
use TresBienTech\Drupatch\Text;

/**
 * The two composer files of one site, and the request the service may be told about it.
 *
 * Reading a site opens no connection and reads no patch text. The declared
 * texts are read when a caller asks for the request.
 */
class Site
{
    /** The body the service accepts for one scan. */
    private const BODY_LIMIT = 32 * 1024 * 1024;

    /** Room kept for the field names, the flags and the candidates. */
    private const ENVELOPE_BYTES = 64 * 1024;

    private ?PatchText $text = null;

    private ?Request $request = null;

    private function __construct(
        /** The directory holding composer.json: where a re-rolled patch is written, and the only tree the plugin touches. */
        public readonly string $root,
        public readonly Manager $manager,
        /**
         * The root package's extra, as composer.json holds it.
         *
         * @var array<string, mixed>
         */
        public readonly array $extra,
        private readonly Composer $composer,
        private readonly IOInterface $io,
        /** @var ?Closure(string): array{status: int, body: string} */
        private readonly ?Closure $fetch,
        private readonly string $composerJson,
        private readonly string $composerLock,
        /** @var array<string, string> */
        private readonly array $checkable,
        /** @var array<string, string> */
        private readonly array $installed,
        /** @var array<string, string> */
        private readonly array $constraints,
    ) {
    }

    /**
     * The site this composer run is about.
     */
    public static function read(Composer $composer, IOInterface $io): self
    {
        return self::of($composer, $io, Factory::getComposerFile(), null);
    }

    /**
     * The site one composer.json names, fetching patch text through the given function.
     *
     * @param ?Closure(string): array{status: int, body: string} $fetch composer's own downloader when null
     */
    public static function at(Composer $composer, IOInterface $io, string $jsonPath, ?Closure $fetch = null): self
    {
        return self::of($composer, $io, $jsonPath, $fetch);
    }

    /**
     * The directory holding the composer.json this run is about.
     */
    public static function rootDirectory(): string
    {
        return self::directoryOf(Factory::getComposerFile());
    }

    /**
     * The release the lock pins for one package, empty when the site installs none.
     */
    public function installs(string $package): string
    {
        return $this->installed[$package] ?? '';
    }

    /**
     * Whether the service has a drupal.org release to judge this package's patches against.
     */
    public function canJudge(string $package): bool
    {
        return isset($this->checkable[$package]);
    }

    /**
     * Where a copied patch is written, under the site root.
     */
    public function patchDirectory(): string
    {
        if (!isset($this->extra[Plugin::EXTRA]['patch-directory'])) {
            return Vendoring::DIRECTORY;
        }
        $directory = $this->extra[Plugin::EXTRA]['patch-directory'];
        if (!\is_string($directory) || '' === \trim($directory)) {
            throw new RuntimeException('extra.'.Plugin::EXTRA.'.patch-directory is where a copied patch is written; it has to be a directory under the site root');
        }

        return \trim($directory);
    }

    /**
     * The reader that turns a declared source into the text that travels.
     */
    public function text(): PatchText
    {
        if (null === $this->text) {
            $this->text = null === $this->fetch
                ? PatchText::fromComposer($this->composer, $this->io, $this->root)
                : new PatchText($this->root, $this->fetch, '');
        }

        return $this->text;
    }

    /**
     * The site as the service may be told it, read once.
     */
    public function request(): Request
    {
        if (null === $this->request) {
            // What the texts may cost once escaped, which is what the request
            // is measured in.
            $budget = \max(0, self::BODY_LIMIT - self::ENVELOPE_BYTES
                - \strlen(\json_encode($this->composerJson, \JSON_THROW_ON_ERROR))
                - \strlen(\json_encode($this->composerLock, \JSON_THROW_ON_ERROR)));
            $this->request = $this->about(PatchConfig::read(
                $this->text(),
                $budget,
                $this->checkable,
                $this->extra,
                $this->root,
                $this->manager,
            ));
        }

        return $this->request;
    }

    /**
     * A request about one patch the site has yet to declare, judged behind the patches the site declares for its package.
     *
     * @param string $text the patch as it will travel
     *
     * @throws RuntimeException when the service has no release to judge the package against
     */
    public function requestFor(Declaration $declaration, string $text): Request
    {
        // What leaves the site is the checkable subset (ADR 0003).
        if (!$this->canJudge($declaration->package)) {
            throw new RuntimeException(Text::t('this site installs @package from outside drupal.org, so the service cannot judge a patch on it', ['@package' => $declaration->package]));
        }

        return $this->about($this->request()->patches->adding($declaration, $text));
    }

    /**
     * @param ?Closure(string): array{status: int, body: string} $fetch
     */
    private static function of(Composer $composer, IOInterface $io, string $jsonPath, ?Closure $fetch): self
    {
        $lockPath = '.json' === \substr($jsonPath, -5)
            ? \substr($jsonPath, 0, -5).'.lock'
            : $jsonPath.'.lock';

        $json = @\file_get_contents($jsonPath);
        if (false === $json) {
            throw new RuntimeException('composer.json is not readable');
        }
        $lock = @\file_get_contents($lockPath);
        if (false === $lock) {
            throw new RuntimeException('composer.lock is not readable; run composer update first');
        }

        // What the site requires, so a candidate can be resolved inside
        // the constraint rather than past it.
        $constraints = [];
        foreach ([$composer->getPackage()->getRequires(), $composer->getPackage()->getDevRequires()] as $set) {
            foreach ($set as $name => $link) {
                $constraints[$name] = $link->getConstraint()->getPrettyString();
            }
        }

        $decoded = \json_decode($json, true);
        if (!\is_array($decoded)) {
            throw new RuntimeException('composer.json is not readable JSON');
        }
        // What the service can judge decides the whole request: the two
        // documents, the patches resolved, and the candidates asked for.
        $filtered = Client::filter($decoded, $lock);

        return new self(
            self::directoryOf($jsonPath),
            Manager::fromComposer($composer),
            (array) ($decoded['extra'] ?? []),
            $composer,
            $io,
            $fetch,
            $filtered['json'],
            $filtered['lock'],
            $filtered['packages'],
            $filtered['locked'],
            \array_intersect_key($constraints, $filtered['packages']),
        );
    }

    private static function directoryOf(string $jsonPath): string
    {
        $real = \realpath($jsonPath);

        return \dirname(false === $real ? $jsonPath : $real);
    }

    /**
     * The request carrying these patches, with everything else the site already read.
     */
    private function about(PatchConfig $patches): Request
    {
        return new Request(
            $this->composerJson,
            $this->composerLock,
            $patches,
            $this->installed,
            $this->constraints,
            // What each installed release says it needs of core, read from
            // the site's own vendor directory.
            Candidates::declaredCore($this->composer, $this->checkable),
        );
    }
}

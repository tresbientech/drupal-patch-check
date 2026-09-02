<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch;

use Composer\Composer;
use Composer\Factory;
use Composer\IO\IOInterface;
use RuntimeException;

/**
 * The two composer files of one site, and the text of the patches it declares.
 */
class Site
{
    /** The body the service accepts for one scan. */
    private const BODY_LIMIT = 32 * 1024 * 1024;

    /** Room kept for the field names, the flags and the candidates. */
    private const ENVELOPE_BYTES = 64 * 1024;

    /**
     * @param array<string, string> $checkable   checkable package to its installed version
     * @param array<string, string> $installed   every package the lock names, to its version, checkable or not
     * @param array<string, string> $constraints
     */
    private function __construct(
        private readonly string $root,
        private readonly string $composerJson,
        private readonly string $composerLock,
        private readonly array $checkable,
        private readonly array $installed,
        private readonly PatchConfig $patches,
        private readonly array $constraints,
    ) {
    }

    public static function atWorkingDirectory(Composer $composer, IOInterface $io): self
    {
        $jsonPath = Factory::getComposerFile();
        $real = \realpath($jsonPath);
        $root = \dirname(false === $real ? $jsonPath : $real);
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

        // What the vendor directory holds, which is what says whether a
        // patch manager this reader does not handle is in the site.
        $vendor = [];
        foreach ($composer->getRepositoryManager()->getLocalRepository()->getPackages() as $package) {
            $vendor[] = $package->getName();
        }

        // What the site requires, so a candidate can be resolved inside
        // the constraint rather than past it.
        $constraints = [];
        foreach ([$composer->getPackage()->getRequires(), $composer->getPackage()->getDevRequires()] as $set) {
            foreach ($set as $name => $link) {
                $constraints[$name] = $link->getConstraint()->getPrettyString();
            }
        }

        // What the service can judge decides the whole request: the two
        // documents, the patches resolved, and the candidates asked for.
        $request = Client::filter($json, $lock);
        $budget = \max(0, self::BODY_LIMIT - self::ENVELOPE_BYTES
            - \strlen(\json_encode($request['json'], \JSON_THROW_ON_ERROR))
            - \strlen(\json_encode($request['lock'], \JSON_THROW_ON_ERROR)));
        $patches = PatchConfig::read(
            $root,
            PatchText::fromComposer($composer, $io, $root),
            $budget,
            $request['packages'],
            $composer->getPackage()->getExtra(),
            $vendor,
        );

        return new self(
            $root,
            $request['json'],
            $request['lock'],
            $request['packages'],
            $request['locked'],
            $patches,
            \array_intersect_key($constraints, $request['packages']),
        );
    }

    /**
     * The directory holding composer.json: where a re-rolled patch is written, and the only tree the plugin touches.
     */
    public function root(): string
    {
        return $this->root;
    }

    /**
     * The composer.json the service receives.
     */
    public function composerJson(): string
    {
        return $this->composerJson;
    }

    /**
     * The composer.lock the service receives: a name and a version per checkable package.
     */
    public function composerLock(): string
    {
        return $this->composerLock;
    }

    /**
     * Packages the service can judge, to the versions the lock installs.
     *
     * @return array<string, string>
     */
    public function checkable(): array
    {
        return $this->checkable;
    }

    /**
     * Every package the lock names, to the version it pins, so a package the service cannot judge can still be named with its release.
     *
     * @return array<string, string>
     */
    public function installed(): array
    {
        return $this->installed;
    }

    /**
     * The site's own requirement for each checkable package, keyed by composer name.
     *
     * @return array<string, string>
     */
    public function constraints(): array
    {
        return $this->constraints;
    }

    public function patches(): PatchConfig
    {
        return $this->patches;
    }

    public function hasPatches(): bool
    {
        return !$this->patches->isEmpty();
    }
}

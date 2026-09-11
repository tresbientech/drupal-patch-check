<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Read;

use Composer\Composer;
use Composer\Factory;
use Composer\IO\IOInterface;
use RuntimeException;
use TresBienTech\Drupatch\Fetch\PatchText;
use TresBienTech\Drupatch\Manager;
use TresBienTech\Drupatch\Service\Client;

/**
 * The two composer files of one site, and the text of the patches it declares.
 */
class Site
{
    /** The body the service accepts for one scan. */
    private const BODY_LIMIT = 32 * 1024 * 1024;

    /** Room kept for the field names, the flags and the candidates. */
    private const ENVELOPE_BYTES = 64 * 1024;

    private function __construct(
        /** The directory holding composer.json: where a re-rolled patch is written, and the only tree the plugin touches. */
        public readonly string $root,
        /** The composer.json the service receives. */
        public readonly string $composerJson,
        /** The composer.lock the service receives: a name and a version per checkable package. */
        public readonly string $composerLock,
        /**
         * Packages the service can judge, to the versions the lock installs.
         *
         * @var array<string, string>
         */
        public readonly array $checkable,
        /**
         * Every package the lock names, to the version it pins, so a package the service cannot judge can still be named with its release.
         *
         * @var array<string, string>
         */
        public readonly array $installed,
        public readonly PatchConfig $patches,
        /**
         * The site's own requirement for each checkable package, keyed by composer name.
         *
         * @var array<string, string>
         */
        public readonly array $constraints,
    ) {
    }

    /**
     * The directory holding the composer.json this run is about.
     */
    public static function rootDirectory(): string
    {
        $jsonPath = Factory::getComposerFile();
        $real = \realpath($jsonPath);

        return \dirname(false === $real ? $jsonPath : $real);
    }

    public static function atWorkingDirectory(Composer $composer, IOInterface $io, Manager $manager): self
    {
        $jsonPath = Factory::getComposerFile();
        $root = self::rootDirectory();
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

        // What the service can judge decides the whole request: the two
        // documents, the patches resolved, and the candidates asked for.
        // The declarations come from the file on disk, which a run that
        // rewrote them reads again.
        $decoded = \json_decode($json, true);
        if (!\is_array($decoded)) {
            throw new RuntimeException('composer.json is not readable JSON');
        }
        $extra = (array) ($decoded['extra'] ?? []);
        $request = Client::filter($json, $lock);
        $budget = \max(0, self::BODY_LIMIT - self::ENVELOPE_BYTES
            - \strlen(\json_encode($request['json'], \JSON_THROW_ON_ERROR))
            - \strlen(\json_encode($request['lock'], \JSON_THROW_ON_ERROR)));
        $patches = PatchConfig::read(
            PatchText::fromComposer($composer, $io, $root),
            $budget,
            $request['packages'],
            $extra,
            $root,
            $manager,
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
}

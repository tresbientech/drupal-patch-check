<?php

declare(strict_types=1);

namespace Tresbien\Drupatch;

use Composer\Composer;
use Composer\Factory;
use RuntimeException;
use Tresbien\Drupatch\PatchConfig\Reader;
use Tresbien\Drupatch\PatchConfig\Resolution;
use Tresbien\Drupatch\Plan\Value;

/**
 * The two composer files of one site, and the text of the patches it
 * declares.
 */
final class Site
{
    private function __construct(
        private readonly string $root,
        private readonly string $composerJson,
        private readonly string $composerLock,
        private readonly Resolution $patches,
    ) {
    }

    public static function atWorkingDirectory(Composer $composer): self
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

        $installed = [];
        foreach ($composer->getRepositoryManager()->getLocalRepository()->getPackages() as $package) {
            $installed[] = $package->getName();
        }

        $extra = Value::keyed($composer->getPackage()->getExtra());

        return new self($root, $json, $lock, (new Reader($root))->read($extra, $installed));
    }

    /**
     * The directory holding composer.json: where a re-rolled patch is
     * written, and the only tree the plugin touches.
     */
    public function root(): string
    {
        return $this->root;
    }

    public function composerJson(): string
    {
        return $this->composerJson;
    }

    public function composerLock(): string
    {
        return $this->composerLock;
    }

    public function patches(): Resolution
    {
        return $this->patches;
    }

    public function hasPatches(): bool
    {
        return !$this->patches->isEmpty();
    }
}

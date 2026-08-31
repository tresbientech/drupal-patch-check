<?php

declare(strict_types=1);

namespace Tresbien\Drupatch;

use Composer\Composer;
use Composer\Factory;
use RuntimeException;
use Tresbien\Drupatch\PatchConfig\Reader;
use Tresbien\Drupatch\PatchConfig\Resolution;
use Tresbien\Drupatch\Plan\Value;
use Tresbien\Drupatch\Request\Filtered;

/**
 * The two composer files of one site, and the text of the patches it
 * declares.
 */
final class Site
{
    /** The body the service accepts for one scan. */
    private const BODY_LIMIT = 32 * 1024 * 1024;

    /** Room kept for the field names, the flags and the candidates. */
    private const ENVELOPE_BYTES = 64 * 1024;

    /**
     * @param array<string, string> $constraints
     */
    private function __construct(
        private readonly string $root,
        private readonly Filtered $request,
        private readonly Resolution $patches,
        private readonly array $constraints,
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
        $request = Filtered::of($json, $lock);
        $reader = new Reader($root, self::textBudget($request), $request->packages);
        $constraints = \array_intersect_key($constraints, $request->packages);

        return new self($root, $request, $reader->read($extra, $installed), $constraints);
    }

    /**
     * What is left of the request once the two composer files are in it,
     * measured as they are escaped in the body. The service refuses a
     * larger one, so a patch beyond this is named rather than sent.
     */
    private static function textBudget(Filtered $request): int
    {
        $taken = \strlen(\json_encode($request->composerJson, \JSON_THROW_ON_ERROR))
            + \strlen(\json_encode($request->composerLock, \JSON_THROW_ON_ERROR));

        return \max(0, self::BODY_LIMIT - $taken - self::ENVELOPE_BYTES);
    }

    /**
     * The directory holding composer.json: where a re-rolled patch is
     * written, and the only tree the plugin touches.
     */
    public function root(): string
    {
        return $this->root;
    }

    /**
     * The composer.json the service receives: the five keys it reads, with
     * every package map narrowed to what it can judge.
     */
    public function composerJson(): string
    {
        return $this->request->composerJson;
    }

    /**
     * The composer.lock the service receives: a name and a version per
     * checkable package, which is the slim form it already accepts.
     */
    public function composerLock(): string
    {
        return $this->request->composerLock;
    }

    /**
     * Packages the service can judge, to the versions the lock installs.
     *
     * @return array<string, string>
     */
    public function checkable(): array
    {
        return $this->request->packages;
    }

    /**
     * The site's own requirement for each patched package, keyed by
     * composer name. A package the site does not require is left out.
     *
     * @return array<string, string>
     */
    public function constraints(): array
    {
        return $this->constraints;
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

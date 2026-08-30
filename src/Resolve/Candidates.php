<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Resolve;

use Composer\Composer;
use Composer\Package\PackageInterface;
use Composer\Repository\CompositeRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositorySet;
use Composer\Semver\Comparator;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Throwable;

/**
 * The release composer would install for each package, if the site moved
 * to a target core.
 *
 * The server answers this from a daily copy of drupal.org and one
 * constraint at a time. Here composer's own repositories are in reach,
 * with the site's stability rules, its platform and whatever private or
 * mirrored repository it configured, so the answer is the one an update
 * would actually produce.
 *
 * What composer's selector does not know is the target core: it picks the
 * newest release matching a constraint, and a release names the core it
 * supports in its own requirements. That filter is applied here.
 */
final class Candidates
{
    private function __construct(private readonly RepositorySet $set, private readonly string $phpVersion, private readonly bool $preferStable)
    {
    }

    /**
     * Builds a resolver over the site's own repositories, or null when
     * composer cannot offer them.
     */
    public static function forSite(Composer $composer): ?self
    {
        try {
            $root = $composer->getPackage();
            $set = new RepositorySet($root->getMinimumStability(), $root->getStabilityFlags());
            $set->addRepository(new CompositeRepository($composer->getRepositoryManager()->getRepositories()));

            return new self($set, self::phpVersion($composer), $root->getPreferStable());
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The release each package would move to for the target core, keyed
     * by composer name. A package with no such release is left out.
     *
     * @param array<string, string> $constraints package name to the site's requirement
     *
     * @return array<string, string>
     */
    public function forTarget(string $target, array $constraints): array
    {
        $out = [];
        foreach ($constraints as $name => $constraint) {
            $best = $this->best($name, $constraint, $target);
            if (null !== $best) {
                $out[$name] = $best->getPrettyVersion();
            }
        }

        return $out;
    }

    /**
     * The newest release of one package that the site could install and
     * that supports the target core.
     */
    private function best(string $name, string $constraint, string $target): ?PackageInterface
    {
        try {
            $parser = new VersionParser();
            $found = $this->set->findPackages($name, '' === $constraint ? null : $parser->parseConstraints($constraint));
        } catch (Throwable) {
            return null;
        }
        $best = null;
        $bestStable = null;
        foreach ($found as $package) {
            if (!$this->supports($package, $target) || !$this->runnable($package)) {
                continue;
            }
            if (null === $best || Comparator::greaterThan($package->getVersion(), $best->getVersion())) {
                $best = $package;
            }
            if ('stable' === $package->getStability()
                && (null === $bestStable || Comparator::greaterThan($package->getVersion(), $bestStable->getVersion()))) {
                $bestStable = $package;
            }
        }

        return $this->preferStable ? ($bestStable ?? $best) : $best;
    }

    /**
     * Whether a release says it supports the target core.
     */
    private function supports(PackageInterface $package, string $target): bool
    {
        $requires = $package->getRequires();
        if (!isset($requires['drupal/core'])) {
            return false;
        }
        try {
            return Semver::satisfies($target, $requires['drupal/core']->getConstraint()->getPrettyString());
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Whether the site's PHP satisfies what the release asks for.
     */
    private function runnable(PackageInterface $package): bool
    {
        $requires = $package->getRequires();
        if ('' === $this->phpVersion || !isset($requires['php'])) {
            return true;
        }
        try {
            return Semver::satisfies($this->phpVersion, $requires['php']->getConstraint()->getPrettyString());
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * The PHP the site runs, as its platform config declares it or as the
     * process reports it.
     */
    private static function phpVersion(Composer $composer): string
    {
        $platform = $composer->getConfig()->get('platform');
        if (\is_string($platform['php'] ?? null)) {
            return $platform['php'];
        }
        $php = (new PlatformRepository())->findPackage('php', '*');

        return null === $php ? '' : $php->getPrettyVersion();
    }
}

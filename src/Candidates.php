<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch;

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
 * The release composer would install for each package, if the site moved to a target core.
 */
class Candidates
{
    /** Where a site declares the core it runs, newest convention first. */
    private const CORE_REQUIREMENTS = ['drupal/core-recommended', 'drupal/core'];

    /** @var list<string> */
    private array $notes = [];

    private function __construct(private readonly RepositorySet $set, private readonly string $phpVersion, private readonly bool $preferStable)
    {
    }

    /**
     * Builds a resolver over the site's own repositories.
     */
    public static function forSite(Composer $composer): self
    {
        $root = $composer->getPackage();
        $set = new RepositorySet($root->getMinimumStability(), $root->getStabilityFlags());
        $set->addRepository(new CompositeRepository($composer->getRepositoryManager()->getRepositories()));

        return new self($set, self::phpVersion($composer), $root->getPreferStable());
    }

    /**
     * What each installed package requires of core, read from the site's own vendor directory, keyed by composer name.
     *
     * @param array<string, string> $packages the packages worth asking about
     *
     * @return array<string, string>
     */
    public static function declaredCore(Composer $composer, array $packages): array
    {
        $out = [];
        foreach ($composer->getRepositoryManager()->getLocalRepository()->getPackages() as $package) {
            $name = $package->getName();
            if (!isset($packages[$name])) {
                continue;
            }
            $requires = $package->getRequires();
            if (isset($requires['drupal/core'])) {
                $out[$name] = $requires['drupal/core']->getConstraint()->getPrettyString();
            }
        }

        return $out;
    }

    /**
     * What this resolver could not answer, in the order it came up.
     *
     * @return list<string>
     */
    public function notes(): array
    {
        return $this->notes;
    }

    /**
     * The release each package would move to for the target core, keyed by composer name.
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
     * The newest core release the site's own constraint allows, or an empty string when it requires no core package.
     *
     * @param array<string, string> $constraints the site's own requirements
     */
    public function coreTarget(array $constraints): string
    {
        foreach (self::CORE_REQUIREMENTS as $name) {
            $constraint = $constraints[$name] ?? '';
            if ('' === $constraint) {
                continue;
            }
            $best = null;
            try {
                $found = $this->set->findPackages('drupal/core', (new VersionParser())->parseConstraints($constraint));
            } catch (Throwable $e) {
                $this->notes[] = \sprintf('no core release found for %s %s: %s', $name, $constraint, $e->getMessage());
                continue;
            }
            foreach ($found as $package) {
                if ('stable' !== $package->getStability()) {
                    continue;
                }
                if (null === $best || Comparator::greaterThan($package->getVersion(), $best->getVersion())) {
                    $best = $package;
                }
            }
            if (null !== $best) {
                return $best->getPrettyVersion();
            }
        }

        return '';
    }

    /**
     * The newest release of one package that the site could install and that supports the target core.
     *
     * A release qualifies when it says it supports the target core and
     * the site's PHP satisfies what it asks for. A requirement that will
     * not parse is unread (null), never a no: the release is left out
     * and the reason is noted once.
     */
    private function best(string $name, string $constraint, string $target): ?PackageInterface
    {
        try {
            $parser = new VersionParser();
            $found = $this->set->findPackages($name, '' === $constraint ? null : $parser->parseConstraints($constraint));
        } catch (Throwable $e) {
            $this->notes[] = \sprintf('no release found for %s %s: %s', $name, '' === $constraint ? '*' : $constraint, $e->getMessage());

            return null;
        }
        $best = null;
        $bestStable = null;
        $unread = false;
        foreach ($found as $package) {
            $supports = $this->supports($package, $target);
            $runnable = $this->runnable($package);
            $reason = match (null) {
                $supports => 'its drupal/core requirement could not be read',
                $runnable => 'its php requirement could not be read',
                default => '',
            };
            if (true !== $supports || true !== $runnable) {
                if ('' !== $reason && !$unread) {
                    $unread = true;
                    $this->notes[] = \sprintf('%s %s was left out: %s', $name, $package->getPrettyVersion(), $reason);
                }
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
     * Whether a release says it supports the target core; null when its requirement could not be read.
     */
    private function supports(PackageInterface $package, string $target): ?bool
    {
        $requires = $package->getRequires();
        if (!isset($requires['drupal/core'])) {
            return false;
        }
        try {
            return Semver::satisfies($target, $requires['drupal/core']->getConstraint()->getPrettyString());
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether the site's PHP satisfies what the release asks for; null when its requirement could not be read.
     */
    private function runnable(PackageInterface $package): ?bool
    {
        $requires = $package->getRequires();
        if ('' === $this->phpVersion || !isset($requires['php'])) {
            return true;
        }
        try {
            return Semver::satisfies($this->phpVersion, $requires['php']->getConstraint()->getPrettyString());
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The PHP the site runs, as its platform config declares it or as the process reports it.
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

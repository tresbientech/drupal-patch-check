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
 *
 * Nothing here answers "no" for a question it could not read. A failure
 * is either thrown, for the caller to report, or recorded in `notes()`.
 */
final class Candidates
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
     *
     * Throws when composer cannot offer them. A site whose repositories
     * are out of reach is not a site with nothing to install, and the
     * caller is where the difference can be said out loud.
     */
    public static function forSite(Composer $composer): self
    {
        $root = $composer->getPackage();
        $set = new RepositorySet($root->getMinimumStability(), $root->getStabilityFlags());
        $set->addRepository(new CompositeRepository($composer->getRepositoryManager()->getRepositories()));

        return new self($set, self::phpVersion($composer), $root->getPreferStable());
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
     * The newest core release the site's own constraint allows, or an
     * empty string when it requires no core package.
     *
     * `latest` is a question, not a version. Asking composer which release
     * of a package supports "latest" compares a constraint against a word
     * and answers no for everything, so the target is resolved to a
     * release first and the word never reaches a comparison.
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
                $this->notes[] = \sprintf('no core release was read for %s %s: %s', $name, $constraint, $e->getMessage());
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
     * The newest release of one package that the site could install and
     * that supports the target core.
     */
    private function best(string $name, string $constraint, string $target): ?PackageInterface
    {
        try {
            $parser = new VersionParser();
            $found = $this->set->findPackages($name, '' === $constraint ? null : $parser->parseConstraints($constraint));
        } catch (Throwable $e) {
            $this->notes[] = \sprintf('no candidate was read for %s %s: %s', $name, '' === $constraint ? '*' : $constraint, $e->getMessage());

            return null;
        }
        $best = null;
        $bestStable = null;
        $unread = false;
        foreach ($found as $package) {
            $eligibility = Eligibility::of($this->supports($package, $target), $this->runnable($package));
            if (!$eligibility->keep) {
                if ('' !== $eligibility->reason && !$unread) {
                    $unread = true;
                    $this->notes[] = \sprintf('%s %s was left out: %s', $name, $package->getPrettyVersion(), $eligibility->reason);
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
     * Whether a release says it supports the target core.
     *
     * A release naming no core requirement has answered: it does not.
     * A requirement that will not parse has not.
     */
    private function supports(PackageInterface $package, string $target): Answer
    {
        $requires = $package->getRequires();
        if (!isset($requires['drupal/core'])) {
            return Answer::No;
        }
        try {
            return Answer::of(Semver::satisfies($target, $requires['drupal/core']->getConstraint()->getPrettyString()));
        } catch (Throwable) {
            return Answer::Unread;
        }
    }

    /**
     * Whether the site's PHP satisfies what the release asks for.
     *
     * A release asking for no PHP, or a site whose PHP is unknown,
     * leaves nothing to refuse.
     */
    private function runnable(PackageInterface $package): Answer
    {
        $requires = $package->getRequires();
        if ('' === $this->phpVersion || !isset($requires['php'])) {
            return Answer::Yes;
        }
        try {
            return Answer::of(Semver::satisfies($this->phpVersion, $requires['php']->getConstraint()->getPrettyString()));
        } catch (Throwable) {
            return Answer::Unread;
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

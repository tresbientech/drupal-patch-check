<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Resolve;

use Composer\Composer;
use Composer\Package\PackageInterface;

/**
 * The core requirement each installed release declares.
 *
 * The service answers this from a copy of drupal.org's release data, which
 * can be months behind a project. The site has the release itself on disk,
 * and its `drupal/core` requirement is the same value drupal.org publishes
 * as the release's core compatibility. Reading it here makes the verdict
 * independent of how fresh any copy is.
 */
final class Declared
{
    /**
     * What each installed package requires of core, keyed by composer
     * name. A package that requires no core is left out: the site has
     * nothing to say about it.
     *
     * @param array<string, string> $packages the packages worth asking about
     *
     * @return array<string, string>
     */
    public static function forSite(Composer $composer, array $packages): array
    {
        $out = [];
        foreach ($composer->getRepositoryManager()->getLocalRepository()->getPackages() as $package) {
            $name = $package->getName();
            if (!isset($packages[$name])) {
                continue;
            }
            $core = self::coreRequirement($package);
            if ('' !== $core) {
                $out[$name] = $core;
            }
        }

        return $out;
    }

    /**
     * The `drupal/core` constraint one installed release declares.
     */
    private static function coreRequirement(PackageInterface $package): string
    {
        $requires = $package->getRequires();
        if (!isset($requires['drupal/core'])) {
            return '';
        }

        return $requires['drupal/core']->getConstraint()->getPrettyString();
    }
}

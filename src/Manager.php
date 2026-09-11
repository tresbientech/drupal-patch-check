<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch;

use Composer\Composer;

/**
 * The patch manager a site installed, and every answer that depends on which line of it is there.
 */
class Manager
{
    /** The one patch manager drupatch reads the config of. */
    public const PACKAGE = 'cweagans/composer-patches';

    /** The run that moves a site from 1.x to 2.x, named by anything that refuses on 1.x. */
    public const UPGRADE = 'drupatch:upgrade-patch-manager';

    /** Where 2.x reads the file holding the patches. */
    public const FILE_KEY_TWO = ['composer-patches', 'patches-file'];

    /** Where 1.x reads it. */
    public const FILE_KEY_ONE = ['patches-file'];

    private function __construct(
        /** The line the site is on: 1, 2, or 0 when it has no patch manager. */
        public readonly int $line,
        /** The release the lock pins, empty when the site installs none. */
        public readonly string $version,
    ) {
    }

    /**
     * Reads the release the lock pins, which is what composer installs.
     */
    public static function fromComposer(Composer $composer): self
    {
        $locker = $composer->getLocker();
        $package = $locker->isLocked() ? $locker->getLockedRepository(true)->findPackage(self::PACKAGE, '*') : null;

        return self::ofVersion(null === $package ? '' : $package->getPrettyVersion());
    }

    /**
     * The line one version string names. Anything that is not a 1 or a 2 reads as no manager.
     */
    public static function ofVersion(string $version): self
    {
        $version = \trim($version);

        return new self(1 === \preg_match('/^([12])\./', \ltrim($version, 'v'), $found) ? (int) $found[1] : 0, $version);
    }

    public function isOne(): bool
    {
        return 1 === $this->line;
    }

    public function isTwo(): bool
    {
        return 2 === $this->line;
    }

    /**
     * The patches file this site's manager reads, relative to the site root; empty when it reads none.
     *
     * A site with no manager is read as a 2.x site, so a file written the
     * recommended way is found before the manager it was written for is there.
     *
     * @param array<string, mixed> $extra the root package's extra
     */
    public function patchesFile(array $extra): string
    {
        $keys = match ($this->line) {
            1 => [self::FILE_KEY_ONE],
            2 => [self::FILE_KEY_TWO],
            default => [self::FILE_KEY_TWO, self::FILE_KEY_ONE],
        };
        foreach ($keys as $key) {
            $value = $extra;
            foreach ($key as $segment) {
                $value = \is_array($value) ? ($value[$segment] ?? null) : null;
            }
            if (\is_string($value) && '' !== \trim($value)) {
                return \trim($value);
            }
        }

        return '';
    }
}

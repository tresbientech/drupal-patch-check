<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch;

/**
 * What a **manager upgrade** changes about a site's settings.
 *
 * 1.x reads its keys straight under `extra`. 2.x reads them under
 * `extra.composer-patches`, renames one, and reads three no more.
 */
class Settings
{
    /** Where 2.x keeps every setting of its own. */
    public const KEY = 'composer-patches';

    /** 1.x key to the 2.x key that replaces it, both without their prefix. */
    public const RENAMED = [
        'patches-ignore' => 'ignore-dependency-patches',
        'patches-file' => 'patches-file',
    ];

    /** 1.x key to why 2.x has nothing to move it to. */
    public const DROPPED = [
        'patchLevel' => 'a measured depth replaces it',
        'enable-patching' => '2.x reads it no more',
        'composer-exit-on-patch-failure' => '2.x always stops on a patch that fails',
    ];

    /**
     * The depth 2.x applies a package's patches at when nothing names one.
     *
     * The plugin's own `Util::getDefaultPackagePatchDepth` holds this list;
     * a depth equal to it is a depth worth leaving out.
     */
    public const DEFAULT_DEPTHS = ['drupal/core' => 2];

    /** The depth 2.x uses for a package its own list does not name. */
    public const DEFAULT_DEPTH = 1;

    /**
     * Whether this site's composer.json already holds the block the move writes.
     *
     * The lock still pins 1.x until composer installs the raised requirement,
     * so a second run reads the settings rather than the version.
     *
     * @param array<string, mixed> $extra the root package's extra
     */
    public static function moved(array $extra): bool
    {
        return isset($extra[self::KEY]);
    }

    /**
     * The settings this site carries that the move renames, as 1.x key to 2.x key.
     *
     * @param array<string, mixed> $extra the root package's extra
     *
     * @return array<string, string>
     */
    public static function renamed(array $extra): array
    {
        $out = [];
        foreach (self::RENAMED as $from => $to) {
            if (isset($extra[$from])) {
                $out[$from] = $to;
            }
        }

        return $out;
    }

    /**
     * The settings this site carries that the move drops, as key to the reason.
     *
     * @param array<string, mixed> $extra the root package's extra
     *
     * @return array<string, string>
     */
    public static function dropped(array $extra): array
    {
        return \array_intersect_key(self::DROPPED, $extra);
    }

    /**
     * The depth to record for one patch, null when 2.x already applies it there.
     *
     * 1.x guesses a level per patch. 2.x applies at the depth the definition
     * names, then the one its package defaults to, so a measured level that
     * matches the default is nothing to write down.
     */
    public static function depthFor(string $package, ?int $appliesAt): ?int
    {
        $default = self::DEFAULT_DEPTHS[$package] ?? self::DEFAULT_DEPTH;

        return null === $appliesAt || $appliesAt === $default ? null : $appliesAt;
    }
}

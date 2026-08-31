<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Write;

use RuntimeException;
use TresBienTech\Drupatch\PatchConfig;

/**
 * Reads the conflict regions a person decided, for every patch a site declares.
 */
class Decisions
{
    /** Marker prefixes that mean a region was not decided. */
    private const MARKERS = ['<<<<<<< ', '=======', '>>>>>>> '];

    /**
     * Regions decided on disk, keyed by the patch's position in the site's declarations, each in the shape the service reads.
     *
     * @param list<array{package: string, title: string, source: string}> $patches
     * @param list<string>                                                $packages narrows the read; empty reads them all
     *
     * @return array<int, list<array{file: string, region: int, text?: string, delete?: bool}>>
     */
    public static function onDisk(string $root, array $patches, array $packages): array
    {
        $out = [];
        foreach ($patches as $i => $patch) {
            if (!self::inScope($patch['package'], $packages)) {
                continue;
            }
            $path = self::conflictFile($patch['package'], $patch['source']);
            if (null === $path) {
                continue;
            }
            $text = @\file_get_contents($root.\DIRECTORY_SEPARATOR.$path);
            if (false === $text) {
                continue;
            }
            $regions = self::read($text, $path);
            if ([] !== $regions) {
                $out[$i] = $regions;
            }
        }

        return $out;
    }

    /**
     * The regions one conflict file decides. Throws on sentinels that no longer pair up.
     *
     * @return list<array{file: string, region: int, text?: string, delete?: bool}>
     */
    public static function read(string $text, string $path): array
    {
        $out = [];
        $open = null;
        $body = [];
        foreach (\explode("\n", $text) as $i => $line) {
            $at = $i + 1;
            $region = self::sentinel($line, PatchFiles::REGION_OPEN);
            if (null !== $region) {
                if (null !== $open) {
                    throw self::unreadable($path, $at, 'a region opens inside another');
                }
                $open = $region;
                $body = [];

                continue;
            }
            $end = self::sentinel($line, PatchFiles::REGION_CLOSE);
            if (null !== $end) {
                if (null === $open) {
                    throw self::unreadable($path, $at, 'a region ends without opening');
                }
                if ($end !== $open) {
                    throw self::unreadable($path, $at, 'a region ends under another name');
                }
                if (!self::holdsMarkers($body)) {
                    $decided = \rtrim(\implode("\n", $body), "\n");
                    $out[] = ['file' => $open['file'], 'region' => $open['region']]
                        + ('' === $decided ? ['delete' => true] : ['text' => $decided]);
                }
                $open = null;

                continue;
            }
            if (null !== $open) {
                $body[] = $line;
            }
        }
        if (null !== $open) {
            throw self::unreadable($path, \substr_count($text, "\n") + 1, 'a region never ends');
        }

        return $out;
    }

    private static function unreadable(string $path, int $line, string $what): RuntimeException
    {
        return new RuntimeException(\sprintf('%s line %d: %s', $path, $line, $what));
    }

    /**
     * The region a sentinel line names, or null when the line is not one.
     *
     * @return array{region: int, file: string}|null
     */
    private static function sentinel(string $line, string $prefix): ?array
    {
        if (!\str_starts_with($line, $prefix)) {
            return null;
        }
        $rest = \substr($line, \strlen($prefix));
        $space = \strpos($rest, ' ');
        if (false === $space) {
            return null;
        }
        $index = \substr($rest, 0, $space);
        if (1 !== \preg_match('/^\d+$/', $index)) {
            return null;
        }

        return ['region' => (int) $index, 'file' => \substr($rest, $space + 1)];
    }

    /**
     * @param list<string> $body
     */
    private static function holdsMarkers(array $body): bool
    {
        foreach ($body as $line) {
            foreach (self::MARKERS as $marker) {
                if (\str_starts_with($line, $marker)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The conflict file this declaration's re-roll would have been written to, or null when the declaration names no file under the site root.
     */
    private static function conflictFile(string $package, string $source): ?string
    {
        $declared = PatchConfig::isUrl($source) ? PatchFiles::adoptedPath($package, '', $source) : $source;
        if ('' === $declared) {
            return null;
        }
        $inside = PatchFiles::inside($declared);

        return null === $inside ? null : PatchFiles::conflictPath($inside);
    }

    /**
     * @param list<string> $packages
     */
    private static function inScope(string $package, array $packages): bool
    {
        if ([] === $packages) {
            return true;
        }
        $short = \str_replace('drupal/', '', $package);
        foreach ($packages as $wanted) {
            if ($wanted === $package || $wanted === $short) {
                return true;
            }
        }

        return false;
    }
}

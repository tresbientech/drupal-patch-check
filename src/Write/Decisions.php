<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Write;

use RuntimeException;
use TresBienTech\Drupatch\PatchConfig;
use TresBienTech\Drupatch\Scope;

/**
 * Reads the conflict regions a person decided, for every patch a site declares.
 */
class Decisions
{
    /** Marker prefixes that mean a region was not decided. */
    private const MARKERS = ['<<<<<<< ', '=======', '>>>>>>> '];

    /** The sides a decision can take, as the service names them. */
    private const CHOICES = ['release', 'patch'];

    /**
     * Regions decided on disk, keyed by the patch's position in the site's declarations, each in the shape the service reads.
     *
     * @param list<array{package: string, title: string, source: string}> $patches
     *
     * @return array<int, list<array{file: string, region: int, text?: string, delete?: bool}>>
     */
    public static function onDisk(string $root, array $patches, Scope $scope): array
    {
        $out = [];
        foreach ($patches as $i => $patch) {
            if (!$scope->has($patch['package'], $patch['source'])) {
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
     * Regions decided in a document, keyed like onDisk(). The document is one object holding a `decisions` list; each entry names a patch by its declared source, a file, a region index, and either a `choice` of `release` or `patch` or the `text` to put there.
     *
     * @param list<array{package: string, title: string, source: string}> $patches
     *
     * @throws RuntimeException on a source the site does not declare in scope, or an entry the service would refuse
     *
     * @return array<int, list<array{file: string, region: int, choice?: string, text?: string}>>
     */
    public static function fromDocument(string $json, array $patches, Scope $scope): array
    {
        $decoded = \json_decode($json, true);
        if (!\is_array($decoded) || !\is_array($decoded['decisions'] ?? null)) {
            throw new RuntimeException('the decisions document is one JSON object holding a "decisions" list');
        }
        $bySource = [];
        foreach ($patches as $i => $patch) {
            if ($scope->has($patch['package'], $patch['source'])) {
                $bySource[$patch['source']] = $i;
            }
        }
        $out = [];
        foreach (\array_values($decoded['decisions']) as $n => $entry) {
            $entry = \is_array($entry) ? $entry : [];
            $source = $entry['source'] ?? null;
            if (!\is_string($source) || !isset($bySource[$source])) {
                throw new RuntimeException(\sprintf('decision %d names %s, which is not a patch declared in scope; the site declares %s', $n + 1, \is_string($source) ? $source : 'no source', [] === $bySource ? 'none' : \implode(', ', \array_keys($bySource))));
            }
            $file = $entry['file'] ?? null;
            $region = $entry['region'] ?? null;
            if (!\is_string($file) || '' === $file || !\is_int($region) || $region < 0) {
                throw new RuntimeException(\sprintf('decision %d for %s needs the file and the region index the conflict reported', $n + 1, $source));
            }
            $decided = ['file' => $file, 'region' => $region];
            $text = $entry['text'] ?? null;
            $choice = $entry['choice'] ?? null;
            if (\is_string($text)) {
                $decided['text'] = $text;
            } elseif (\is_string($choice) && \in_array($choice, self::CHOICES, true)) {
                $decided['choice'] = $choice;
            } else {
                throw new RuntimeException(\sprintf('decision %d for %s %s:%d needs a choice of release or patch, or a text', $n + 1, $source, $file, $region));
            }
            $out[$bySource[$source]][] = $decided;
        }

        return $out;
    }

    /**
     * Both sources of decisions as one. Where the document names a region a conflict file also decided, the document wins and the region is listed.
     *
     * @param array<int, list<array<string, mixed>>> $onDisk
     * @param array<int, list<array<string, mixed>>> $document
     *
     * @return array{decided: array<int, list<array<string, mixed>>>, overridden: list<array{patch: int, file: string, region: int}>}
     */
    public static function merge(array $onDisk, array $document): array
    {
        $decided = $onDisk;
        $overridden = [];
        foreach ($document as $i => $entries) {
            foreach ($entries as $entry) {
                $kept = [];
                foreach ($decided[$i] ?? [] as $existing) {
                    if ($existing['file'] === $entry['file'] && $existing['region'] === $entry['region']) {
                        $overridden[] = ['patch' => $i, 'file' => $entry['file'], 'region' => $entry['region']];
                        continue;
                    }
                    $kept[] = $existing;
                }
                $kept[] = $entry;
                $decided[$i] = $kept;
            }
        }

        return ['decided' => $decided, 'overridden' => $overridden];
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
}

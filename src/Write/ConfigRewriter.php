<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Write;

use Composer\Json\JsonManipulator;
use RuntimeException;
use TresBienTech\Drupatch\Plan\PatchRow;
use TresBienTech\Drupatch\Plan\Plan;
use TresBienTech\Drupatch\Read\PatchConfig;
use TresBienTech\Drupatch\Source\Provenance;
use TresBienTech\Drupatch\Text;

/**
 * Rewrites a site's patch declarations from a plan.
 */
class ConfigRewriter
{
    /** A declaration the site did not hold, which an add run writes. */
    public const ADDED = 'added';

    /** A declaration that now names another file. */
    public const REPOINTED = 'repointed';

    /** A declaration the release made unneeded. */
    public const DROPPED = 'dropped';

    /**
     * Decides what changes: one entry per declaration the plan settles.
     *
     * @param list<array{path: string, provenance: array<string, string>, status: string, package: string, title: string, verified: bool, unioned: list<array{file: string, line: int}>, regions: int}> $written
     *
     * @return list<array{action: 'dropped'|'repointed', package: string, title: string, path: string, provenance: array<string, string>}>
     */
    public static function changes(Plan $plan, array $written): array
    {
        $files = [];
        foreach ($written as $file) {
            if ('clean' === $file['status']) {
                $files[PatchRow::keyOf($file['package'], $file['title'])] = $file;
            }
        }

        $changes = [];
        foreach ($plan->patches as $row) {
            if ($row->isMerged()) {
                $left = PatchConfig::isUrl($row->source) ? '' : $row->source;
                $changes[] = ['action' => self::DROPPED, 'package' => $row->package, 'title' => $row->title, 'path' => $left, 'provenance' => []];
                continue;
            }
            $file = $files[$row->key()] ?? null;
            // A re-roll written over the file the entry already names
            // changes the file, never the entry.
            if ($row->conflicts() && null !== $file && $file['path'] !== $row->source) {
                $changes[] = ['action' => self::REPOINTED, 'package' => $row->package, 'title' => $row->title, 'path' => $file['path'], 'provenance' => $file['provenance']];
            }
        }

        return $changes;
    }

    /**
     * Groups the changes by the file that held each entry, composer.json first. A change whose entry no declaration names belongs to composer.json.
     *
     * @param list<array{action: string, package: string, title: string, path: string, provenance: array<string, string>}> $changes
     * @param list<array{package: string, title: string, source: string, file: string, shape: string}>                     $declarations
     *
     * @return array<string, list<array{action: string, package: string, title: string, path: string, provenance: array<string, string>}>>
     */
    public static function byFile(array $changes, array $declarations): array
    {
        $held = [];
        foreach ($declarations as $declaration) {
            $held[PatchRow::keyOf($declaration['package'], $declaration['title'])] = $declaration['file'];
        }
        $out = [];
        foreach ($changes as $change) {
            $out[$held[PatchRow::keyOf($change['package'], $change['title'])] ?? PatchConfig::COMPOSER_JSON][] = $change;
        }
        \uksort($out, static fn (int|string $a, int|string $b): int => [PatchConfig::COMPOSER_JSON !== $a, (string) $a] <=> [PatchConfig::COMPOSER_JSON !== $b, (string) $b]);

        return $out;
    }

    /**
     * Applies the changes to a declaration map, keeping the order the site wrote it in. An entry no change names is kept as it stands.
     *
     * @param array<string, mixed>                                                                                         $patches
     * @param list<array{action: string, package: string, title: string, path: string, provenance: array<string, string>}> $changes
     *
     * @return array<string, mixed>
     */
    public static function apply(array $patches, array $changes): array
    {
        $byEntry = [];
        $added = [];
        foreach ($changes as $change) {
            if (self::ADDED === $change['action']) {
                $added[$change['package']][$change['title']] = [null, $change];
                continue;
            }
            $byEntry[PatchRow::keyOf($change['package'], $change['title'])] = $change;
        }

        $out = [];
        foreach ($patches as $package => $entries) {
            if (!\is_array($entries)) {
                $out[$package] = $entries;
                continue;
            }
            $kept = [];
            foreach ($entries as $title => $entry) {
                $name = \is_array($entry) ? ($entry[PatchConfig::TITLE_KEY] ?? null) : $title;
                $key = \is_string($name) ? PatchRow::keyOf($package, $name) : '';
                $change = $byEntry[$key] ?? null;
                unset($byEntry[$key]);
                if (null !== $change && self::DROPPED === $change['action']) {
                    continue;
                }
                $kept[$title] = [$entry, $change];
            }
            // A patch manager applies a package's patches in declaration
            // order, so a new one goes last.
            $kept += $added[$package] ?? [];
            unset($added[$package]);
            if ([] !== $kept) {
                $out[$package] = self::shaped($entries, $kept);
            }
        }
        foreach ($added as $package => $kept) {
            $out[$package] = self::shaped([], $kept);
        }
        // Every change comes from a declaration the run read, so one that
        // matched no entry pairs a row with the wrong declaration.
        $unmatched = \reset($byEntry);
        if (false !== $unmatched) {
            throw new RuntimeException(Text::t('no declaration of @package is titled @title, so nothing was rewritten', ['@package' => $unmatched['package'], '@title' => $unmatched['title']]));
        }

        return $out;
    }

    /**
     * What one package declares after the changes, in the shape it was written in. A record moves the package to the expanded shape, because 2.x reads a package's shape from its first entry.
     *
     * @param array<int|string, mixed>                                                                                           $entries the package as the site wrote it
     * @param array<int|string, array{0: mixed, 1: array{action: string, path: string, provenance: array<string, string>}|null}> $kept    the entries that survived, each with the change that settled it
     *
     * @return array<int|string, mixed>
     */
    private static function shaped(array $entries, array $kept): array
    {
        $expanded = \array_is_list($entries);
        foreach ($kept as [, $change]) {
            $expanded = $expanded || (null !== $change && [] !== $change['provenance']);
        }
        $out = [];
        foreach ($kept as $title => [$entry, $change]) {
            $definition = self::definition($entry, $change, $expanded, (string) $title);
            if ($expanded) {
                // A dropped entry leaves a hole in the integer keys, which
                // JSON would then write as an object.
                $out[] = $definition;
                continue;
            }
            $out[$title] = $definition;
        }

        return $out;
    }

    /**
     * One entry in the shape asked for, pointed at what the run wrote and carrying its record.
     *
     * @param mixed                                                                       $entry  the entry the site wrote, null for one it did not hold
     * @param array{action: string, path: string, provenance: array<string, string>}|null $change
     */
    private static function definition(mixed $entry, ?array $change, bool $expanded, string $title): mixed
    {
        if (!$expanded) {
            return null === $change ? $entry : $change['path'];
        }
        $out = \is_array($entry) ? $entry : [PatchConfig::TITLE_KEY => $title, PatchConfig::SOURCE_KEY => $entry];
        if (null !== $change) {
            $out[PatchConfig::SOURCE_KEY] = $change['path'];
            if ([] !== $change['provenance']) {
                $out['extra'][Provenance::KEY] = $change['provenance'];
            }
        }

        return $out;
    }

    /**
     * Writes the new declarations into composer.json, leaving every other key, the key order and the file's indentation as they were.
     *
     * @param array<string, mixed> $patches
     */
    public static function intoComposerJson(string $text, array $patches): string
    {
        $manipulator = new JsonManipulator($text);
        if (!$manipulator->addSubNode('extra', 'patches', $patches)) {
            throw new RuntimeException('composer.json extra.patches could not be rewritten');
        }

        return $manipulator->getContents();
    }

    /**
     * Writes the new declarations into a patches file, whose whole subject is the `patches` key.
     *
     * @param array<string, mixed> $patches
     */
    public static function intoPatchesFile(string $text, array $patches): string
    {
        $manipulator = new JsonManipulator($text);
        if (!$manipulator->addMainKey('patches', $patches)) {
            throw new RuntimeException('the patches file could not be rewritten');
        }

        return $manipulator->getContents();
    }
}

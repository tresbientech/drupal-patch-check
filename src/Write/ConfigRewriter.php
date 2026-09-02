<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Write;

use Composer\Json\JsonManipulator;
use RuntimeException;
use TresBienTech\Drupatch\PatchConfig;
use TresBienTech\Drupatch\Plan\Plan;

/**
 * Rewrites a site's patch declarations from a plan.
 */
class ConfigRewriter
{
    /**
     * Decides what changes: one entry per declaration the plan settles.
     *
     * @param list<array{path: string, status: string, package: string, title: string, verified: bool, unioned: list<array{file: string, line: int}>}> $written
     *
     * @return list<array{action: 'dropped'|'repointed', package: string, title: string, path: string}>
     */
    public static function changes(Plan $plan, array $written): array
    {
        $files = [];
        foreach ($written as $file) {
            if ('clean' === $file['status']) {
                $files[$file['package']."\0".$file['title']] = $file['path'];
            }
        }

        $changes = [];
        foreach ($plan->patches as $row) {
            if ($row->isMerged()) {
                $left = PatchConfig::isUrl($row->source) ? '' : $row->source;
                $changes[] = ['action' => 'dropped', 'package' => $row->package, 'title' => $row->title, 'path' => $left];
                continue;
            }
            $path = $files[$row->key()] ?? '';
            // A re-roll written over the file the entry already names
            // changes the file, never the entry.
            if ($row->conflicts() && '' !== $path && $path !== $row->source) {
                $changes[] = ['action' => 'repointed', 'package' => $row->package, 'title' => $row->title, 'path' => $path];
            }
        }

        return $changes;
    }

    /**
     * The line the report prints for one change.
     *
     * @param array{action: string, package: string, title: string, path: string} $change
     */
    public static function line(array $change): string
    {
        if ('dropped' !== $change['action']) {
            return \sprintf('    ~ %s: %s → %s', $change['package'], $change['title'], $change['path']);
        }
        if ('' === $change['path']) {
            return \sprintf('    - %s: %s (already in the release)', $change['package'], $change['title']);
        }

        return \sprintf(
            '    - %s: %s (already in the release; %s is now unreferenced and was kept)',
            $change['package'],
            $change['title'],
            $change['path'],
        );
    }

    /**
     * Applies the changes to a declaration map, keeping the order the site wrote it in.
     *
     * @param array<string, mixed>                                                      $patches
     * @param list<array{action: string, package: string, title: string, path: string}> $changes
     *
     * @return array<string, mixed>
     */
    public static function apply(array $patches, array $changes): array
    {
        $byEntry = [];
        foreach ($changes as $change) {
            $byEntry[$change['package']."\0".$change['title']] = $change;
        }

        $out = [];
        foreach ($patches as $package => $entries) {
            if (!\is_array($entries)) {
                $out[$package] = $entries;
                continue;
            }
            $isList = \array_is_list($entries);
            $kept = [];
            foreach ($entries as $key => $entry) {
                $change = $byEntry[$package."\0".PatchConfig::entryTitle($key, $entry)] ?? null;
                if (null === $change) {
                    $kept[$key] = $entry;
                    continue;
                }
                if ('dropped' === $change['action']) {
                    continue;
                }
                $kept[$key] = PatchConfig::entryWithSource($entry, $change['path']);
            }
            if ([] !== $kept) {
                $out[$package] = $isList ? \array_values($kept) : $kept;
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
     * Writes the new declarations into an external patches file, keeping whichever of the two shapes the file uses.
     *
     * @param array<string, mixed> $patches
     */
    public static function intoPatchesFile(string $text, array $patches): string
    {
        $decoded = \json_decode($text, true);
        $body = \is_array($decoded) && isset($decoded['patches']) ? ['patches' => $patches] + $decoded : $patches;

        return \json_encode($body, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n";
    }
}

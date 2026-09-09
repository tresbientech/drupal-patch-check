<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Write;

use Composer\Json\JsonManipulator;
use RuntimeException;
use TresBienTech\Drupatch\PatchConfig;
use TresBienTech\Drupatch\Plan\PatchRow;
use TresBienTech\Drupatch\Plan\Plan;

/**
 * Rewrites a site's patch declarations from a plan.
 */
class ConfigRewriter
{
    /**
     * Decides what changes: one entry per declaration the plan settles.
     *
     * @param list<array{path: string, status: string, package: string, title: string, verified: bool, unioned: list<array{file: string, line: int}>, regions: int}> $written
     *
     * @return list<array{action: 'dropped'|'repointed', package: string, title: string, path: string}>
     */
    public static function changes(Plan $plan, array $written): array
    {
        $files = [];
        foreach ($written as $file) {
            if ('clean' === $file['status']) {
                $files[PatchRow::keyOf($file['package'], $file['title'])] = $file['path'];
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
     * Applies the changes to a declaration map, keeping the order the site wrote it in. An entry in a shape the reader does not read is kept as it stands.
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
            $kept = [];
            foreach ($entries as $title => $source) {
                $change = \is_string($title) ? ($byEntry[$package."\0".$title] ?? null) : null;
                if (null === $change) {
                    $kept[$title] = $source;
                    continue;
                }
                if ('dropped' === $change['action']) {
                    continue;
                }
                $kept[$title] = $change['path'];
            }
            if ([] !== $kept) {
                $out[$package] = $kept;
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
}

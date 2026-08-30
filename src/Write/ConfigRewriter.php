<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Write;

use Composer\Json\JsonManipulator;
use RuntimeException;
use Tresbien\Drupatch\PatchConfig\Entry;
use Tresbien\Drupatch\Plan\Plan;

/**
 * Rewrites a site's patch declarations from a plan.
 *
 * Two changes and no others: an entry the release already carries is
 * dropped, and an entry that no longer applies points at its re-rolled
 * file. A conflicted re-roll is never named, because that file holds
 * regions nobody has decided.
 */
final class ConfigRewriter
{
    /**
     * Decides what changes: one entry per declaration the plan settles.
     *
     * @param list<WrittenFile> $written
     *
     * @return list<Change>
     */
    public static function changes(Plan $plan, array $written): array
    {
        $files = [];
        foreach ($written as $file) {
            if ($file->isUsable()) {
                $files[$file->key()] = $file->path;
            }
        }

        $changes = [];
        foreach ($plan->patches as $row) {
            if ($row->isShipped()) {
                $changes[] = new Change(Change::DROPPED, $row->package, $row->title, '');
                continue;
            }
            $path = $files[$row->key()] ?? '';
            if ($row->needsReroll() && '' !== $path) {
                $changes[] = new Change(Change::REPOINTED, $row->package, $row->title, $path);
            }
        }

        return $changes;
    }

    /**
     * Applies the changes to a declaration map, keeping the order the
     * site wrote it in.
     *
     * @param array<string, mixed> $patches
     * @param list<Change>         $changes
     *
     * @return array<string, mixed>
     */
    public static function apply(array $patches, array $changes): array
    {
        $byEntry = [];
        foreach ($changes as $change) {
            $byEntry[$change->key()] = $change;
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
                $change = $byEntry[$package."\0".Entry::title($key, $entry)] ?? null;
                if (null === $change) {
                    $kept[$key] = $entry;
                    continue;
                }
                if ($change->isDrop()) {
                    continue;
                }
                $kept[$key] = Entry::withSource($entry, $change->path);
            }
            if ([] !== $kept) {
                $out[$package] = $isList ? \array_values($kept) : $kept;
            }
        }

        return $out;
    }

    /**
     * Writes the new declarations into composer.json, leaving every other
     * key, the key order and the file's indentation as they were.
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
     * Writes the new declarations into an external patches file, keeping
     * whichever of the two shapes the file uses.
     *
     * @param array<string, mixed> $patches
     */
    public static function intoPatchesFile(string $text, array $patches): string
    {
        $decoded = \json_decode($text, true);
        $body = \is_array($decoded) && isset($decoded['patches']) ? ['patches' => $patches] + $decoded : $patches;
        if (\is_array($decoded) && isset($decoded['patches'])) {
            $body['patches'] = $patches;
        }

        return \json_encode($body, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n";
    }
}

<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Write;

use RuntimeException;
use TresBienTech\Drupatch\Header;
use TresBienTech\Drupatch\PatchConfig;
use TresBienTech\Drupatch\PatchText;
use TresBienTech\Drupatch\Plan\PatchRow;
use TresBienTech\Drupatch\Plan\Plan;
use TresBienTech\Drupatch\Render\Report;
use TresBienTech\Drupatch\Text;

/**
 * Writes the re-rolled diffs a plan carries.
 *
 * @phpstan-import-type WrittenRow from \TresBienTech\Drupatch\Render\Outcomes
 */
class PatchFiles
{
    public const CONFLICT_SUFFIX = '.conflict.patch';

    /**
     * Sentinels around each region, carrying the file and the region index the service assigned.
     */
    public const REGION_OPEN = '# drupatch region ';

    public const REGION_CLOSE = '# drupatch end ';

    /** Extensions a conflict file replaces rather than keeps. */
    private const PATCH_EXTENSIONS = ['.patch', '.diff'];

    public const OUTSIDE_ROOT = 'its path points outside the site';

    public const NO_REROLL = 'the service sent no re-roll and no reason for it';

    public const URL_DECLARED = 'it is declared as a URL, so there is no file to replace; run `'.Report::PIN.'` to copy it into the site';

    public const NOT_DECLARED = 'the site declares no patch by that name';

    public function __construct(
        private readonly string $root,
        /** Asked before a file is replaced; null replaces everything. `--force`. */
        private readonly ?WorkingTree $tree,
        /**
         * The patches the site declares, which decide where a re-roll may land.
         *
         * @var list<array{package: string, title: string, source: string}>
         */
        private readonly array $declared,
    ) {
    }

    /**
     * The source the site declared for this row, null when it declared none.
     */
    private function declaredSource(PatchRow $row): ?string
    {
        foreach ($this->declared as $patch) {
            if ($patch['package'] === $row->package && $patch['title'] === $row->title) {
                return $patch['source'];
            }
        }

        return null;
    }

    /**
     * Writes one file per re-rolled patch and reports what happened.
     *
     * @return array{written: list<WrittenRow>,
     *               refused: list<array{package: string, title: string, path: string, reason: string, lifts: string, shipped: bool}>}
     */
    public function write(Plan $plan): array
    {
        $written = [];
        $refused = [];
        foreach ($plan->patches as $row) {
            if (null === $row->reroll) {
                continue;
            }
            $declaredSource = $this->declaredSource($row);
            $fromUrl = null !== $declaredSource && PatchConfig::isUrl($declaredSource);
            $body = self::body($row);
            if (null === $body) {
                // A patch the release already carries has nothing to send
                // upstream; only a re-roll that produced nothing does.
                $where = $fromUrl && !$row->isMerged() ? self::upstream($declaredSource) : '';
                $why = self::whyNoReroll($row);
                $refused[] = self::refusal($row, $row->source, '' === $where
                    ? $why
                    : Text::t('@why; the fix belongs upstream: @where', ['@why' => $why, '@where' => $where]), shipped: $row->isMerged());
                continue;
            }
            // A merge that produced code the service cannot parse is not
            // a patch to hand anybody, whatever the site declared.
            $broken = $row->rerollSyntaxErrors();
            if ([] !== $broken) {
                $refused[] = self::refusal($row, $declaredSource ?? $row->source, Text::t('its re-roll leaves a file that does not parse: @file', ['@file' => $broken[0]]));
                continue;
            }
            if (null === $declaredSource) {
                $refused[] = self::refusal($row, $row->source, self::NOT_DECLARED);
                continue;
            }
            if ($fromUrl) {
                $refused[] = self::refusal($row, $declaredSource, self::URL_DECLARED);
                continue;
            }
            $source = self::inside($declaredSource);
            if (null === $source) {
                $refused[] = self::refusal($row, $declaredSource, self::OUTSIDE_ROOT);
                continue;
            }
            $path = $row->rerollIsClean() ? $source : self::conflictPath($source);
            // A copied patch says where its bytes came from. The re-roll
            // changes the bytes, so the line says which release they were
            // merged against and hashes what this run wrote.
            $provenance = Header::read($this->held($source));
            if ($row->rerollIsClean() && [] !== $provenance) {
                $body = Header::line(['rerolled' => $row->version, 'sha256' => Header::hash($body)] + $provenance).$body;
            }
            if (!$this->holds($path, $body)) {
                $reason = $this->refusalFor($path);
                if ('' !== $reason) {
                    $refused[] = self::refusal($row, $path, $reason, '--force');
                    continue;
                }
                $this->put($path, $body);
                if ($row->rerollIsClean()) {
                    $this->removeStale(self::conflictPath($source));
                }
            }
            $written[] = [
                'path' => $path,
                'status' => (string) ($row->reroll['status'] ?? ''),
                'package' => $row->package,
                'title' => $row->title,
                'verified' => true === ($row->reroll['verified'] ?? null),
                'unioned' => $row->unioned(),
                'regions' => $row->openRegions(),
                'open' => $row->openRegionList(),
                'removed' => $row->removedFiles(),
                'from' => $provenance['mr'] ?? '',
            ];
        }

        return ['written' => $written, 'refused' => $refused];
    }

    /**
     * Where the fix for a URL patch belongs: its merge request, its issue, or the URL itself.
     */
    private static function upstream(string $source): string
    {
        $where = PatchText::upstream($source);

        return '' === $where ? $source : $where;
    }

    /**
     * Why a row produced no patch to write, in the service's own words
     * when it gave any.
     */
    private static function whyNoReroll(PatchRow $row): string
    {
        foreach (['error', 'note'] as $key) {
            if ('' !== ($said = (string) ($row->reroll[$key] ?? ''))) {
                return $said;
            }
        }

        return self::NO_REROLL;
    }

    /**
     * @param bool $shipped the release already carries the patch, so the refusal is a finished job rather than work
     *
     * @return array{package: string, title: string, path: string, reason: string, lifts: string, shipped: bool}
     */
    private static function refusal(PatchRow $row, string $path, string $reason, string $lifts = '', bool $shipped = false): array
    {
        return ['package' => $row->package, 'title' => $row->title, 'path' => $path, 'reason' => $reason, 'lifts' => $lifts, 'shipped' => $shipped];
    }

    /**
     * Why this path may not be written, empty when it may.
     */
    private function refusalFor(string $path): string
    {
        if (null === $this->tree || !\is_file($this->root.\DIRECTORY_SEPARATOR.$path)) {
            return '';
        }

        return $this->tree->refusal($this->root, $path);
    }

    /**
     * Where a conflicted re-roll of this source goes: beside it, under a name a patch config never points at.
     */
    public static function conflictPath(string $source): string
    {
        foreach (self::PATCH_EXTENSIONS as $extension) {
            if (\str_ends_with(\strtolower($source), $extension)) {
                return \substr($source, 0, -\strlen($extension)).self::CONFLICT_SUFFIX;
            }
        }

        return $source.self::CONFLICT_SUFFIX;
    }

    /**
     * The declared source as a path under the site root, or null when it leaves the root.
     */
    public static function inside(string $source): ?string
    {
        $path = \str_replace('\\', '/', \trim($source));
        if ('' === $path || \str_starts_with($path, '/') || 1 === \preg_match('#^[a-z]:#i', $path)) {
            return null;
        }
        $kept = [];
        foreach (\explode('/', $path) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }
            if ('..' === $segment) {
                if ([] === $kept) {
                    return null;
                }
                \array_pop($kept);

                continue;
            }
            $kept[] = $segment;
        }

        return [] === $kept ? null : \implode('/', $kept);
    }

    /**
     * The file's text: the diff for a clean merge, and for a conflicted one the diff that did merge followed by every region left open.
     */
    private static function body(PatchRow $row): ?string
    {
        if ($row->rerollIsClean()) {
            return (string) ($row->reroll['patch'] ?? '');
        }
        if ('conflicts' !== ($row->reroll['status'] ?? '')) {
            return null;
        }
        $patch = (string) ($row->reroll['patch'] ?? '');
        $parts = '' === $patch ? [] : [$patch];
        foreach ((array) ($row->reroll['conflicts'] ?? []) as $conflict) {
            $parts[] = self::conflictText($conflict);
        }

        return [] === $parts ? null : \implode("\n", $parts);
    }

    /**
     * One conflicted file as merge markers, so the regions can be worked through in an editor.
     *
     * @param array<string, mixed> $conflict
     */
    private static function conflictText(array $conflict): string
    {
        $file = (string) ($conflict['file'] ?? '');
        if (true === ($conflict['removed'] ?? null)) {
            return self::removedText($conflict, $file);
        }
        $lines = [
            Text::t('# drupatch: @regions unresolved region(s) in @file', ['@regions' => (int) ($conflict['regions'] ?? 0), '@file' => $file]),
            '# drupatch: keep the region and end lines; replace the text between them.',
            Text::t('# drupatch: then run @command', ['@command' => Report::REROLL]),
        ];
        foreach ((array) ($conflict['hunks'] ?? []) as $index => $hunk) {
            $releaseLine = (int) ($hunk['release_line'] ?? 0);
            $at = $releaseLine > 0 ? $releaseLine : (int) ($hunk['line'] ?? 0);
            $lines[] = self::REGION_OPEN.$index.' '.$file;
            $lines[] = '<<<<<<< release '.$file.':'.$at;
            $lines[] = \rtrim((string) ($hunk['release'] ?? ''), "\n");
            $lines[] = '=======';
            $lines[] = \rtrim((string) ($hunk['patch'] ?? ''), "\n");
            $lines[] = '>>>>>>> patch';
            $lines[] = self::REGION_CLOSE.$index.' '.$file;
        }

        return \implode("\n", $lines)."\n";
    }

    /**
     * One file the release removed: what the patch did to it, and no region, because nothing here can be decided.
     *
     * @param array<string, mixed> $conflict
     */
    private static function removedText(array $conflict, string $file): string
    {
        $lines = [
            Text::t('# drupatch: @file is not in the release, so there is nothing to merge into.', ['@file' => $file]),
            '# drupatch: the hunks below are the patch as it was. Drop it, or aim it at where the code moved.',
        ];
        foreach ((array) ($conflict['hunks'] ?? []) as $hunk) {
            $lines[] = \rtrim((string) (((array) $hunk)['patch'] ?? ''), "\n");
        }

        return \implode("\n", $lines)."\n";
    }

    /**
     * What this file holds now, empty when the site has none.
     */
    private function held(string $path): string
    {
        $full = $this->root.\DIRECTORY_SEPARATOR.$path;

        return \is_file($full) ? (string) @\file_get_contents($full) : '';
    }

    /**
     * Whether the file already holds these bytes.
     */
    private function holds(string $path, string $body): bool
    {
        $full = $this->root.\DIRECTORY_SEPARATOR.$path;

        return \is_file($full) && \file_get_contents($full) === $body;
    }

    /**
     * Drops the conflict file an earlier run wrote for a patch that now merges cleanly, so one patch never has two answers on disk.
     */
    private function removeStale(string $path): void
    {
        $full = $this->root.\DIRECTORY_SEPARATOR.$path;
        if (\is_file($full)) {
            @\unlink($full);
        }
    }

    /**
     * Writes the file, creating its directory when the site has none.
     */
    private function put(string $path, string $body): void
    {
        $full = $this->root.\DIRECTORY_SEPARATOR.$path;
        $dir = \dirname($full);
        if (!\is_dir($dir) && !\mkdir($dir, 0o777, true) && !\is_dir($dir)) {
            throw new RuntimeException(Text::t('cannot create @dir', ['@dir' => $dir]));
        }
        if (false === \file_put_contents($full, $body)) {
            throw new RuntimeException(Text::t('cannot write @path', ['@path' => $path]));
        }
    }
}

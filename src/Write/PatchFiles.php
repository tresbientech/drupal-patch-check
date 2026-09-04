<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Write;

use RuntimeException;
use TresBienTech\Drupatch\PatchConfig;
use TresBienTech\Drupatch\PatchText;
use TresBienTech\Drupatch\Plan\PatchRow;
use TresBienTech\Drupatch\Plan\Plan;
use TresBienTech\Drupatch\Render\Report;

/**
 * Writes the re-rolled diffs a plan carries.
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

    public const NO_REROLL = 'the service returned no re-roll and gave no reason';

    public const URL_DECLARED = 'it is declared as a URL, so there is no file to replace';

    public const NO_FILE_NAME = 'its URL ends in no file name';

    public const NOT_DECLARED = 'the site declares no patch by that name';

    public const NOTHING_MERGED = 'no hunk of its re-roll merged, so there is nothing to fetch';

    /** Where an adopted URL patch goes, under the project it is on. */
    private const ADOPTED_DIRECTORY = 'patches';

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
        /** Whether a patch declared as a URL is written locally. `--update`. */
        private readonly bool $adopt = false,
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
     * @return array{written: list<array{path: string, status: string, package: string, title: string, verified: bool, unioned: list<array{file: string, line: int}>, regions: int}>,
     *               refused: list<array{package: string, title: string, path: string, reason: string, lifts: string}>}
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
                $refused[] = self::refusal($row, $row->source, self::whyNoReroll($row).$where);
                continue;
            }
            if (null === $declaredSource) {
                $refused[] = self::refusal($row, $row->source, self::NOT_DECLARED);
                continue;
            }
            if ($fromUrl && !$this->adopt) {
                $refused[] = self::refusal($row, $declaredSource, self::URL_DECLARED, '--update');
                continue;
            }
            // A URL patch reaches the site only when the re-roll gives it
            // something to use. A conflicted merge that kept no hunk is
            // the patch as it was, and the fix for that belongs upstream.
            if ($fromUrl && !$row->rerollIsClean() && '' === ($row->reroll['patch'] ?? '')) {
                $refused[] = self::refusal($row, $declaredSource, self::NOTHING_MERGED.self::upstream($declaredSource));
                continue;
            }
            $target = PatchConfig::isUrl($declaredSource)
                ? self::adoptedPath($row->package, $row->project, $declaredSource)
                : $declaredSource;
            if ('' === $target) {
                $refused[] = self::refusal($row, $declaredSource, self::NO_FILE_NAME);
                continue;
            }
            $source = self::inside($target);
            if (null === $source) {
                $refused[] = self::refusal($row, $declaredSource, self::OUTSIDE_ROOT);
                continue;
            }
            $path = $row->rerollIsClean() ? $source : self::conflictPath($source);
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
            ];
        }

        return ['written' => $written, 'refused' => $refused];
    }

    /**
     * Where the fix belongs, to end a refusal of a URL patch with: its merge request, its issue, or the URL itself.
     */
    private static function upstream(string $source): string
    {
        $where = PatchText::upstream($source);

        return '; the fix belongs upstream: '.('' === $where ? $source : $where);
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
     * @return array{package: string, title: string, path: string, reason: string, lifts: string}
     */
    private static function refusal(PatchRow $row, string $path, string $reason, string $lifts = ''): array
    {
        return ['package' => $row->package, 'title' => $row->title, 'path' => $path, 'reason' => $reason, 'lifts' => $lifts];
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
     * Where a patch declared as a URL is adopted to: the project's own patch directory, under the name the URL ends in.
     */
    public static function adoptedPath(string $package, string $project, string $source): string
    {
        $project = '' !== $project ? $project : \str_replace('drupal/', '', $package);
        $path = \parse_url($source, \PHP_URL_PATH);
        $name = \basename(\is_string($path) ? $path : '');
        if ('' === $name || '' === $project) {
            return '';
        }
        // The service names the project, so it decides a directory here.
        // A separator in it would place the file outside the project's own.
        if (\str_contains($project, '/') || \str_contains($project, '\\')) {
            return '';
        }

        return self::ADOPTED_DIRECTORY.'/'.$project.'/'.$name;
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
        $lines = [
            '# drupatch: '.(int) ($conflict['regions'] ?? 0).' unresolved region(s) in '.$file,
            '# drupatch: keep the region and end lines; replace the text between them.',
            '# drupatch: then run '.Report::REROLL,
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
            throw new RuntimeException('cannot create '.$dir);
        }
        if (false === \file_put_contents($full, $body)) {
            throw new RuntimeException('cannot write '.$path);
        }
    }
}

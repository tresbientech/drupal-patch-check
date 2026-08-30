<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Write;

use RuntimeException;
use Tresbien\Drupatch\Plan\Conflict;
use Tresbien\Drupatch\Plan\PatchRow;
use Tresbien\Drupatch\Plan\Plan;
use Tresbien\Drupatch\Plan\Reroll;

/**
 * Writes the re-rolled diffs a plan carries.
 *
 * A clean merge becomes a patch file the site can use. A conflicted merge
 * becomes a file named so no patch manager reads it: it holds regions
 * nobody has decided yet.
 */
final class PatchFiles
{
    public const CLEAN_SUFFIX = '.patch';

    public const CONFLICT_SUFFIX = '.conflict.patch';

    /** Where re-rolls go when every patch the site declares is a URL. */
    private const DEFAULT_DIRECTORY = 'patches';

    public function __construct(private readonly string $root, private readonly string $directory)
    {
    }

    /**
     * Picks where re-rolls go: beside the local patches the site already
     * keeps, or `patches/` when it keeps none.
     */
    public static function forPlan(string $root, Plan $plan): self
    {
        foreach ($plan->patches as $row) {
            if ('' === $row->source || 1 === \preg_match('#^https?://#i', $row->source)) {
                continue;
            }
            $dir = \dirname($row->source);

            return new self($root, '.' === $dir ? self::DEFAULT_DIRECTORY : $dir);
        }

        return new self($root, self::DEFAULT_DIRECTORY);
    }

    /**
     * Writes one file per re-rolled patch and reports what happened.
     * Writing the same plan twice writes the same bytes and adds no file.
     *
     * @return list<WrittenFile>
     */
    public function write(Plan $plan): array
    {
        $written = [];
        foreach ($plan->patches as $row) {
            if (null === $row->reroll) {
                continue;
            }
            $body = self::body($row->reroll);
            if (null === $body) {
                continue;
            }
            $path = $this->path($row, $row->reroll);
            $this->put($path, $body);
            $written[] = WrittenFile::of($row, $row->reroll, $path);
        }

        return $written;
    }

    /**
     * The file's text: the diff for a clean merge, and for a conflicted
     * one the diff that did merge followed by every region left open.
     */
    private static function body(Reroll $reroll): ?string
    {
        if ($reroll->isClean()) {
            return $reroll->patch;
        }
        if (!$reroll->hasConflicts()) {
            return null;
        }
        $parts = '' === $reroll->patch ? [] : [$reroll->patch];
        foreach ($reroll->conflicts as $conflict) {
            $parts[] = self::conflictText($conflict);
        }

        return [] === $parts ? null : \implode("\n", $parts);
    }

    /**
     * One conflicted file as merge markers, so the regions can be worked
     * through in an editor.
     */
    private static function conflictText(Conflict $conflict): string
    {
        $lines = ['# drupatch: '.$conflict->regions.' unresolved region(s) in '.$conflict->file];
        foreach ($conflict->hunks as $hunk) {
            $lines[] = '<<<<<<< release '.$conflict->file.':'.$hunk->at();
            $lines[] = \rtrim($hunk->release, "\n");
            $lines[] = '=======';
            $lines[] = \rtrim($hunk->patch, "\n");
            $lines[] = '>>>>>>> patch';
        }

        return \implode("\n", $lines)."\n";
    }

    private function path(PatchRow $row, Reroll $reroll): string
    {
        $project = self::slug(\str_replace('drupal/', '', '' !== $row->project ? $row->project : $row->package));
        $title = self::slug($row->title);
        $stamp = \substr(\sha1($row->source.'|'.$row->title), 0, 8);
        $name = \trim($project.'-'.$title, '-').'-'.$stamp;

        return $this->directory.'/'.$name.($reroll->isClean() ? self::CLEAN_SUFFIX : self::CONFLICT_SUFFIX);
    }

    private static function slug(string $text): string
    {
        $slug = \strtolower(\trim($text));
        $slug = (string) \preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = \trim($slug, '-');

        return \strlen($slug) > 50 ? \substr($slug, 0, 50) : $slug;
    }

    /**
     * Writes only when the bytes differ, so a second run of the same plan
     * leaves the tree alone.
     */
    private function put(string $path, string $body): void
    {
        $full = $this->root.\DIRECTORY_SEPARATOR.$path;
        $dir = \dirname($full);
        if (!\is_dir($dir) && !\mkdir($dir, 0o777, true) && !\is_dir($dir)) {
            throw new RuntimeException('cannot create '.$dir);
        }
        if (\is_file($full) && \file_get_contents($full) === $body) {
            return;
        }
        if (false === \file_put_contents($full, $body)) {
            throw new RuntimeException('cannot write '.$path);
        }
    }
}

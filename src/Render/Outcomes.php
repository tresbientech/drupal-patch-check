<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Render;

use TresBienTech\Drupatch\Plan\PatchRow;

/**
 * What a run did to each patch, keyed the way rows are: the lines under a row, and the counts the footer needs.
 */
class Outcomes
{
    /** @var array<string, array{path: string, status: string, verified: bool, unioned: list<array{file: string, line: int}>}> */
    private array $written = [];

    /** @var array<string, array{path: string, reason: string, lifts: string}> */
    private array $refused = [];

    /** What each lifting flag does about a refusal, in the words of its next-step line. */
    private const LIFTS = ['--force' => 'replaces it', '--fix' => 'adopts it'];

    /** @var array<string, array{action: 'dropped'|'repointed', path: string}> */
    private array $changes = [];

    private bool $fixed = false;

    /** The file the fix rewrote: composer.json, or the site's patches file. */
    private string $declaration = '';

    /**
     * @param array{written: list<array{path: string, status: string, package: string, title: string, verified: bool, unioned: list<array{file: string, line: int}>}>,
     *              refused: list<array{package: string, title: string, path: string, reason: string, lifts: string}>} $result
     */
    public static function fromWrite(array $result): self
    {
        $out = new self();
        foreach ($result['written'] as $file) {
            $out->written[PatchRow::keyOf($file['package'], $file['title'])] = $file;
        }
        foreach ($result['refused'] as $refusal) {
            $out->refused[PatchRow::keyOf($refusal['package'], $refusal['title'])] = $refusal;
        }

        return $out;
    }

    /**
     * Records the fix rewrite: the file it changed, and what it did to each entry.
     *
     * @param list<array{action: 'dropped'|'repointed', package: string, title: string, path: string}> $changes
     */
    public function recordFix(array $changes, string $declaration): void
    {
        $this->fixed = true;
        $this->declaration = $declaration;
        foreach ($changes as $change) {
            $this->changes[PatchRow::keyOf($change['package'], $change['title'])] = $change;
        }
    }

    /**
     * Whether the run rewrote the declarations.
     */
    public function fixed(): bool
    {
        return $this->fixed;
    }

    /**
     * Whether the rewrite changed an entry.
     */
    public function changed(): bool
    {
        return [] !== $this->changes;
    }

    /**
     * The lines under a row, in order: why nothing was written and what lifts that, the file written and the regions the merge decided by itself, what the fix did to the entry.
     *
     * @return list<string>
     */
    public function under(PatchRow $row): array
    {
        $lines = [];
        $refusal = $this->refused[$row->key()] ?? null;
        if (null !== $refusal) {
            $lift = self::LIFTS[$refusal['lifts']] ?? '';
            $lines[] = 'not written: '.$refusal['reason'].('' === $lift ? '' : ', '.$refusal['lifts'].' '.$lift);
        }
        $file = $this->written[$row->key()] ?? null;
        if (null !== $file) {
            $lines[] = self::wrote($file, $row);
            foreach ($file['unioned'] as $region) {
                $lines[] = '  '.$region['file'].':'.$region['line'];
            }
        }
        $change = $this->changes[$row->key()] ?? null;
        if (null !== $change) {
            $lines[] = $this->rewrote($change);
        }

        return $lines;
    }

    /**
     * @param array{action: 'dropped'|'repointed', path: string} $change
     */
    private function rewrote(array $change): string
    {
        if ('repointed' === $change['action']) {
            return $this->declaration.' now points at '.$change['path'];
        }
        if ('' === $change['path']) {
            return 'dropped from '.$this->declaration.' (already in the release)';
        }

        return \sprintf('dropped from %s (already in the release; %s is now unreferenced and was kept)', $this->declaration, $change['path']);
    }

    /**
     * @param array{path: string, status: string, verified: bool} $file
     */
    private static function wrote(array $file, PatchRow $row): string
    {
        if (PatchRow::CONFLICTS === $file['status']) {
            $open = $row->openRegions();

            return \sprintf('wrote %s, %d region%s to decide', $file['path'], $open, 1 === $open ? '' : 's');
        }
        if ($file['verified']) {
            return \sprintf('wrote %s (verified against %s)', $file['path'], $row->version);
        }

        return 'wrote '.$file['path'];
    }

    /**
     * The conflict files this run left, none of them usable as a patch.
     */
    public function openConflictFiles(): int
    {
        $count = 0;
        foreach ($this->written as $file) {
            if (PatchRow::CONFLICTS === $file['status']) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * The refusals a flag lifts.
     */
    public function lifted(string $flag): int
    {
        $count = 0;
        foreach ($this->refused as $refusal) {
            if ($flag === $refusal['lifts']) {
                ++$count;
            }
        }

        return $count;
    }
}

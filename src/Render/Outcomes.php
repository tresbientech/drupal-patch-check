<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Render;

use TresBienTech\Drupatch\Plan\PatchRow;

/**
 * What a run did: the files it wrote, the ones it would not, the declarations it rewrote, and the counts the footer needs.
 */
class Outcomes
{
    /** @var list<array{path: string, status: string, package: string, title: string, verified: bool, unioned: list<array{file: string, line: int}>, regions: int}> */
    private array $written = [];

    /** @var list<array{package: string, title: string, path: string, reason: string, lifts: string}> */
    private array $refused = [];

    /** @var list<array{action: 'dropped'|'repointed', package: string, title: string, path: string}> */
    private array $changes = [];

    private bool $fixed = false;

    /** The file the fix rewrote: composer.json, or the site's patches file. */
    private string $declaration = '';

    /**
     * @param array{written: list<array{path: string, status: string, package: string, title: string, verified: bool, unioned: list<array{file: string, line: int}>, regions: int}>,
     *              refused: list<array{package: string, title: string, path: string, reason: string, lifts: string}>} $result
     */
    public static function fromWrite(array $result): self
    {
        $out = new self();
        $out->written = $result['written'];
        $out->refused = $result['refused'];

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
        $this->changes = $changes;
    }

    /**
     * @return list<array{path: string, status: string, package: string, title: string, verified: bool, unioned: list<array{file: string, line: int}>, regions: int}>
     */
    public function written(): array
    {
        return $this->written;
    }

    /**
     * @return list<array{package: string, title: string, path: string, reason: string, lifts: string}>
     */
    public function refused(): array
    {
        return $this->refused;
    }

    /**
     * @return list<array{action: 'dropped'|'repointed', package: string, title: string, path: string}>
     */
    public function changes(): array
    {
        return $this->changes;
    }

    public function declaration(): string
    {
        return $this->declaration;
    }

    /**
     * Whether the run rewrote the declarations.
     */
    public function fixed(): bool
    {
        return $this->fixed;
    }

    /**
     * The document with the diff text this run put on disk taken out: each written row's `reroll.patch` is emptied and `reroll.path` names the file. A row the run refused keeps its text.
     *
     * @param array<string, mixed> $raw the service's document as received
     *
     * @return array<string, mixed>
     */
    public function intoDocument(array $raw): array
    {
        $paths = [];
        foreach ($this->written as $file) {
            $paths[PatchRow::keyOf($file['package'], $file['title'])] = $file['path'];
        }
        if ([] === $paths || !\is_array($raw['plan'] ?? null)) {
            return $raw;
        }
        foreach ((array) ($raw['plan']['patches'] ?? []) as $i => $row) {
            $path = $paths[PatchRow::keyOf((string) ($row['package'] ?? ''), (string) ($row['title'] ?? ''))] ?? null;
            if (null === $path || !\is_array($row['result']['reroll'] ?? null)) {
                continue;
            }
            $raw['plan']['patches'][$i]['result']['reroll']['patch'] = '';
            $raw['plan']['patches'][$i]['result']['reroll']['path'] = $path;
        }

        return $raw;
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

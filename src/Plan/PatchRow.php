<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Plan;

use RuntimeException;

/**
 * One patch as the plan judged it.
 */
class PatchRow
{
    /**
     * Verdicts that need nothing from anybody; an unknown one never reads as fine.
     */
    public const CLEAN_VERDICTS = [self::MERGED, self::APPLIES];

    /** Verdicts a non-strict run reports without failing. */
    public const TOLERATED_VERDICTS = [self::MERGED, self::APPLIES, self::UNKNOWN];

    public const UNKNOWN = 'unknown';

    public const MERGED = 'merged';

    public const APPLIES = 'applies';

    public const CONFLICTS = 'conflicts';

    private function __construct(
        public readonly string $package,
        public readonly string $project,
        public readonly string $version,
        public readonly string $installed,
        public readonly string $title,
        public readonly string $source,
        public readonly string $verdict,
        public readonly string $note,
        public readonly string $error,
        /** Why a strict apply refused a patch a looser one accepted. */
        public readonly string $strictRefused,
        /** An earlier patch of the package that did not apply. */
        public readonly string $judgedWithout,
        /** The first file the patch failed on, and why. */
        private readonly string $failedHunk,
        /**
         * The hunks the release already carries verbatim, one line each.
         *
         * Every hunk, not just the first: a patch whose hunks the release
         * mostly has is the evidence that its fix landed in another form.
         *
         * @var list<string>
         */
        public readonly array $hunksShipped,
        /** Where the release this row is about came from: composer, or the bundle. */
        public readonly string $decidedBy,
        /**
         * The server's re-roll as it arrived, null when it sent none.
         *
         * @var array<string, mixed>|null
         */
        public readonly ?array $reroll,
        /**
         * The core references block as the server sent it, [] when the row carries none.
         *
         * @var array<string, mixed>
         */
        public readonly array $coreReferences,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $package = (string) ($data['package'] ?? '');
        if ('' === $package) {
            throw new RuntimeException('a patch row has no package');
        }
        $result = (array) ($data['result'] ?? []);
        $failed = (array) ($result['hunks_failed'] ?? []);
        $shipped = (array) ($result['hunks_shipped'] ?? []);

        return new self(
            $package,
            (string) ($data['project'] ?? \str_replace('drupal/', '', $package)),
            (string) ($data['version'] ?? ''),
            (string) ($data['installed'] ?? ''),
            (string) ($data['title'] ?? ''),
            (string) ($data['source'] ?? ''),
            (string) ($data['verdict'] ?? ''),
            (string) ($data['note'] ?? ''),
            (string) ($result['error'] ?? ''),
            (string) ($result['strict_refused'] ?? ''),
            (string) ($result['judged_without'] ?? ''),
            [] === $failed ? '' : self::hunk($failed[0]),
            \array_values(\array_map(self::hunk(...), $shipped)),
            (string) ($data['decided_by'] ?? ''),
            \is_array($result['reroll'] ?? null) ? $result['reroll'] : null,
            \is_array($result['core_references'] ?? null) ? $result['core_references'] : [],
        );
    }

    /**
     * Whether the run should exit non-zero because of this row.
     */
    public function needsAction(): bool
    {
        return !\in_array($this->verdict, self::CLEAN_VERDICTS, true);
    }

    /**
     * Whether the server's re-roll merged cleanly into a usable patch.
     */
    public function rerollIsClean(): bool
    {
        return 'clean' === ($this->reroll['status'] ?? '') && '' !== ($this->reroll['patch'] ?? '');
    }

    /**
     * The patch the merge ran on, when the server did not use the declared one.
     */
    public function mergedFrom(): string
    {
        return (string) ($this->reroll['merged_from'] ?? '');
    }

    /**
     * The regions a conflicted re-roll leaves for a person, summed over its files.
     */
    public function openRegions(): int
    {
        $count = 0;
        foreach ((array) ($this->reroll['conflicts'] ?? []) as $conflict) {
            $count += (int) (((array) $conflict)['regions'] ?? 0);
        }

        return $count;
    }

    /**
     * The regions the merge decided on its own by keeping both sides.
     *
     * @return list<array{file: string, line: int}>
     */
    public function unioned(): array
    {
        $out = [];
        foreach ((array) ($this->reroll['unioned'] ?? []) as $region) {
            $region = (array) $region;
            $file = (string) ($region['file'] ?? '');
            if ('' !== $file) {
                $out[] = ['file' => $file, 'line' => (int) ($region['line'] ?? 0)];
            }
        }

        return $out;
    }

    /**
     * One failed hunk as a person reads it: the file, and why.
     *
     * @param array<string, mixed> $hunk
     */
    private static function hunk(array $hunk): string
    {
        $file = (string) ($hunk['file'] ?? '');
        $reason = (string) ($hunk['reason'] ?? '');
        if ('' === $file || '' === $reason) {
            return $file.$reason;
        }

        return $file.': '.$reason;
    }

    /**
     * The first file a re-roll has to fix, empty when the verdict stands.
     */
    public function firstFailure(): string
    {
        return $this->conflicts() ? $this->failedHunk : '';
    }

    /**
     * Whether the row fails the run; strict adds the unclear verdicts.
     */
    public function fails(bool $strict): bool
    {
        if ($strict) {
            return $this->needsAction();
        }

        return !\in_array($this->verdict, self::TOLERATED_VERDICTS, true);
    }

    /**
     * Whether the row is worth a line of its own: anything but an applying patch, or an applying patch referencing core code the target changed.
     */
    public function needsMention(): bool
    {
        return self::APPLIES !== $this->verdict || $this->flaggedCoreReferences() > 0;
    }

    /**
     * How many core references the target removed, moved or re-signed, the ones the server cut included.
     */
    public function flaggedCoreReferences(): int
    {
        return \count((array) ($this->coreReferences['flagged'] ?? [])) + (int) ($this->coreReferences['flagged_more'] ?? 0);
    }

    public function isMerged(): bool
    {
        return self::MERGED === $this->verdict;
    }

    public function conflicts(): bool
    {
        return self::CONFLICTS === $this->verdict;
    }

    /**
     * Whether the verdict is about a release other than the installed one; the two spellings of a dev branch are one release.
     */
    public function movesRelease(): bool
    {
        if ('' === $this->installed || '' === $this->version) {
            return false;
        }

        return self::branch($this->installed) !== self::branch($this->version);
    }

    private static function branch(string $version): string
    {
        if (\str_starts_with($version, 'dev-')) {
            return \substr($version, 4);
        }
        if (\str_ends_with($version, '-dev')) {
            return \substr($version, 0, -4);
        }

        return $version;
    }

    public function label(): string
    {
        return '' !== $this->title ? $this->title : $this->source;
    }

    /**
     * Why a patch has no usable verdict, empty when it has one.
     */
    public function reason(): string
    {
        return '' !== $this->note ? $this->note : $this->error;
    }

    /**
     * One declaration's identity: the package and the declared title.
     */
    public function key(): string
    {
        return self::keyOf($this->package, $this->title);
    }

    /**
     * The key a row is filed under, for anything that names a patch by package and title.
     */
    public static function keyOf(string $package, string $title): string
    {
        return $package."\0".$title;
    }
}

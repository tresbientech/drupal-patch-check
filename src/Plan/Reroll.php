<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Plan;

/**
 * The re-roll the service sent for one patch.
 *
 * @phpstan-type Conflict array{file: string, removed: bool, regions: int, hunks: array<array{line: int, release_line: int, release: string, patch: string}>}
 */
class Reroll
{
    /**
     * @param list<string>                           $syntaxErrors
     * @param list<string>                           $droppedPaths
     * @param list<array{file: string, line: int}>   $unioned
     * @param list<Conflict>                         $conflicts
     * @param list<array{file: string, region: int}> $open
     * @param list<string>                           $removed
     */
    private function __construct(
        /** `clean` or `conflicts`. */
        public readonly string $status,
        /** The merged diff; for a conflicted merge, the part that merged. */
        public readonly string $patch,
        /** Whether the service applied the merged diff to the release. */
        public readonly bool $verified,
        public readonly string $error,
        public readonly string $note,
        /** The patch the merge ran on, empty when the service used the declared one. */
        public readonly string $mergedFrom,
        /** How many of the decided regions the service applied. */
        public readonly int $resolutionsApplied,
        /**
         * What the merged diff leaves unparseable, `path: message` as the
         * server wrote it. Only a clean merge is read for this, so a conflict
         * file a person still has to edit is never judged by it.
         */
        public readonly array $syntaxErrors,
        /** The files the re-roll left out, because the release package does not carry them. */
        public readonly array $droppedPaths,
        /** The regions the merge decided on its own by keeping both sides. */
        public readonly array $unioned,
        /** Every conflicted file, with the text of the regions the service sent. */
        public readonly array $conflicts,
        /** Every region a conflicted re-roll leaves, as the file and index a decision names it by. */
        public readonly array $open,
        /** The files the release deleted, which a re-roll has nothing to merge into. */
        public readonly array $removed,
    ) {
    }

    /**
     * @param array<mixed> $reroll `result.reroll` as the server sent it
     */
    public static function fromArray(array $reroll): self
    {
        $status = (string) ($reroll['status'] ?? '');
        $unioned = \array_values(\array_filter(
            \array_map(static fn (array $region): array => ['file' => (string) ($region['file'] ?? ''), 'line' => (int) ($region['line'] ?? 0)], (array) ($reroll['unioned'] ?? [])),
            static fn (array $region): bool => '' !== $region['file'],
        ));
        $conflicts = [];
        $open = [];
        $removed = [];
        foreach ((array) ($reroll['conflicts'] ?? []) as $conflict) {
            $entry = self::conflict($conflict);
            $conflicts[] = $entry;
            // A merge the decisions settled keeps the conflicts it started
            // from and reports itself clean. Nothing there is left to decide.
            if (PatchRow::CONFLICTS !== $status || '' === $entry['file']) {
                continue;
            }
            // A file the release removed has no index entry, so the service
            // applies no resolution to it. Offering its region would be
            // offering a decision that decides nothing.
            if ($entry['removed']) {
                $removed[] = $entry['file'];
                continue;
            }
            // The service numbers every region of the merged file, and sends
            // the text of only the first few, so the count leads here and the
            // hunks do not.
            for ($region = 0; $region < $entry['regions']; ++$region) {
                $open[] = ['file' => $entry['file'], 'region' => $region];
            }
        }

        return new self(
            $status,
            (string) ($reroll['patch'] ?? ''),
            true === ($reroll['verified'] ?? null),
            (string) ($reroll['error'] ?? ''),
            (string) ($reroll['note'] ?? ''),
            (string) ($reroll['merged_from'] ?? ''),
            (int) ($reroll['resolutions_applied'] ?? 0),
            \array_values(\array_filter((array) ($reroll['syntax_errors'] ?? []), \is_string(...))),
            \array_values(\array_filter((array) ($reroll['dropped_paths'] ?? $reroll['dropped_tests'] ?? []), \is_string(...))),
            $unioned,
            $conflicts,
            $open,
            $removed,
        );
    }

    /**
     * One conflicted file as the server sent it.
     *
     * @param array<string, mixed> $conflict
     *
     * @return Conflict
     */
    private static function conflict(array $conflict): array
    {
        return [
            'file' => (string) ($conflict['file'] ?? ''),
            'removed' => true === ($conflict['removed'] ?? null),
            'regions' => (int) ($conflict['regions'] ?? 0),
            'hunks' => \array_map(static fn (array $hunk): array => [
                'line' => (int) ($hunk['line'] ?? 0),
                'release_line' => (int) ($hunk['release_line'] ?? 0),
                'release' => (string) ($hunk['release'] ?? ''),
                'patch' => (string) ($hunk['patch'] ?? ''),
            ], (array) ($conflict['hunks'] ?? [])),
        ];
    }

    /**
     * Whether the merge came out clean with a diff to write.
     */
    public function isClean(): bool
    {
        return 'clean' === $this->status && '' !== $this->patch;
    }
}

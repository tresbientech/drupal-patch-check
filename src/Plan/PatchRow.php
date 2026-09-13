<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Plan;

use RuntimeException;
use TresBienTech\Drupatch\Manager;
use TresBienTech\Drupatch\Read\Declaration;
use TresBienTech\Drupatch\Source\IssueReference;

/**
 * One patch as the plan judged it.
 *
 * @phpstan-type Hunk array{file: string, line: int, reason: string}
 */
class PatchRow
{
    /** Verdicts the run reports without failing. */
    public const TOLERATED_VERDICTS = [self::MERGED, self::APPLIES, self::UNKNOWN];

    public const UNKNOWN = 'unknown';

    public const MERGED = 'merged';

    public const APPLIES = 'applies';

    public const CONFLICTS = 'conflicts';

    /** The failure mode the server sends for a patch that applied and left a file that does not parse. */
    public const BROKEN_SYNTAX = 'broken syntax';

    /** The failure mode a site on 2.x of the patch manager gives a patch only a lenient apply took. */
    public const REFUSED_BY_TWO = 'refused by 2.x';

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
        /**
         * Whether the patch needed a lenient apply.
         *
         * A strict refusal the mirror caused sets no fuzzy: a release
         * archive carries a packaging block the git tag does not, so such
         * a patch applies on a site as it stands.
         */
        public readonly bool $fuzzy,
        /**
         * The `-p` level the patch applies at, and null when none did.
         *
         * 1.x guesses the level per patch. 2.x applies at the depth the
         * definition names, then the one its package defaults to, so a patch
         * needing another level has to record it.
         */
        public readonly ?int $appliesAt,
        /**
         * The earlier patches of the package that did not apply whole and
         * left part of themselves in the tree this one was judged against.
         *
         * @var list<string>
         */
        public readonly array $judgedWithout,
        /**
         * Every hunk the patch failed on.
         *
         * @var list<Hunk>
         */
        private readonly array $hunksFailed,
        /** How many hunks failed, before the service capped the list. */
        public readonly int $failedTotal,
        /** How many hunks the release carries, before the same cap. */
        public readonly int $shippedTotal,
        /**
         * The hunks the release already carries verbatim.
         *
         * Every hunk, not just the first: a patch whose hunks the release
         * mostly has is the evidence that its fix landed in another form.
         *
         * @var list<Hunk>
         */
        public readonly array $hunksShipped,
        /** Where the release this row is about came from: composer, or the bundle. */
        public readonly string $decidedBy,
        /** The server's re-roll, null when it sent none. */
        public readonly ?Reroll $reroll,
        /**
         * The core references block as the server sent it, [] when the row carries none.
         *
         * @var array<string, mixed>
         */
        public readonly array $coreReferences,
        /** What the service says is wrong with a patch that applied, empty when it says nothing is. */
        private readonly string $failureMode,
        /**
         * The files behind the failure mode, `path: message` as the server
         * wrote them.
         *
         * @var list<string>
         */
        public readonly array $syntaxErrors,
        /** The patch manager the row is read under, null to read the service's answer as sent. */
        private readonly ?Manager $manager,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param Manager|null         $manager the patch manager the row is read under, null to read the service's answer as sent
     */
    public static function fromArray(array $data, ?Manager $manager): self
    {
        $package = (string) ($data['package'] ?? '');
        if ('' === $package) {
            throw new RuntimeException('a patch row has no package');
        }
        $result = (array) ($data['result'] ?? []);
        $failed = (array) ($result['hunks_failed'] ?? []);
        $shipped = (array) ($result['hunks_shipped'] ?? []);
        $without = (array) ($result['judged_without'] ?? []);

        return new self(
            $package,
            (string) ($data['project'] ?? IssueReference::projectOf($package)),
            (string) ($data['version'] ?? ''),
            (string) ($data['installed'] ?? ''),
            (string) ($data['title'] ?? ''),
            (string) ($data['source'] ?? ''),
            (string) ($data['verdict'] ?? ''),
            (string) ($data['note'] ?? ''),
            (string) ($result['error'] ?? ''),
            (string) ($result['strict_refused'] ?? ''),
            true === ($result['fuzzy'] ?? null),
            \is_int($result['applies_at'] ?? null) ? $result['applies_at'] : null,
            \array_values(\array_filter($without, \is_string(...))),
            self::hunks($failed),
            (int) ($result['hunks_failed_total'] ?? \count($failed)),
            (int) ($result['hunks_shipped_total'] ?? \count($shipped)),
            self::hunks($shipped),
            (string) ($data['decided_by'] ?? ''),
            \is_array($result['reroll'] ?? null) ? Reroll::fromArray($result['reroll']) : null,
            \is_array($result['core_references'] ?? null) ? $result['core_references'] : [],
            (string) ($result['failure_mode'] ?? ''),
            \array_values(\array_filter((array) ($result['syntax_errors'] ?? []), \is_string(...))),
            $manager,
        );
    }

    /**
     * A server list of hunks, each as its file, the release line it was aimed at, and the reason.
     *
     * @param array<array<string, mixed>> $list
     *
     * @return list<Hunk>
     */
    private static function hunks(array $list): array
    {
        return \array_values(\array_map(
            static fn (array $hunk): array => ['file' => (string) ($hunk['file'] ?? ''), 'line' => (int) ($hunk['line'] ?? 0), 'reason' => (string) ($hunk['reason'] ?? '')],
            $list,
        ));
    }

    /**
     * What is wrong with a patch that applied, empty when nothing is. The
     * verdict stays what it was: the patch did apply.
     *
     * 2.x applies with `git apply` alone, so under it a patch only a lenient
     * apply took stops applying. A row the service already names a failure
     * for keeps that failure.
     */
    public function failureMode(): string
    {
        return '' === $this->failureMode && $this->fuzzy && true === $this->manager?->isTwo() ? self::REFUSED_BY_TWO : $this->failureMode;
    }

    /**
     * What a re-roll has to fix, empty when the verdict stands.
     *
     * @return list<Hunk>
     */
    public function failures(): array
    {
        return $this->conflicts() ? $this->hunksFailed : [];
    }

    /**
     * Whether the row fails the run.
     */
    public function fails(): bool
    {
        return '' !== $this->failureMode() || !\in_array($this->verdict, self::TOLERATED_VERDICTS, true);
    }

    /**
     * Whether the row is worth a line of its own: anything but an applying patch, or an applying patch referencing core code the target changed.
     */
    public function needsMention(): bool
    {
        return self::APPLIES !== $this->verdict || '' !== $this->failureMode() || $this->flaggedCoreReferences() > 0;
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
     * What the row reads as: the verdict, or the failure mode when the patch
     * applied and left something broken.
     */
    public function status(): string
    {
        return '' !== $this->failureMode() ? $this->failureMode() : $this->verdict;
    }

    /**
     * One declaration's identity: the package and the declared title.
     */
    public function key(): string
    {
        return Declaration::keyOf($this->package, $this->title);
    }
}

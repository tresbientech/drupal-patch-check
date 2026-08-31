<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Plan;

/**
 * One patch as the plan judged it.
 */
final class PatchRow
{
    /**
     * Verdicts that need nothing from anybody. Every other verdict counts
     * as work, including one this plugin has never heard of: a verdict
     * added to the server later must not read as "fine".
     */
    public const CLEAN_VERDICTS = [self::MERGED, self::APPLIES];

    /**
     * Verdicts a run reports without failing. `unknown` is as often a
     * mirror that lags a release as a real problem, and neither is
     * something the repository can fix, so a scheduled job is not woken
     * by one unless it asked to be.
     */
    public const TOLERATED_VERDICTS = [self::MERGED, self::APPLIES, self::UNKNOWN];

    public const UNKNOWN = 'unknown';

    /** The release already carries the change, so the patch can go. */
    public const MERGED = 'merged';

    /** The patch applies to the release cleanly. */
    public const APPLIES = 'applies';

    /** The patch does not apply and has to be re-rolled. */
    public const CONFLICTS = 'conflicts';

    /**
     * What each verdict was called before 0.6.1.
     *
     * A site can run a plugin newer than the service it asks, so a plan
     * arriving in the old words is read into the new ones here, at the
     * boundary, and nothing below this line has two names for one thing.
     */
    private const RENAMED = [
        'shipped' => self::MERGED,
        'still-needed' => self::APPLIES,
        'needs-reroll' => self::CONFLICTS,
    ];

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
        /** Where the release this row is about came from: composer, or the bundle. */
        public readonly string $decidedBy,
        public readonly ?Reroll $reroll,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $package = Value::str($data, 'package');
        if ('' === $package) {
            throw new InvalidPlan('a patch row names no package');
        }
        $result = Value::object($data, 'result');
        $reroll = isset($result['reroll']) && \is_array($result['reroll'])
            ? Reroll::fromArray(Value::keyed($result['reroll']))
            : null;
        $failed = Value::objects($result, 'hunks_failed');

        return new self(
            $package,
            Value::str($data, 'project', \str_replace('drupal/', '', $package)),
            Value::str($data, 'version'),
            Value::str($data, 'installed'),
            Value::str($data, 'title'),
            Value::str($data, 'source'),
            self::RENAMED[Value::str($data, 'verdict')] ?? Value::str($data, 'verdict'),
            Value::str($data, 'note'),
            Value::str($result, 'error'),
            Value::str($result, 'strict_refused'),
            Value::str($result, 'judged_without'),
            [] === $failed ? '' : self::hunk($failed[0]),
            Value::str($data, 'decided_by'),
            $reroll,
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
     * One failed hunk as a person reads it: the file, and why git
     * refused it there.
     *
     * @param array<string, mixed> $hunk
     */
    private static function hunk(array $hunk): string
    {
        $file = Value::str($hunk, 'file');
        $reason = Value::str($hunk, 'reason');
        if ('' === $file || '' === $reason) {
            return $file.$reason;
        }

        return $file.': '.$reason;
    }

    /**
     * The first file a re-roll has to fix, empty when the verdict stands
     * or the server named none. One line is the size of a hint; the rest
     * of the failure is in the patch.
     */
    public function firstFailure(): string
    {
        return $this->conflicts() ? $this->failedHunk : '';
    }

    /**
     * Whether the row should fail a run. A patch that will not apply
     * should; one the service could not judge should only when the run
     * asked to be woken by those too. A verdict this plugin does not know
     * always fails, so a new one is never read as fine.
     */
    public function fails(bool $strict): bool
    {
        if ($strict) {
            return $this->needsAction();
        }

        return !\in_array($this->verdict, self::TOLERATED_VERDICTS, true);
    }

    /**
     * Whether the row is worth a line of its own. A merged patch needs
     * no action and is still worth saying: it can be deleted. Only a
     * patch that applies and is still required stays in the tally alone.
     */
    public function needsMention(): bool
    {
        return self::APPLIES !== $this->verdict;
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
     * What the row is called in output: its title, or the patch it names.
     */
    /**
     * Whether the verdict is about a release other than the one the lock
     * holds.
     *
     * Composer writes a branch two ways: `dev-2.x` as a reference and
     * `2.x-dev` as the alias for a branch named like a version. They are
     * one branch, so a heading pairing them would claim a move that is
     * not happening.
     */
    public function movesRelease(): bool
    {
        if ('' === $this->installed || '' === $this->version) {
            return false;
        }

        return self::branch($this->installed) !== self::branch($this->version);
    }

    /**
     * A version with either spelling of a development branch reduced to
     * the branch name, and any other version left alone.
     */
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
     * The identity of one declaration: a package and the title under
     * which the site declared it.
     */
    public function key(): string
    {
        return $this->package."\0".$this->title;
    }
}

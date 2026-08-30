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
    public const CLEAN_VERDICTS = ['shipped', 'still-needed'];

    /**
     * Verdicts a run reports without failing. `unknown` is as often a
     * mirror that lags a release as a real problem, and neither is
     * something the repository can fix, so a scheduled job is not woken
     * by one unless it asked to be.
     */
    public const TOLERATED_VERDICTS = ['shipped', 'still-needed', 'unknown'];

    public const UNKNOWN = 'unknown';

    public const SHIPPED = 'shipped';

    public const NEEDS_REROLL = 'needs-reroll';

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

        return new self(
            $package,
            Value::str($data, 'project', \str_replace('drupal/', '', $package)),
            Value::str($data, 'version'),
            Value::str($data, 'installed'),
            Value::str($data, 'title'),
            Value::str($data, 'source'),
            Value::str($data, 'verdict'),
            Value::str($data, 'note'),
            Value::str($result, 'error'),
            Value::str($result, 'strict_refused'),
            Value::str($result, 'judged_without'),
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
     * Whether the row is worth a line of its own. A shipped patch needs
     * no action and is still worth saying: it can be deleted. Only a
     * patch that applies and is still required stays in the tally alone.
     */
    public function needsMention(): bool
    {
        return 'still-needed' !== $this->verdict;
    }

    public function isShipped(): bool
    {
        return self::SHIPPED === $this->verdict;
    }

    public function needsReroll(): bool
    {
        return self::NEEDS_REROLL === $this->verdict;
    }

    /**
     * What the row is called in output: its title, or the patch it names.
     */
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

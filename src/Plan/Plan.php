<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Plan;

/**
 * One upgrade plan as the api answered it.
 *
 * This is the one place the server's JSON becomes data. A field the
 * plugin does not know is ignored, so the api can add fields without
 * breaking installed sites; a body that is not a plan is refused here
 * rather than rendering as an empty report.
 */
final class Plan
{
    /**
     * @param array<string, int>   $counts
     * @param array<string, int>   $packageCounts
     * @param list<string>         $noRelease
     * @param list<PatchRow>       $patches
     * @param list<string>         $missingFiles
     * @param list<string>         $warnings
     * @param array<string, mixed> $raw           the body as received, for --json
     */
    private function __construct(
        public readonly string $targetCore,
        public readonly string $coreInstalled,
        public readonly bool $targetIsInstalled,
        public readonly string $bundleDate,
        public readonly array $counts,
        public readonly array $packageCounts,
        public readonly array $noRelease,
        public readonly array $patches,
        public readonly array $missingFiles,
        public readonly array $warnings,
        public readonly array $raw,
    ) {
    }

    /**
     * @param array<mixed> $decoded
     *
     * @throws InvalidPlan
     */
    public static function fromArray(array $decoded): self
    {
        $data = Value::keyed($decoded);
        if (!\array_key_exists('plan', $data)) {
            throw new InvalidPlan('the answer carries no plan, so the patches were not judged');
        }
        if (!\is_array($data['plan'])) {
            throw new InvalidPlan('the answer\'s plan is not an object');
        }
        $plan = Value::keyed($data['plan']);
        if (isset($plan['patches']) && !\is_array($plan['patches'])) {
            throw new InvalidPlan('the answer\'s patches are not a list');
        }

        $patches = [];
        foreach (Value::objects($plan, 'patches') as $row) {
            $patches[] = PatchRow::fromArray($row);
        }

        // The scan is the top level and the patch half is nested, so the
        // two tallies keep the name each answers to: `counts` on the
        // outside is package statuses, `counts` inside the plan is
        // verdicts.
        return new self(
            Value::str($data, 'target_core'),
            Value::str($data, 'core_installed'),
            Value::bool($data, 'target_is_installed'),
            Value::str($data, 'bundle_date'),
            Value::counts($plan, 'counts'),
            Value::counts($data, 'counts'),
            Value::strings($plan, 'no_release'),
            $patches,
            Value::strings($plan, 'missing_files'),
            Value::strings($plan, 'warnings'),
            $data,
        );
    }

    public function hasPatches(): bool
    {
        return [] !== $this->patches;
    }

    /**
     * The rows a person has to do something about, in plan order.
     *
     * @return list<PatchRow>
     */
    public function needingAction(): array
    {
        return \array_values(\array_filter($this->patches, static fn (PatchRow $row): bool => $row->needsAction()));
    }

    /**
     * The rows worth a line of their own, in plan order.
     *
     * @return list<PatchRow>
     */
    public function worthMentioning(): array
    {
        return \array_values(\array_filter($this->patches, static fn (PatchRow $row): bool => $row->needsMention()));
    }

    /**
     * The core the plan judged against, in the words the report uses.
     */
    public function against(): string
    {
        return '' === $this->targetCore ? 'the installed core' : $this->targetCore;
    }

    /**
     * True when a package has no release for the target and so blocks the
     * upgrade whatever its patches say.
     */
    public function isBlocked(): bool
    {
        return [] !== $this->noRelease;
    }
}

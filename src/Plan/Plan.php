<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Plan;

use RuntimeException;

/**
 * One upgrade plan as the api answered it. The one place the server's
 * JSON becomes data: unknown fields are ignored, a non-plan is refused.
 */
class Plan
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
        /** The package whose constraint decided the target, for `--target latest`. */
        public readonly string $targetFrom,
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
     * @throws RuntimeException
     */
    public static function fromArray(array $decoded): self
    {
        $data = $decoded;
        if (!\array_key_exists('plan', $data)) {
            throw new RuntimeException('the answer has no plan, so the patches were not judged');
        }
        if (!\is_array($data['plan'])) {
            throw new RuntimeException('the answer\'s plan is not an object');
        }
        $plan = $data['plan'];
        if (isset($plan['patches']) && !\is_array($plan['patches'])) {
            throw new RuntimeException('the answer\'s patches are not a list');
        }

        $patches = [];
        foreach ($plan['patches'] ?? [] as $row) {
            $patches[] = PatchRow::fromArray($row);
        }

        // `counts` outside is package statuses, `counts` inside the plan
        // is verdicts.
        return new self(
            (string) ($data['target_core'] ?? ''),
            (string) ($data['core_installed'] ?? ''),
            true === ($data['target_is_installed'] ?? null),
            (string) ($data['bundle_date'] ?? ''),
            (string) ($data['target_from'] ?? ''),
            (array) ($plan['counts'] ?? []),
            (array) ($data['counts'] ?? []),
            (array) ($plan['no_release'] ?? []),
            $patches,
            (array) ($plan['missing_files'] ?? []),
            (array) ($plan['warnings'] ?? []),
            $data,
        );
    }

    /**
     * The same plan narrowed to some packages, counts recomputed from the rows that are left.
     *
     * @param list<string> $packages composer names or drupal.org project names
     */
    public function onlyPackages(array $packages): self
    {
        if ([] === $packages) {
            return $this;
        }
        $wanted = [];
        foreach ($packages as $name) {
            $wanted[self::normalisePackage($name)] = true;
        }
        $patches = \array_values(\array_filter(
            $this->patches,
            static fn (PatchRow $row): bool => isset($wanted[self::normalisePackage($row->package)])
        ));
        $counts = [];
        foreach ($patches as $row) {
            $counts[$row->verdict] = ($counts[$row->verdict] ?? 0) + 1;
        }
        $noRelease = \array_values(\array_filter(
            $this->noRelease,
            static fn (string $name): bool => isset($wanted[self::normalisePackage($name)])
        ));
        // A warning about a package outside the scope is dropped; one
        // naming no package stays.
        $warnings = \array_values(\array_filter(
            $this->warnings,
            static function (string $warning) use ($wanted): bool {
                foreach ($wanted as $name => $_) {
                    if (\str_starts_with(self::normalisePackage($warning), $name.' ')) {
                        return true;
                    }
                }

                return !\str_contains(\explode(' ', $warning)[0], '/');
            }
        ));
        // --json owes the scope it was asked for, not the whole site.
        $raw = $this->raw;
        $raw['scope'] = $packages;
        if (isset($raw['plan']) && \is_array($raw['plan'])) {
            $nested = $raw['plan'];
            $nested['patches'] = \array_values(\array_filter(
                (array) ($nested['patches'] ?? []),
                static fn (array $row): bool => isset($wanted[self::normalisePackage((string) ($row['package'] ?? ''))])
            ));
            $nested['counts'] = $counts;
            $nested['no_release'] = $noRelease;
            $nested['warnings'] = $warnings;
            $raw['plan'] = $nested;
        }

        return new self(
            $this->targetCore,
            $this->coreInstalled,
            $this->targetIsInstalled,
            $this->bundleDate,
            $this->targetFrom,
            $counts,
            [],
            $noRelease,
            $patches,
            $this->missingFiles,
            $warnings,
            $raw,
        );
    }

    /**
     * One package name: `drupal/webform` and `webform` are the same thing to a person typing --package.
     */
    private static function normalisePackage(string $name): string
    {
        return \strtolower(\str_replace('drupal/', '', \trim($name)));
    }

    /**
     * The packages the plan has rows for, in plan order.
     *
     * @return list<string>
     */
    public function packages(): array
    {
        $seen = [];
        foreach ($this->patches as $row) {
            $seen[$row->package] = true;
        }

        return \array_keys($seen);
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
     * The scenario the run answered for, said in full.
     */
    public function scenario(): string
    {
        if ('' !== $this->targetFrom) {
            return 'for a move to core '.$this->against().' (the newest '.$this->targetFrom.' allows)';
        }
        if ($this->targetIsInstalled) {
            return 'against the releases this site installs';
        }

        return 'for a move to core '.$this->against();
    }

    public const CLEAN = 0;

    public const ACTION_NEEDED = 1;

    public const FAILED = 2;

    /**
     * The exit code: fails on a patch that will not apply or an unknown verdict; strict adds the unjudged and a vacuous run.
     */
    public function exitCode(bool $strict = false, bool $vacuous = false): int
    {
        if ($strict && $vacuous) {
            return self::ACTION_NEEDED;
        }
        foreach ($this->patches as $row) {
            if ($row->fails($strict)) {
                return self::ACTION_NEEDED;
            }
        }

        return self::CLEAN;
    }
}

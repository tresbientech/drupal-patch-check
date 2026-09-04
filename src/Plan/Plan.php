<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Plan;

use RuntimeException;
use TresBienTech\Drupatch\Scope;

/**
 * One upgrade plan as the api answered it. The one place the server's
 * JSON becomes data: unknown fields are ignored, a non-plan is refused.
 */
class Plan
{
    /**
     * @param array<string, int>    $counts
     * @param array<string, int>    $packageCounts
     * @param list<string>          $noRelease
     * @param array<string, string> $rowNotes
     * @param list<PatchRow>        $patches
     * @param list<string>          $missingFiles
     * @param list<string>          $warnings
     * @param array<string, mixed>  $raw           the body as received, for --json
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
        /** The sentence a blocked package's scan row carries, keyed by package. */
        public readonly array $rowNotes,
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
            throw new RuntimeException('the service\'s answer is not a plan');
        }
        $plan = $data['plan'];
        if (isset($plan['patches']) && !\is_array($plan['patches'])) {
            throw new RuntimeException('the answer\'s patches are not a list');
        }

        $patches = [];
        foreach ($plan['patches'] ?? [] as $row) {
            $patches[] = PatchRow::fromArray($row);
        }
        // The blocked packages and their sentences come from the scan
        // rows, which is where the service states them.
        $noRelease = [];
        $rowNotes = [];
        foreach ((array) ($data['rows'] ?? []) as $row) {
            $row = (array) $row;
            $package = (string) ($row['package'] ?? '');
            if ('' === $package || 'no_release' !== ($row['status'] ?? '')) {
                continue;
            }
            $noRelease[] = $package;
            if ('' !== ($note = (string) ($row['note'] ?? ''))) {
                $rowNotes[$package] = $note;
            }
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
            $noRelease,
            $rowNotes,
            $patches,
            (array) ($plan['missing_files'] ?? []),
            (array) ($plan['warnings'] ?? []),
            $data,
        );
    }

    /**
     * The same plan narrowed to a scope, counts recomputed from the rows that are left.
     */
    public function only(Scope $scope): self
    {
        if ($scope->isWhole()) {
            return $this;
        }
        $patches = \array_values(\array_filter(
            $this->patches,
            static fn (PatchRow $row): bool => $scope->has($row->package, $row->source)
        ));
        $counts = [];
        $left = [];
        foreach ($patches as $row) {
            $counts[$row->verdict] = ($counts[$row->verdict] ?? 0) + 1;
            $left[Scope::key($row->package)] = true;
        }
        // What is said about a package follows the packages named. When
        // only patches were named, it follows the packages their rows
        // belong to.
        $about = static fn (string $name): bool => [] === $scope->packages ? isset($left[Scope::key($name)]) : $scope->hasPackage($name);
        $noRelease = \array_values(\array_filter($this->noRelease, $about));
        // A warning about a package outside the scope is dropped; one
        // naming no package stays.
        $warnings = \array_values(\array_filter(
            $this->warnings,
            static function (string $warning) use ($about): bool {
                $first = \explode(' ', \trim($warning))[0];

                return !\str_contains($first, '/') || $about($first);
            }
        ));
        // --json owes the scope it was asked for, not the whole site.
        $raw = $this->raw;
        $raw['scope'] = $scope->packages;
        if ([] !== $scope->sources) {
            $raw['scope_patches'] = $scope->sources;
        }
        if (isset($raw['plan']) && \is_array($raw['plan'])) {
            $nested = $raw['plan'];
            $nested['patches'] = \array_values(\array_filter(
                (array) ($nested['patches'] ?? []),
                static fn (array $row): bool => $scope->has((string) ($row['package'] ?? ''), (string) ($row['source'] ?? ''))
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
            \array_filter($this->rowNotes, $about, \ARRAY_FILTER_USE_KEY),
            $patches,
            $this->missingFiles,
            $warnings,
            $raw,
        );
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
        if ($this->targetIsInstalled) {
            return 'against the releases this site installs';
        }
        $move = '' === $this->coreInstalled
            ? 'for a move to core '.$this->against()
            : 'for a move from core '.$this->coreInstalled.' to '.$this->against();
        if ('' !== $this->targetFrom) {
            return $move.' (the newest '.$this->targetFrom.' allows)';
        }

        return $move;
    }

    public const CLEAN = 0;

    public const ACTION_NEEDED = 1;

    public const FAILED = 2;

    /**
     * The exit code: fails on a patch whose verdict is none of merged,
     * applies or unknown. Strict fails on any row needing action, and on
     * a vacuous run.
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

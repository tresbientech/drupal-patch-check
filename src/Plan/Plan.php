<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Plan;

use RuntimeException;
use TresBienTech\Drupatch\Manager;
use TresBienTech\Drupatch\Read\Declaration;
use TresBienTech\Drupatch\Read\Scope;
use TresBienTech\Drupatch\Text;

/**
 * One upgrade plan as the api answered it. The one place the server's
 * JSON becomes data: unknown fields are ignored, a non-plan is refused.
 *
 * The plan holds the scope the run acts on, and its rows are read through it.
 */
class Plan
{
    /**
     * @param array<string, int>    $counts
     * @param list<string>          $noRelease
     * @param array<string, string> $rowNotes
     * @param list<PatchRow>        $patches      every row the service answered, whatever the scope
     * @param list<string>          $missingFiles
     * @param list<string>          $warnings
     * @param array<string, mixed>  $raw          the body as received, for --format=json
     */
    private function __construct(
        public readonly string $targetCore,
        public readonly string $coreInstalled,
        public readonly bool $targetIsInstalled,
        public readonly string $bundleDate,
        /** The package whose constraint decided the target, for `--target latest`. */
        public readonly string $targetFrom,
        private readonly array $counts,
        private readonly array $noRelease,
        /** The sentence a blocked package's scan row carries, keyed by package. */
        public readonly array $rowNotes,
        private readonly array $patches,
        public readonly array $missingFiles,
        private readonly array $warnings,
        private readonly array $raw,
        private readonly Scope $scope,
        /** The patch manager the rows are read under, null for the service's answer as sent. */
        public readonly ?Manager $manager,
    ) {
    }

    /**
     * @param array<mixed>      $decoded
     * @param list<Declaration> $declared the site's own declarations, in the order the request listed them
     * @param Manager|null      $manager  the patch manager each row is read under, null to read the service's answer as sent
     * @param Scope             $scope    what the run acts on
     *
     * @throws RuntimeException
     */
    public static function fromArray(array $decoded, array $declared, ?Manager $manager, Scope $scope): self
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
        foreach ($plan['patches'] ?? [] as $i => $row) {
            // The request carries no title and no path of the site's own, so
            // a row takes both from the declaration it was built from, over
            // anything the service answers for them. The service answers one
            // row per declaration, in the order it was sent them, and a row
            // past the end has none to read.
            $own = $declared[$i] ?? null;
            if (\is_array($row) && null !== $own) {
                $row['title'] = $own->title;
                $row['source'] = $own->source;
                // `--format=json` prints the answer as received, so a job
                // reading it needs the site's own words on the rows too.
                $data['plan']['patches'][$i] = $row;
            }
            $patches[] = PatchRow::fromArray($row, $manager);
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
            $noRelease,
            $rowNotes,
            $patches,
            (array) ($plan['missing_files'] ?? []),
            (array) ($plan['warnings'] ?? []),
            $data,
            $scope,
            $manager,
        );
    }

    /**
     * The rows in scope, in plan order.
     *
     * @return list<PatchRow>
     */
    public function rows(): array
    {
        return \array_values(\array_filter($this->patches, fn (PatchRow $row): bool => $this->scope->has($row->package, $row->source)));
    }

    /**
     * Patches per verdict: the service's tally for the whole site, counted again from the rows in a narrower scope.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        if ($this->scope->isWhole()) {
            return $this->counts;
        }
        $counts = [];
        foreach ($this->rows() as $row) {
            $counts[$row->verdict] = ($counts[$row->verdict] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * The blocked packages the scope is about.
     *
     * @return list<string>
     */
    public function noRelease(): array
    {
        return $this->scope->isWhole() ? $this->noRelease : \array_values(\array_filter($this->noRelease, $this->about(...)));
    }

    /**
     * The plan's warnings the scope is about: a warning about a package outside the scope is left out, and one naming no package stays.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        if ($this->scope->isWhole()) {
            return $this->warnings;
        }

        return \array_values(\array_filter(
            $this->warnings,
            function (string $warning): bool {
                $first = \explode(' ', \trim($warning))[0];

                return !\str_contains($first, '/') || $this->about($first);
            }
        ));
    }

    /**
     * Whether what is said about a package belongs to the scope. It follows the packages named, and when only patches were named, the packages their rows belong to.
     */
    private function about(string $package): bool
    {
        if ([] !== $this->scope->packages) {
            return $this->scope->hasPackage($package);
        }
        foreach ($this->rows() as $row) {
            if (Scope::key($row->package) === Scope::key($package)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The body as received, for --format=json, which owes the scope it was asked for rather than the whole site.
     *
     * @return array<string, mixed>
     */
    public function document(): array
    {
        if ($this->scope->isWhole()) {
            return $this->raw;
        }
        $raw = $this->raw;
        $raw['scope'] = $this->scope->packages;
        if ([] !== $this->scope->sources) {
            $raw['scope_patches'] = $this->scope->sources;
        }
        $nested = (array) $raw['plan'];
        $nested['patches'] = \array_values(\array_filter(
            (array) ($nested['patches'] ?? []),
            fn (array $row): bool => $this->scope->has((string) ($row['package'] ?? ''), (string) ($row['source'] ?? ''))
        ));
        $nested['counts'] = $this->counts();
        $nested['no_release'] = $this->noRelease();
        $nested['warnings'] = $this->warnings();
        $raw['plan'] = $nested;

        return $raw;
    }

    /**
     * The packages the service answered rows for, in plan order, whatever the scope.
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
        return [] !== $this->rows();
    }

    /**
     * The rows worth a line of their own, in plan order.
     *
     * @return list<PatchRow>
     */
    public function worthMentioning(): array
    {
        return \array_values(\array_filter($this->rows(), static fn (PatchRow $row): bool => $row->needsMention()));
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
            ? Text::t('for a move to core @target', ['@target' => $this->against()])
            : Text::t('for a move from core @installed to @target', ['@installed' => $this->coreInstalled, '@target' => $this->against()]);
        if ('' !== $this->targetFrom) {
            return Text::t('@move (the newest @constraint allows)', ['@move' => $move, '@constraint' => $this->targetFrom]);
        }

        return $move;
    }

    public const CLEAN = 0;

    public const ACTION_NEEDED = 1;

    public const FAILED = 2;

    /**
     * Every row with its patch number: its place in its package's declared order, counted from one.
     *
     * That order is the one the patch manager applies them in, so a later
     * patch is judged on top of the earlier ones. Numbers restart under each
     * package, which is why a report prints one under its package heading.
     *
     * @return list<array{0: PatchRow, 1: int}>
     */
    public function numbered(): array
    {
        $seen = [];
        $out = [];
        foreach ($this->rows() as $row) {
            $seen[$row->package] = ($seen[$row->package] ?? 0) + 1;
            $out[] = [$row, $seen[$row->package]];
        }

        return $out;
    }

    /**
     * The exit code: fails on a patch whose verdict is none of merged, applies or unknown.
     */
    public function exitCode(): int
    {
        foreach ($this->rows() as $row) {
            if ($row->fails()) {
                return self::ACTION_NEEDED;
            }
        }

        return self::CLEAN;
    }
}

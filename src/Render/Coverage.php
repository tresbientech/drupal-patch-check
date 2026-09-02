<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Render;

use TresBienTech\Drupatch\Site;

/**
 * What a run did not judge: the patches it never sent, and the ones it sent without their text.
 */
class Coverage
{
    /** @var array<string, array<string, int>> patches never sent, by package and reason */
    private readonly array $skippedGroups;

    /** @var array<string, array<string, int>> patches sent without their text, by package and reason */
    private readonly array $unsentGroups;

    /** @var list<string> */
    private readonly array $withheld;

    /**
     * @param int                                                                         $checked  patches the run asked for a verdict on
     * @param list<array{package: string, title: string, reason: string}>                 $skipped  narrowed to the packages asked for, since a package outside the scope is not the run's business
     * @param list<array{package: string, title: string, source: string, reason: string}> $unsent   every package: a note prints under the block of the package it names, and the transport check reads them all
     * @param array<string, string>                                                       $versions the version the lock pins, per package
     */
    public function __construct(
        private readonly int $checked,
        array $skipped,
        array $unsent,
        private readonly array $versions,
    ) {
        $this->skippedGroups = self::grouped($skipped);
        $this->unsentGroups = self::grouped($unsent);
        $this->withheld = \array_column($unsent, 'source');
    }

    /**
     * What a run covered, narrowed to the packages it was asked about.
     *
     * @param list<string> $packages composer names or short names, empty for the whole site
     */
    public static function of(Site $site, array $packages = []): self
    {
        $config = $site->patches();
        if ([] === $packages) {
            return new self(\count($config->patches), $config->skipped, $config->unsent, $site->installed());
        }

        $wanted = [];
        foreach ($packages as $name) {
            $wanted[self::shortName($name)] = true;
        }
        $checked = 0;
        foreach ($config->patches as $patch) {
            if (isset($wanted[self::shortName($patch['package'])])) {
                ++$checked;
            }
        }
        $skipped = [];
        foreach ($config->skipped as $entry) {
            if (isset($wanted[self::shortName($entry['package'])])) {
                $skipped[] = $entry;
            }
        }

        return new self($checked, $skipped, $config->unsent, $site->installed());
    }

    /**
     * A package named the way --package accepts it: either spelling of drupal/webform reduces to the same key.
     */
    private static function shortName(string $package): string
    {
        $slash = \strrpos($package, '/');

        return false === $slash ? $package : \substr($package, $slash + 1);
    }

    /**
     * Whether patches were declared and none of them could be checked.
     */
    public function isVacuous(): bool
    {
        return 0 === $this->checked && [] !== $this->skippedGroups;
    }

    /**
     * What one package's patches came to short of a verdict, one line per reason.
     *
     * @return list<string>
     */
    public function notesFor(string $package): array
    {
        $out = [];
        foreach ($this->skippedGroups[$package] ?? [] as $reason => $count) {
            $out[] = self::count($count, 'patch', 'patches').' skipped ('.$reason.')';
        }
        foreach ($this->unsentGroups[$package] ?? [] as $reason => $count) {
            $out[] = self::count($count, 'patch text', 'patch texts').' not sent ('.$reason.')';
        }

        return $out;
    }

    /**
     * The packages nothing was judged on, each said the way a package heading is said.
     *
     * @param list<string> $judged the packages the table has a block for
     *
     * @return list<string>
     */
    public function unjudged(array $judged): array
    {
        $seen = \array_flip($judged);
        $out = [];
        foreach ($this->skippedGroups as $package => $groups) {
            if (isset($seen[$package])) {
                continue;
            }
            // A site can declare a patch for a package it does not
            // install, and then there is no release to name.
            $version = $this->versions[$package] ?? '';
            $head = $package.('' === $version ? '' : ' '.$version).'   ';
            foreach ($groups as $reason => $count) {
                $out[] = $head.self::count($count, 'patch', 'patches').' skipped ('.$reason.')';
            }
        }

        return $out;
    }

    /**
     * The declared sources whose text the run held back, so a file the service missed anyway can be told apart from one kept back on purpose.
     *
     * @return list<string>
     */
    public function withheld(): array
    {
        return $this->withheld;
    }

    /**
     * A count with the noun it counts.
     */
    private static function count(int $count, string $one, string $many): string
    {
        return $count.' '.(1 === $count ? $one : $many);
    }

    /**
     * The entries counted per reason, under the package they are about, in the order they were declared.
     *
     * @param list<array{package: string, reason: string}> $entries
     *
     * @return array<string, array<string, int>>
     */
    private static function grouped(array $entries): array
    {
        $out = [];
        foreach ($entries as $entry) {
            $out[$entry['package']][$entry['reason']] = ($out[$entry['package']][$entry['reason']] ?? 0) + 1;
        }

        return $out;
    }
}

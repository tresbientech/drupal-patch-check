<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Render;

use TresBienTech\Drupatch\Header;
use TresBienTech\Drupatch\Scope;
use TresBienTech\Drupatch\Site;
use TresBienTech\Drupatch\Text;

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
     * @param list<string>                                                                $edited   copies whose body no longer hashes to their header
     */
    public function __construct(
        private readonly int $checked,
        array $skipped,
        array $unsent,
        private readonly array $versions,
        private readonly array $edited = [],
    ) {
        $this->skippedGroups = self::grouped($skipped);
        $this->unsentGroups = self::grouped($unsent);
        $this->withheld = \array_column($unsent, 'source');
    }

    /**
     * The copies whose body no longer hashes to the header a pin run wrote, so the file holds something else now.
     *
     * @param array<string, string> $files patch text by the source the site declared
     *
     * @return list<string>
     */
    public static function editedCopies(array $files): array
    {
        $out = [];
        foreach ($files as $source => $text) {
            $header = Header::read($text);
            if (isset($header['sha256']) && $header['sha256'] !== Header::hash(Header::body($text))) {
                $out[] = $source;
            }
        }

        return $out;
    }

    /**
     * What a run covered, narrowed to the scope it was asked about.
     */
    public static function of(Site $site, Scope $scope): self
    {
        $config = $site->patches();
        $edited = self::editedCopies($config->files);
        if ($scope->isWhole()) {
            return new self(\count($config->patches), $config->skipped, $config->unsent, $site->installed(), $edited);
        }

        $checked = 0;
        foreach ($config->patches as $patch) {
            if ($scope->has($patch['package'], $patch['source'])) {
                ++$checked;
            }
        }
        $skipped = [];
        foreach ($config->skipped as $entry) {
            if ($scope->hasPackage($entry['package'])) {
                $skipped[] = $entry;
            }
        }

        return new self($checked, $skipped, $config->unsent, $site->installed(), $edited);
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
            $out[] = self::skippedNote($count, $reason);
        }
        foreach ($this->unsentGroups[$package] ?? [] as $reason => $count) {
            $out[] = Text::plural($count, '@count patch text not sent (@reason)', '@count patch texts not sent (@reason)', ['reason' => $reason]);
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
            $heading = '' === $version ? $package : Text::t('@package @version', ['package' => $package, 'version' => $version]);
            foreach ($groups as $reason => $count) {
                $out[] = Text::t('@heading   @skipped', ['heading' => $heading, 'skipped' => self::skippedNote($count, $reason)]);
            }
        }

        return $out;
    }

    /**
     * The copies a person edited after the site took them.
     *
     * @return list<string>
     */
    public function edited(): array
    {
        return $this->edited;
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
     * What one reason kept from a verdict, as it prints under a package.
     */
    private static function skippedNote(int $count, string $reason): string
    {
        return Text::plural($count, '@count patch skipped (@reason)', '@count patches skipped (@reason)', ['reason' => $reason]);
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

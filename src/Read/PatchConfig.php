<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Read;

use TresBienTech\Drupatch\Fetch\PatchText;
use TresBienTech\Drupatch\Manager;
use TresBienTech\Drupatch\Plugin;
use TresBienTech\Drupatch\Source\Provenance;
use TresBienTech\Drupatch\Text;

/**
 * A site's patch declarations: the patches cweagans/composer-patches applies, the text of the local ones, and anything the reader could not read.
 */
class PatchConfig
{
    /** The document that holds declarations under `extra.patches`. */
    public const COMPOSER_JSON = 'composer.json';

    /** A title-keyed map of source strings, the shape 1.x reads and 2.x still accepts. */
    public const COMPACT = 'compact';

    /** A list of objects carrying `description` and `url`, which 2.x alone reads. */
    public const EXPANDED = 'expanded';

    /** Where an expanded declaration keeps the patch's title. */
    public const TITLE_KEY = 'description';

    /** Where an expanded declaration keeps the patch's source. */
    public const SOURCE_KEY = 'url';

    /** Largest number of patch texts sent in one call. */
    private const MAX_PATCH_FILES = 100;

    /**
     * @param list<array{package: string, title: string, source: string, file: string, shape: string, provenance: array<string, string>}> $patches
     * @param array<string, string>                                                                                                       $files
     * @param list<string>                                                                                                                $notes
     * @param list<array{package: string, title: string, reason: string}>                                                                 $skipped one entry per declared patch the run did not judge
     * @param list<array{package: string, title: string, source: string, reason: string}>                                                 $unsent  one entry per patch whose text did not fit
     */
    public function __construct(
        public readonly array $patches,
        public readonly array $files,
        public readonly array $notes,
        public readonly array $skipped,
        public readonly array $unsent,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->patches;
    }

    /**
     * Reads what the site declares, from composer.json and from the patches file its manager reads.
     *
     * @param PatchText             $text       turns a declared source into the text that travels
     * @param int                   $textBudget bytes the patch text may occupy in the request
     * @param array<string, string> $checkable  packages the service can judge, to their versions
     * @param array<string, mixed>  $extra      the root package's extra
     */
    public static function read(PatchText $text, int $textBudget, array $checkable, array $extra, string $root, Manager $manager): self
    {
        $notes = [];

        $patches = [];
        $files = [];
        $unsent = [];
        $skipped = [];
        $spent = 0;
        foreach (self::declarations($extra, $root, $manager) as $declaration) {
            ['package' => $package, 'title' => $title, 'source' => $source] = $declaration;
            if ('' === $source) {
                continue;
            }
            if (!isset($checkable[$package])) {
                $skipped[] = ['package' => $package, 'title' => $title, 'reason' => 'not a drupal.org project'];
                continue;
            }
            if (isset($files[$source])) {
                $patches[] = $declaration;
                continue;
            }
            if (\count($files) >= self::MAX_PATCH_FILES) {
                $patches[] = $declaration;
                $unsent[] = self::withheld($package, $title, $source, 'more than '.self::MAX_PATCH_FILES.' patch texts');
                continue;
            }
            $read = $text->read($source);
            if ($read['withheld']) {
                $patches[] = $declaration;
                $unsent[] = self::withheld($package, $title, $source, $read['reason']);
                continue;
            }
            if ('' !== $read['reason']) {
                // 2.x ships `composer patches-doctor`, which reports an
                // unreachable source itself.
                $skipped[] = ['package' => $package, 'title' => $title, 'reason' => $manager->isTwo() ? '' : $read['reason']];
                continue;
            }
            // What the texts cost once escaped, which is what the request
            // is measured in.
            $cost = 0;
            foreach ($read['files'] as $body) {
                $cost += \strlen(\json_encode($body, \JSON_THROW_ON_ERROR));
            }
            $patches[] = $declaration;
            if ($spent + $cost > $textBudget) {
                $unsent[] = self::withheld($package, $title, $source, 'the request was full; narrow with --package to check it');
                continue;
            }
            $spent += $cost;
            $files += $read['files'];
        }

        if (isset($extra[Plugin::EXTRA]['private-paths'])) {
            $notes[] = Text::t('extra.@key.private-paths is no longer read: no path of your own leaves the site', ['@key' => Plugin::EXTRA]);
        }

        return new self($patches, $files, \array_values(\array_unique($notes)), $skipped, $unsent);
    }

    /**
     * Every patch the site declares, whatever package it is on. `read()` narrows this to what the service can judge; a pin run acts on all of it.
     *
     * @param array<string, mixed> $extra the root package's extra
     *
     * @return list<array{package: string, title: string, source: string, file: string, shape: string, provenance: array<string, string>}>
     */
    public static function declared(array $extra, string $root, Manager $manager): array
    {
        return \array_values(\array_filter(
            self::declarations($extra, $root, $manager),
            static fn (array $declaration): bool => '' !== $declaration['source'],
        ));
    }

    public static function isUrl(string $source): bool
    {
        $s = \strtolower(\trim($source));

        return \str_starts_with($s, 'http://') || \str_starts_with($s, 'https://');
    }

    /**
     * Every patch the site declares, from both places the patch manager reads: `extra.patches` in composer.json, and the file the patches-file key names.
     *
     * @param array<string, mixed> $extra
     *
     * @return list<array{package: string, title: string, source: string, file: string, shape: string, provenance: array<string, string>}>
     */
    private static function declarations(array $extra, string $root, Manager $manager): array
    {
        // A site writes both of these by hand, so anything can be in them.
        $out = self::fromMap($extra['patches'] ?? null, self::COMPOSER_JSON);
        $file = $manager->patchesFile($extra);
        if ('' === $file) {
            return $out;
        }
        $text = @\file_get_contents($root.\DIRECTORY_SEPARATOR.$file);
        $decoded = false === $text ? null : \json_decode($text, true);

        return [...$out, ...self::unheld($out, self::fromMap(\is_array($decoded) ? ($decoded['patches'] ?? null) : null, $file))];
    }

    /**
     * The entries of the second document whose package does not already declare that source.
     *
     * The patch manager reads composer.json first and refuses a patch whose
     * URL a package already holds, so a site naming one patch in both
     * places applies it once and has to be judged once.
     *
     * @param list<array{package: string, title: string, source: string, file: string, shape: string, provenance: array<string, string>}> $held
     * @param list<array{package: string, title: string, source: string, file: string, shape: string, provenance: array<string, string>}> $adding
     *
     * @return list<array{package: string, title: string, source: string, file: string, shape: string, provenance: array<string, string>}>
     */
    private static function unheld(array $held, array $adding): array
    {
        $seen = [];
        foreach ($held as $declaration) {
            $seen[$declaration['package']."\0".$declaration['source']] = true;
        }
        $out = [];
        foreach ($adding as $declaration) {
            $key = $declaration['package']."\0".$declaration['source'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $declaration;
            }
        }

        return $out;
    }

    /**
     * One package-keyed map of declarations, in either shape the patch manager accepts: a title-keyed map of source strings, or a list of objects carrying `description` and `url`.
     *
     * @param string $file where the map was read: composer.json or the patches file
     *
     * @return list<array{package: string, title: string, source: string, file: string, shape: string, provenance: array<string, string>}>
     */
    private static function fromMap(mixed $map, string $file): array
    {
        if (!\is_array($map)) {
            return [];
        }
        $out = [];
        foreach ($map as $package => $entries) {
            if (!\is_string($package) || !\is_array($entries)) {
                continue;
            }
            foreach ($entries as $title => $entry) {
                [$title, $source] = \is_array($entry)
                    ? [$entry[self::TITLE_KEY] ?? null, $entry[self::SOURCE_KEY] ?? null]
                    : [$title, $entry];
                if (\is_string($title) && \is_string($source)) {
                    $out[] = [
                        'package' => $package,
                        'title' => $title,
                        'source' => \trim($source),
                        'file' => $file,
                        'shape' => \is_array($entry) ? self::EXPANDED : self::COMPACT,
                        // The compact map holds no `extra`, so an entry
                        // written in it carries no provenance.
                        'provenance' => \is_array($entry) ? Provenance::read($entry['extra'] ?? null) : [],
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * One patch the run declined to send the text of.
     *
     * @return array{package: string, title: string, source: string, reason: string}
     */
    private static function withheld(string $package, string $title, string $source, string $reason): array
    {
        return ['package' => $package, 'title' => $title, 'source' => $source, 'reason' => $reason];
    }
}

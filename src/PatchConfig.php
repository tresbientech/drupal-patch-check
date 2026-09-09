<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch;

/**
 * A site's patch declarations: the patches cweagans/composer-patches applies, the text of the local ones, and anything the reader could not read.
 */
class PatchConfig
{
    /** Largest number of patch texts sent in one call. */
    private const MAX_PATCH_FILES = 100;

    /**
     * @param list<array{package: string, title: string, source: string}>                 $patches
     * @param array<string, string>                                                       $files
     * @param list<string>                                                                $notes
     * @param list<array{package: string, title: string, reason: string}>                 $skipped one entry per declared patch the run did not judge
     * @param list<array{package: string, title: string, source: string, reason: string}> $unsent  one entry per patch whose text did not fit
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
     * Reads what the site declares under `extra.patches`.
     *
     * @param PatchText             $text       turns a declared source into the text that travels
     * @param int                   $textBudget bytes the patch text may occupy in the request
     * @param array<string, string> $checkable  packages the service can judge, to their versions
     * @param array<string, mixed>  $extra      the root package's extra
     */
    public static function read(PatchText $text, int $textBudget, array $checkable, array $extra): self
    {
        $notes = [];

        $patches = [];
        $files = [];
        $unsent = [];
        $skipped = [];
        $spent = 0;
        foreach (self::declarations($extra) as [$package, $title, $source]) {
            if ('' === $source) {
                continue;
            }
            if (!isset($checkable[$package])) {
                $skipped[] = ['package' => $package, 'title' => $title, 'reason' => 'not a drupal.org project'];
                continue;
            }
            if (isset($files[$source])) {
                $patches[] = ['package' => $package, 'title' => $title, 'source' => $source];
                continue;
            }
            if (\count($files) >= self::MAX_PATCH_FILES) {
                $patches[] = ['package' => $package, 'title' => $title, 'source' => $source];
                $unsent[] = self::withheld($package, $title, $source, 'more than '.self::MAX_PATCH_FILES.' patch texts');
                continue;
            }
            $read = $text->read($source);
            if ($read['withheld']) {
                $patches[] = ['package' => $package, 'title' => $title, 'source' => $source];
                $unsent[] = self::withheld($package, $title, $source, $read['reason']);
                continue;
            }
            if ('' !== $read['reason']) {
                $skipped[] = ['package' => $package, 'title' => $title, 'reason' => $read['reason']];
                continue;
            }
            // What the texts cost once escaped, which is what the request
            // is measured in.
            $cost = 0;
            foreach ($read['files'] as $body) {
                $cost += \strlen(\json_encode($body, \JSON_THROW_ON_ERROR));
            }
            $patches[] = ['package' => $package, 'title' => $title, 'source' => $source];
            if ($spent + $cost > $textBudget) {
                $unsent[] = self::withheld($package, $title, $source, 'the request was full; narrow with --package to check it');
                continue;
            }
            $spent += $cost;
            $files += $read['files'];
        }

        if (isset($extra[Plugin::EXTRA]['private-paths'])) {
            $notes[] = Text::t('extra.@key.private-paths is no longer read: no path of your own leaves the site', ['key' => Plugin::EXTRA]);
        }

        return new self($patches, $files, \array_values(\array_unique($notes)), $skipped, $unsent);
    }

    /**
     * Every patch the site declares, whatever package it is on. `read()` narrows this to what the service can judge; a pin run acts on all of it.
     *
     * @param array<string, mixed> $extra the root package's extra
     *
     * @return list<array{package: string, title: string, source: string}>
     */
    public static function declared(array $extra): array
    {
        $out = [];
        foreach (self::declarations($extra) as [$package, $title, $source]) {
            if ('' !== $source) {
                $out[] = ['package' => $package, 'title' => $title, 'source' => $source];
            }
        }

        return $out;
    }

    public static function isUrl(string $source): bool
    {
        $s = \strtolower(\trim($source));

        return \str_starts_with($s, 'http://') || \str_starts_with($s, 'https://');
    }

    /**
     * Every patch `extra.patches` declares, as [package, title, source] triples. The shape read is one title-keyed map of source strings per package, which is what cweagans/composer-patches writes.
     *
     * @param array<string, mixed> $extra
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private static function declarations(array $extra): array
    {
        // A site writes this file by hand, so anything can be in it.
        $patches = $extra['patches'] ?? null;
        if (!\is_array($patches)) {
            return [];
        }
        $out = [];
        foreach ($patches as $package => $entries) {
            if (!\is_string($package) || !\is_array($entries)) {
                continue;
            }
            foreach ($entries as $title => $source) {
                if (\is_string($title) && \is_string($source)) {
                    $out[] = [$package, $title, \trim($source)];
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

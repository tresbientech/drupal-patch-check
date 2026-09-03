<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch;

/**
 * A site's patch declarations, read from whichever patch manager wrote them: the patches it applies, the text of the local ones, and anything the reader could not read.
 */
class PatchConfig
{
    /** Largest number of patch texts sent in one call. */
    private const MAX_PATCH_FILES = 100;

    /** Largest external patches file read. */
    private const MAX_PATCHES_FILE_BYTES = 1024 * 1024;

    /** Packages kept out of the unread-configuration note. */
    private const HANDLED = [
        'cweagans/composer-patches',
        'vaimo/composer-patches',
        'szeidler/composer-patches-cli',
        'drupal/core-composer-scaffold',
    ];

    /** Keys an entry written as an object may name its patch with. */
    private const SOURCE_KEYS = ['url', 'source', 'path', 'patch'];

    /** Keys an entry written as an object may name itself with. */
    private const TITLE_KEYS = ['description', 'title', 'label'];

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
        /** Path of the external patches file, empty when the declarations are inline. */
        public readonly string $file,
        public readonly array $skipped,
        public readonly array $unsent,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->patches;
    }

    /**
     * Reads the declarations of the site at `$root`.
     *
     * @param PatchText             $text       turns a declared source into the text that travels
     * @param int                   $textBudget bytes the patch text may occupy in the request
     * @param array<string, string> $checkable  packages the service can judge, to their versions
     * @param array<string, mixed>  $extra      the root package's extra
     * @param list<string>          $installed  installed package names
     */
    public static function read(string $root, PatchText $text, int $textBudget, array $checkable, array $extra, array $installed = []): self
    {
        $notes = [];
        $file = '';
        $declarations = self::declarations($root, $extra, $notes, $file);
        $ignored = self::ignored($extra);

        $patches = [];
        $files = [];
        $unsent = [];
        $skipped = [];
        $spent = 0;
        foreach ($declarations as [$package, $title, $source]) {
            if ('' === $source || isset($ignored[$package."\0".$title])) {
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
                $unsent[] = self::withheld($package, $title, $source, 'no room left under the service body limit');
                continue;
            }
            $spent += $cost;
            $files += $read['files'];
        }

        if (isset($extra['patches-search'])) {
            $notes[] = 'extra.patches-search is not read: patches found only by directory scan are left out';
        }
        foreach ($installed as $name) {
            if (\str_contains($name, 'composer-patches') && !\in_array($name, self::HANDLED, true)) {
                $notes[] = $name.' is installed and its patch configuration is not read';
            }
        }

        return new self($patches, $files, \array_values(\array_unique($notes)), $file, $skipped, $unsent);
    }

    /**
     * The title of one entry: its key when it has one, otherwise what the object calls itself.
     */
    public static function entryTitle(int|string $key, mixed $entry): string
    {
        if (\is_string($key)) {
            return $key;
        }
        if (\is_array($entry)) {
            foreach (self::TITLE_KEYS as $titleKey) {
                if (\is_string($entry[$titleKey] ?? null)) {
                    return $entry[$titleKey];
                }
            }
        }

        return '';
    }

    /**
     * The patch an entry names: the string itself, or the object's url, source, path or patch.
     */
    public static function entrySource(mixed $entry): string
    {
        if (\is_string($entry)) {
            return \trim($entry);
        }
        if (!\is_array($entry)) {
            return '';
        }
        foreach (self::SOURCE_KEYS as $sourceKey) {
            if (\is_string($entry[$sourceKey] ?? null)) {
                return \trim($entry[$sourceKey]);
            }
        }

        return '';
    }

    /**
     * The same entry pointing at a different patch, keeping everything else it said.
     */
    public static function entryWithSource(mixed $entry, string $source): mixed
    {
        if (!\is_array($entry)) {
            return $source;
        }
        foreach (self::SOURCE_KEYS as $sourceKey) {
            if (isset($entry[$sourceKey])) {
                $entry[$sourceKey] = $source;

                return $entry;
            }
        }
        $entry['url'] = $source;

        return $entry;
    }

    public static function isUrl(string $source): bool
    {
        $s = \strtolower(\trim($source));

        return \str_starts_with($s, 'http://') || \str_starts_with($s, 'https://');
    }

    /**
     * Every declared patch as [package, title, source], from the external file when the site keeps one, otherwise from composer.json.
     *
     * @param array<string, mixed> $extra
     * @param list<string>         $notes
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private static function declarations(string $root, array $extra, array &$notes, string &$file): array
    {
        $declared = $extra['patches-file'] ?? null;
        if (\is_string($declared) && '' !== $declared) {
            $full = PatchText::under($root, $declared);
            if (null === $full || \filesize($full) > self::MAX_PATCHES_FILE_BYTES) {
                $notes[] = 'extra.patches-file points at '.$declared.', which could not be read';
            } else {
                $decoded = \json_decode((string) \file_get_contents($full), true);
                if (!\is_array($decoded)) {
                    $notes[] = 'extra.patches-file '.$declared.' is not readable JSON';
                } else {
                    $file = $declared;

                    return self::flatten($decoded['patches'] ?? $decoded);
                }
            }
        }

        return self::flatten($extra['patches'] ?? null);
    }

    /**
     * Turns any declaration shape into [package, title, source] triples: a title-keyed map, a list, and entries written as strings or as objects naming their url, path or source.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private static function flatten(mixed $patches): array
    {
        if (!\is_array($patches)) {
            return [];
        }
        $out = [];
        foreach ($patches as $package => $entries) {
            if (!\is_string($package) || !\is_array($entries)) {
                continue;
            }
            foreach ($entries as $key => $entry) {
                if (!\is_string($entry) && !\is_array($entry)) {
                    continue;
                }
                $out[] = [$package, self::entryTitle($key, $entry), self::entrySource($entry)];
            }
        }

        return $out;
    }

    /**
     * The (package, title) pairs an ignore list drops, whichever dependency asked for them to be ignored.
     *
     * @param array<string, mixed> $extra
     *
     * @return array<string, true>
     */
    private static function ignored(array $extra): array
    {
        $ignore = $extra['patches-ignore'] ?? null;
        if (!\is_array($ignore)) {
            return [];
        }
        $out = [];
        foreach ($ignore as $byDependency) {
            foreach (self::flatten($byDependency) as [$package, $title, $_]) {
                $out[$package."\0".$title] = true;
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

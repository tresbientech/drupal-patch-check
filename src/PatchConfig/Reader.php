<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\PatchConfig;

/**
 * Reads a site's patch declarations, whichever patch manager wrote them.
 *
 * Handled: the inline `extra.patches` map, an external patches file, an
 * ignore list, and entries written as objects rather than strings. Every
 * shape resolves to the same list of patches, so the server never has to
 * know which manager a site uses.
 *
 * Only patches on checkable packages are resolved. The check judges a
 * patch against a drupal.org release, so a patch on any other package
 * has nothing to be judged against, and its text stays on the site. So
 * does a patch the service would refuse to fetch.
 */
final class Reader
{
    /**
     * Largest patch file read from disk, the same cap the service applies
     * to a patch it fetches from a URL.
     */
    private const MAX_PATCH_BYTES = 16 * 1024 * 1024;

    /** Largest number of local patch files sent in one call. */
    private const MAX_PATCH_FILES = 100;

    /** Largest external patches file read. */
    private const MAX_PATCHES_FILE_BYTES = 1024 * 1024;

    /** Managers whose configuration the shapes below cover. */
    private const HANDLED = [
        'cweagans/composer-patches',
        'vaimo/composer-patches',
        'szeidler/composer-patches-cli',
        'drupal/core-composer-scaffold',
    ];

    /**
     * @param int                   $textBudget bytes the patch text may occupy in the request
     * @param array<string, string> $checkable  packages the service can judge, to their versions
     */
    public function __construct(
        private readonly string $root,
        private readonly int $textBudget,
        private readonly array $checkable,
    ) {
    }

    /**
     * @param array<string, mixed> $extra
     * @param list<string>         $installedPackages
     */
    public function read(array $extra, array $installedPackages = []): Resolution
    {
        $notes = [];
        $file = '';
        $declarations = $this->declarations($extra, $notes, $file);
        $ignored = self::ignored($extra);

        $patches = [];
        $files = [];
        $unsent = [];
        $heldBack = [];
        $spent = 0;
        foreach ($declarations as $entry) {
            [$package, $title, $source] = $entry;
            if ('' === $source || isset($ignored[$package."\0".$title])) {
                continue;
            }
            if (!isset($this->checkable[$package])) {
                $heldBack[] = self::names($package, $title);
                continue;
            }
            if (Entry::isUrl($source) && !Entry::isFetchable($source)) {
                $heldBack[] = self::names($package, $title).': the service does not fetch from that host';
                continue;
            }
            $patches[] = ['package' => $package, 'title' => $title, 'source' => $source];
            if (Entry::isUrl($source) || isset($files[$source])) {
                continue;
            }
            if (\count($files) >= self::MAX_PATCH_FILES) {
                $unsent[] = self::names($package, $title).': more than '.self::MAX_PATCH_FILES.' local patch files';
                continue;
            }
            $size = $this->sizeOf($source);
            if (null === $size) {
                continue;
            }
            if ($size > self::MAX_PATCH_BYTES) {
                $unsent[] = self::names($package, $title).': '.$size.' bytes, above the '.self::MAX_PATCH_BYTES.' byte cap';
                continue;
            }
            $text = $this->readFile($source);
            if (null === $text) {
                continue;
            }
            // What the text costs once escaped, which is what the request
            // is measured in.
            $cost = \strlen(\json_encode($text, \JSON_THROW_ON_ERROR));
            if ($spent + $cost > $this->textBudget) {
                $unsent[] = self::names($package, $title).': no room left under the service body limit';
                continue;
            }
            $spent += $cost;
            $files[$source] = $text;
        }

        if (isset($extra['patches-search'])) {
            $notes[] = 'extra.patches-search is not read: patches found only by directory scan are left out';
        }
        foreach (self::unhandledManagers($installedPackages) as $manager) {
            $notes[] = $manager.' is installed and its patch configuration is not read';
        }

        return new Resolution($patches, $files, $notes, $file, $heldBack, $unsent);
    }

    /**
     * Every declared patch as [package, title, source], from the external
     * file when the site keeps one, otherwise from composer.json.
     *
     * @param array<string, mixed> $extra
     * @param list<string>         $notes
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function declarations(array $extra, array &$notes, string &$file): array
    {
        $declared = $extra['patches-file'] ?? null;
        if (\is_string($declared) && '' !== $declared) {
            $fromFile = $this->readPatchesFile($declared, $notes);
            if (null !== $fromFile) {
                $file = $declared;

                return $fromFile;
            }
        }

        return self::flatten($extra['patches'] ?? null);
    }

    /**
     * @param list<string> $notes
     *
     * @return list<array{0: string, 1: string, 2: string}>|null
     */
    private function readPatchesFile(string $path, array &$notes): ?array
    {
        $full = $this->resolve($path);
        if (null === $full || \filesize($full) > self::MAX_PATCHES_FILE_BYTES) {
            $notes[] = 'extra.patches-file names '.$path.', which could not be read';

            return null;
        }
        $decoded = \json_decode((string) \file_get_contents($full), true);
        if (!\is_array($decoded)) {
            $notes[] = 'extra.patches-file '.$path.' is not readable JSON';

            return null;
        }

        return self::flatten($decoded['patches'] ?? $decoded);
    }

    /**
     * Turns any declaration shape into [package, title, source] triples:
     * a title-keyed map, a list, and entries written as strings or as
     * objects naming their url, path or source.
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
                $out[] = [$package, Entry::title($key, $entry), Entry::source($entry)];
            }
        }

        return $out;
    }

    /**
     * The (package, title) pairs an ignore list drops, whichever
     * dependency asked for them to be ignored.
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
     * Installed patch managers whose configuration this reader does not
     * cover, so a site is told rather than left with silence.
     *
     * @param list<string> $installedPackages
     *
     * @return list<string>
     */
    private static function unhandledManagers(array $installedPackages): array
    {
        $out = [];
        foreach ($installedPackages as $name) {
            if (\str_contains($name, 'composer-patches') && !\in_array($name, self::HANDLED, true)) {
                $out[] = $name;
            }
        }

        return \array_values(\array_unique($out));
    }

    /**
     * How a patch is named in a line about it.
     */
    private static function names(string $package, string $title): string
    {
        return $package.' "'.$title.'"';
    }

    /**
     * The size of a declared patch, or null when the path does not name a
     * file under the site root.
     */
    private function sizeOf(string $source): ?int
    {
        $full = $this->resolve($source);
        if (null === $full) {
            return null;
        }
        $size = \filesize($full);

        return false === $size ? null : $size;
    }

    /**
     * Reads a file under the site root. A path leaving the root is
     * refused: composer.json names the site's own files.
     */
    private function readFile(string $source): ?string
    {
        $full = $this->resolve($source);
        if (null === $full) {
            return null;
        }
        $text = @\file_get_contents($full);

        return false === $text ? null : $text;
    }

    private function resolve(string $source): ?string
    {
        $path = \realpath($this->root.\DIRECTORY_SEPARATOR.\ltrim($source, '/\\'));
        if (false === $path || !\is_file($path)) {
            return null;
        }

        return \str_starts_with($path, $this->root.\DIRECTORY_SEPARATOR) ? $path : null;
    }
}

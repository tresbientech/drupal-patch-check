<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Write;

use Closure;
use Composer\Json\JsonManipulator;
use Composer\Pcre\PcreException;
use RuntimeException;
use TresBienTech\Drupatch\Manager;
use TresBienTech\Drupatch\Plan\PatchRow;
use TresBienTech\Drupatch\Read\PatchConfig;
use TresBienTech\Drupatch\Settings;
use TresBienTech\Drupatch\Source\Provenance;

/**
 * The two documents a **manager upgrade** writes: the patches file the site moves into, and the composer.json that points at it.
 */
class Upgrade
{
    /** What the requirement becomes. */
    public const CONSTRAINT = '^2';

    /**
     * The patches file this site moves into, in the expanded shape, with a depth on the patches that need one.
     *
     * Every declaration moves, so the file is the whole set and the order
     * inside each package is the order the manager applies them in.
     *
     * @param list<array{package: string, title: string, source: string, provenance: array<string, string>}> $declarations
     * @param array<string, int>                                                                             $depths       the measured depth to record, keyed by PatchRow::keyOf
     *
     * @return string the file's whole text, ending in a newline
     */
    public static function patchesFile(array $declarations, array $depths): string
    {
        $patches = [];
        foreach ($declarations as $declaration) {
            $entry = [PatchConfig::TITLE_KEY => $declaration['title'], PatchConfig::SOURCE_KEY => $declaration['source']];
            $depth = $depths[PatchRow::keyOf($declaration['package'], $declaration['title'])] ?? null;
            if (null !== $depth) {
                $entry['depth'] = $depth;
            }
            if ([] !== $declaration['provenance']) {
                $entry['extra'] = [Provenance::KEY => $declaration['provenance']];
            }
            $patches[$declaration['package']][] = $entry;
        }

        return \json_encode(['patches' => $patches], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * The site's composer.json after the move: no declarations, the 2.x settings block, and the requirement raised.
     *
     * Every other key, the key order and the file's indentation stay as they
     * were, so the diff a person reviews holds the move alone.
     *
     * @param array<string, mixed> $extra the root package's extra, as it stands
     * @param string               $file  where the declarations went
     */
    public static function intoComposerJson(string $text, array $extra, string $file): string
    {
        $manipulator = new JsonManipulator($text);
        self::must(static fn (): bool => $manipulator->addLink('require', Manager::PACKAGE, self::CONSTRAINT), 'the requirement could not be raised');
        self::must(static fn (): bool => $manipulator->removeSubNode('extra', 'patches'), 'extra.patches could not be removed');
        foreach (\array_keys(Settings::dropped($extra)) as $key) {
            self::must(static fn (): bool => $manipulator->removeSubNode('extra', $key), 'extra.'.$key.' could not be removed');
        }
        // What 2.x keeps of its own: the settings that carried over, and
        // the file the declarations moved to.
        $block = [];
        foreach (Settings::renamed($extra) as $from => $to) {
            self::must(static fn (): bool => $manipulator->removeSubNode('extra', $from), 'extra.'.$from.' could not be removed');
            $block[$to] = $extra[$from];
        }
        $block['patches-file'] = $file;
        self::must(static fn (): bool => $manipulator->addSubNode('extra', Settings::KEY, $block), 'extra.'.Settings::KEY.' could not be written');

        return $manipulator->getContents();
    }

    /**
     * The site's composer.json as the move leaves it, built before the run writes anything.
     *
     * @param array<string, mixed> $extra the root package's extra, as it stands
     * @param string               $file  where the declarations go
     *
     * @throws RuntimeException when composer.json cannot be read or composer refuses an edit
     */
    public static function composerJsonFor(string $root, array $extra, string $file): string
    {
        $text = @\file_get_contents($root.\DIRECTORY_SEPARATOR.PatchConfig::COMPOSER_JSON);
        if (false === $text) {
            throw new RuntimeException('composer.json is not readable');
        }

        return self::intoComposerJson($text, $extra, $file);
    }

    /**
     * Puts both documents on disk: the patches file first, so a composer.json pointing at a file that is not there never exists.
     *
     * @param list<array{package: string, title: string, source: string, provenance: array<string, string>}> $declarations
     * @param array<string, int>                                                                             $depths
     * @param string                                                                                         $composerJson the text composerJsonFor built
     *
     * @throws RuntimeException when either document could not be written
     *
     * @return list<string> the files written, in that order
     */
    public static function writeTo(string $root, string $file, array $declarations, array $depths, string $composerJson): array
    {
        self::put($root.\DIRECTORY_SEPARATOR.$file, self::patchesFile($declarations, $depths), $file);
        self::put($root.\DIRECTORY_SEPARATOR.PatchConfig::COMPOSER_JSON, $composerJson, PatchConfig::COMPOSER_JSON);

        return [$file, PatchConfig::COMPOSER_JSON];
    }

    /**
     * @throws RuntimeException when the file could not be written
     */
    private static function put(string $full, string $body, string $name): void
    {
        if (!SiteFile::put($full, $body)) {
            throw new RuntimeException($name.' could not be written');
        }
    }

    /**
     * @param Closure(): bool $edit
     *
     * @throws RuntimeException when the manipulator refused an edit, before any of its text is handed back
     */
    private static function must(Closure $edit, string $failure): void
    {
        try {
            $done = $edit();
        } catch (PcreException) {
            // The editor matches the file with a pattern, and a large file
            // runs that pattern out of backtracking.
            $done = false;
        }
        if (!$done) {
            throw new RuntimeException(PatchConfig::COMPOSER_JSON.': '.$failure);
        }
    }
}

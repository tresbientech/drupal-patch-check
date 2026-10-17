<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Write;

use Closure;
use Composer\Json\JsonFile;
use Composer\Json\JsonManipulator;
use Composer\Pcre\PcreException;
use RuntimeException;
use stdClass;
use TresBienTech\Drupatch\Manager;
use TresBienTech\Drupatch\Plan\Plan;
use TresBienTech\Drupatch\Read\Declaration;
use TresBienTech\Drupatch\Read\Scope;
use TresBienTech\Drupatch\Settings;
use TresBienTech\Drupatch\Source\UrlPatch;
use TresBienTech\Drupatch\Text;

/**
 * Every **declaration rewrite** a run makes, the two documents a **manager upgrade** writes, and the one question git is asked before the run writes anything.
 *
 * @phpstan-type Change array{action: string, package: string, title: string, path: string, provenance: array<string, string>}
 */
class Declarations
{
    /** A declaration the site did not hold, which an add run writes. */
    public const ADDED = 'added';

    /** A declaration that now names another file. */
    public const REPOINTED = 'repointed';

    /** A declaration the release made unneeded. */
    public const DROPPED = 'dropped';

    /** What a manager upgrade raises the patch manager requirement to. */
    public const CONSTRAINT = '^2';

    private function __construct(private readonly string $root)
    {
    }

    /**
     * Asks git about each document, once, before the run writes anything.
     *
     * A document passes when its patches match the last commit, whatever else
     * in it changed. The run's later rewrites are its own, so none asks again.
     *
     * @param list<string> $documents composer.json or the patches file, each once
     * @param ?WorkingTree $tree      null to ask nothing, for `--force` and a dry run
     *
     * @throws RuntimeException naming the document and `--force` when git cannot vouch for its patches
     */
    public static function checked(string $root, array $documents, ?WorkingTree $tree): self
    {
        foreach (null === $tree ? [] : $documents as $file) {
            self::refuseEdited($root, $file, $tree);
        }

        return new self($root);
    }

    /**
     * The documents holding a declaration this run acts on.
     *
     * @param list<Declaration> $declared
     *
     * @return list<string>
     */
    public static function documentsIn(array $declared, Scope $scope): array
    {
        $out = [];
        foreach ($declared as $declaration) {
            if ($scope->has($declaration->package, $declaration->source)) {
                $out[$declaration->document] = $declaration->document;
            }
        }

        return \array_values($out);
    }

    /**
     * The document a new declaration lands in: the patches file the site's manager reads, else composer.json. An add run never moves a layout.
     *
     * @param array<string, mixed> $extra the root package's extra
     */
    public static function documentFor(array $extra, Manager $manager): string
    {
        $file = $manager->patchesFile($extra);

        return '' === $file ? Declaration::COMPOSER_JSON : $file;
    }

    /**
     * Refuses when git cannot say each whole document is safe to replace, for a run that rewrites more than its patches.
     *
     * @param list<string> $documents
     *
     * @throws RuntimeException naming the first document git cannot vouch for
     */
    public static function refuseReplacing(string $root, array $documents, ?WorkingTree $tree): void
    {
        foreach (null === $tree ? [] : $documents as $file) {
            $held = $tree->refusal($root, $file);
            if ('' !== $held) {
                throw new RuntimeException(Text::t('@file: @why; commit it or pass --force', ['@file' => $file, '@why' => $held]));
            }
        }
    }

    /**
     * Decides what changes: one entry per declaration the plan settles.
     *
     * @param list<array{path: string, provenance: array<string, string>, status: string, package: string, title: string, verified: bool, unioned: list<array{file: string, line: int}>, regions: int}> $written
     *
     * @return list<array{action: 'dropped'|'repointed', package: string, title: string, path: string, provenance: array<string, string>}>
     */
    public static function changes(Plan $plan, array $written): array
    {
        $files = [];
        foreach ($written as $file) {
            if ('clean' === $file['status']) {
                $files[Declaration::keyOf($file['package'], $file['title'])] = $file;
            }
        }

        $changes = [];
        foreach ($plan->rows() as $row) {
            if ($row->isMerged()) {
                $left = UrlPatch::isUrl($row->source) ? '' : $row->source;
                $changes[] = ['action' => self::DROPPED, 'package' => $row->package, 'title' => $row->title, 'path' => $left, 'provenance' => []];
                continue;
            }
            $file = $files[$row->key()] ?? null;
            // A re-roll written over the file the entry already names
            // changes the file, never the entry.
            if ($row->conflicts() && null !== $file && $file['path'] !== $row->source) {
                $changes[] = ['action' => self::REPOINTED, 'package' => $row->package, 'title' => $row->title, 'path' => $file['path'], 'provenance' => $file['provenance']];
            }
        }

        return $changes;
    }

    /**
     * Points the declarations at what the run wrote. Each change lands in the document that held its entry, in the shape it was written in, composer.json first.
     *
     * @param list<Change>      $changes
     * @param list<Declaration> $declared the declarations the run read, and the one an add run writes
     *
     * @throws RuntimeException when a document cannot be read or written, or a change names no entry, before that document is written
     *
     * @return list<string> the documents written, in the order they were written
     */
    public function write(array $changes, array $declared): array
    {
        // Every change comes from a declaration the run read, so one that
        // names none pairs a row with the wrong declaration.
        $groups = [];
        foreach ($changes as $change) {
            $declaration = Declaration::find($declared, Declaration::keyOf($change['package'], $change['title'])) ?? throw self::unmatched($change);
            $groups[$declaration->document][] = [$change, $declaration];
        }
        \uksort($groups, static fn (int|string $a, int|string $b): int => [Declaration::COMPOSER_JSON !== $a, (string) $a] <=> [Declaration::COMPOSER_JSON !== $b, (string) $b]);

        $written = [];
        foreach ($groups as $document => $group) {
            $document = (string) $document;
            [$text, $patches] = self::document($this->root, $document);
            $patches = self::rewrite($patches, $group, $document);
            // Composer's editor writes a list on one line, so it writes a
            // marker and the marker is replaced by the pretty-printed map.
            $marker = '__drupapatch_'.\bin2hex(\random_bytes(8)).'__';
            $inComposerJson = Declaration::COMPOSER_JSON === $document;
            $json = new JsonManipulator($text);
            self::must($document, static fn (): bool => $inComposerJson
                ? $json->addSubNode('extra', 'patches', $marker)
                : $json->addMainKey('patches', $marker), 'its patch config could not be rewritten');
            // extra.patches sits two levels deep, a patches file's key one.
            $depth = $inComposerJson ? 2 : 1;
            $declarations = self::pretty($patches, $text, $depth);
            $contents = \str_replace('"'.$marker.'"', $declarations, $json->getContents());
            self::put($this->root, $document, $contents);
            $written[] = $document;
        }

        return $written;
    }

    /**
     * The site's composer.json as a manager upgrade leaves it: no declarations, the 2.x settings block, and the requirement raised. Built before the run writes anything.
     *
     * Every other key, the key order and the file's indentation stay as they
     * were, so the diff a person reviews holds the move alone.
     *
     * @param array<string, mixed> $extra the root package's extra, as it stands
     * @param string               $file  where the declarations go
     *
     * @throws RuntimeException when composer.json cannot be read or composer refuses an edit
     */
    public static function movedComposerJson(string $root, array $extra, string $file): string
    {
        $text = @\file_get_contents($root.\DIRECTORY_SEPARATOR.Declaration::COMPOSER_JSON);
        if (false === $text) {
            throw new RuntimeException('composer.json is not readable');
        }
        $json = new JsonManipulator($text);
        self::must(Declaration::COMPOSER_JSON, static fn (): bool => $json->addLink('require', Manager::PACKAGE, self::CONSTRAINT), 'the requirement could not be raised');
        self::must(Declaration::COMPOSER_JSON, static fn (): bool => $json->removeSubNode('extra', 'patches'), 'extra.patches could not be removed');
        foreach (\array_keys(Settings::dropped($extra)) as $key) {
            self::must(Declaration::COMPOSER_JSON, static fn (): bool => $json->removeSubNode('extra', $key), 'extra.'.$key.' could not be removed');
        }
        // What 2.x keeps of its own: the settings that carried over, and
        // the file the declarations moved to.
        $block = [];
        foreach (Settings::renamed($extra) as $from => $to) {
            self::must(Declaration::COMPOSER_JSON, static fn (): bool => $json->removeSubNode('extra', $from), 'extra.'.$from.' could not be removed');
            $block[$to] = $extra[$from];
        }
        $block['patches-file'] = $file;
        self::must(Declaration::COMPOSER_JSON, static fn (): bool => $json->addSubNode('extra', Settings::KEY, $block), 'extra.'.Settings::KEY.' could not be written');

        return $json->getContents();
    }

    /**
     * Writes both documents of a manager upgrade: the patches file first, so a composer.json pointing at a file that is not there never exists.
     *
     * Every declaration moves into the patches file in the expanded shape, with
     * a depth on the patches that need one. The order inside each package is
     * the order the manager applies them in.
     *
     * @param list<Declaration>  $declarations every patch the site declares
     * @param array<string, int> $depths       the measured depth to record, by declaration key
     * @param string             $composerJson the text movedComposerJson built
     *
     * @throws RuntimeException when either document could not be written
     *
     * @return list<string> the files written, in that order
     */
    public static function writeMove(string $root, string $file, array $declarations, array $depths, string $composerJson): array
    {
        $patches = [];
        foreach ($declarations as $declaration) {
            $depth = $depths[$declaration->key()] ?? null;
            $patches[$declaration->package][] = $declaration->definition(null === $depth ? [] : ['depth' => $depth]);
        }
        self::put($root, $file, JsonFile::encode(['patches' => $patches])."\n");
        self::put($root, Declaration::COMPOSER_JSON, $composerJson);

        return [$file, Declaration::COMPOSER_JSON];
    }

    /**
     * One document's declarations after its changes.
     *
     * A package no change touches stays as the site wrote it. A touched one
     * keeps the shape of its first entry, which is where 2.x reads a package's
     * shape, and moves to the expanded shape when a change brings provenance. An
     * added entry goes last in its package, because the manager applies a
     * package's patches in declaration order.
     *
     * @param array<mixed>                           $patches the declarations map the document holds
     * @param list<array{0: Change, 1: Declaration}> $group   each change with the declaration it settles
     *
     * @throws RuntimeException when a change names no entry the document holds
     *
     * @return array<mixed>
     */
    private static function rewrite(array $patches, array $group, string $document): array
    {
        $pending = [];
        $added = [];
        foreach ($group as [$change, $declaration]) {
            if (self::ADDED === $change['action']) {
                $added[$declaration->package][] = [$change, $declaration];
            } else {
                $pending[$declaration->key()] = $change;
            }
        }

        $out = [];
        foreach (self::opened($patches, \array_keys($added)) as $package => $entries) {
            if (!\is_string($package) || !\is_array($entries)) {
                $out[$package] = $entries;
                continue;
            }
            $first = null;
            $recorded = false;
            $touched = isset($added[$package]);
            // Each entry that stays: its key, what the site wrote, the
            // declaration as it now stands, and whether a change settled it.
            $kept = [];
            foreach ($entries as $key => $entry) {
                $read = Declaration::of($package, $key, $entry, $document);
                $first ??= $read;
                $change = null === $read ? null : ($pending[$read->key()] ?? null);
                if (null === $change) {
                    $kept[] = [$key, $entry, $read, false];
                    continue;
                }
                unset($pending[$read->key()]);
                $touched = true;
                $recorded = $recorded || [] !== $change['provenance'];
                if (self::DROPPED !== $change['action']) {
                    $kept[] = [$key, $entry, $read->at($change['path'], $change['provenance']), true];
                }
            }
            foreach ($added[$package] ?? [] as [$change, $declaration]) {
                $first ??= $declaration;
                $recorded = $recorded || [] !== $change['provenance'];
                $kept[] = [$declaration->title, null, $declaration->at($change['path'], $change['provenance']), true];
            }
            if (!$touched) {
                $out[$package] = $entries;
                continue;
            }

            $expanded = $recorded || $first->expanded;
            $rewritten = [];
            foreach ($kept as [$key, $entry, $now, $changed]) {
                $value = match (true) {
                    null === $now => $entry,
                    !$expanded => $changed ? $now->source : $entry,
                    $changed || !\is_array($entry) => $now->definition(\is_array($entry) ? $entry : []),
                    default => $entry,
                };
                if ($expanded) {
                    // A dropped entry leaves a hole in the integer keys, which
                    // JSON would then write as an object.
                    $rewritten[] = $value;
                } else {
                    $rewritten[$key] = $value;
                }
            }
            if ([] !== $rewritten) {
                $out[$package] = $rewritten;
            }
        }
        $unmatched = \reset($pending);
        if (false !== $unmatched) {
            throw self::unmatched($unmatched);
        }

        return $out;
    }

    /**
     * The declarations map plus an empty entry for each package with no patches yet, inserted in alphabetical order.
     *
     * The new package goes just before the first package name that sorts
     * after it. An unsorted map keeps its order around the new entry.
     *
     * @param array<mixed>     $patches
     * @param list<int|string> $packages
     *
     * @return array<mixed>
     */
    private static function opened(array $patches, array $packages): array
    {
        foreach ($packages as $package) {
            if (\array_key_exists($package, $patches)) {
                continue;
            }
            $out = [];
            foreach ($patches as $key => $entries) {
                if (!isset($out[$package]) && \is_string($key) && \strcmp((string) $package, $key) < 0) {
                    $out[$package] = [];
                }
                $out[$key] = $entries;
            }
            $out[$package] ??= [];
            $patches = $out;
        }

        return $patches;
    }

    /**
     * The declarations map as JSON, in the document's own indentation and line endings, for a value that sits at this depth.
     *
     * @param array<mixed> $patches
     * @param string       $text    the document as it stood, which sets the indentation
     */
    private static function pretty(array $patches, string $text, int $depth): string
    {
        $indent = JsonFile::detectIndenting($text);
        $newline = \str_contains($text, "\r\n") ? "\r\n" : "\n";
        $encoded = JsonFile::encode([] === $patches ? new stdClass() : $patches, indent: $indent);

        // JSON escapes a line break inside a string, so each \n ends a line.
        return \str_replace("\n", $newline.\str_repeat($indent, $depth), $encoded);
    }

    /**
     * @param Change $change
     */
    private static function unmatched(array $change): RuntimeException
    {
        return new RuntimeException(Text::t('no declaration of @package is titled @title, so nothing was rewritten', ['@package' => $change['package'], '@title' => $change['title']]));
    }

    /**
     * @param Closure(): bool $edit
     *
     * @throws RuntimeException naming the document when the manipulator refused an edit, before any of its text is handed back
     */
    private static function must(string $document, Closure $edit, string $failure): void
    {
        try {
            $done = $edit();
        } catch (PcreException) {
            // The editor matches the file with a pattern, and a large file
            // runs that pattern out of backtracking.
            $done = false;
        }
        if (!$done) {
            throw new RuntimeException($document.': '.$failure);
        }
    }

    /**
     * @throws RuntimeException when the file could not be written
     */
    private static function put(string $root, string $file, string $text): void
    {
        if (!SiteFile::put($root.\DIRECTORY_SEPARATOR.$file, $text)) {
            throw new RuntimeException(Text::t('@file could not be written', ['@file' => $file]));
        }
    }

    /**
     * @throws RuntimeException when git cannot vouch for the document's patches
     */
    private static function refuseEdited(string $root, string $file, WorkingTree $tree): void
    {
        [, $patches] = self::document($root, $file);
        $held = $tree->refusal($root, $file);
        if ('' === $held) {
            return;
        }
        if (WorkingTree::NOT_A_CHECKOUT === $held) {
            throw new RuntimeException(Text::t('@file: @why; pass --force', ['@file' => $file, '@why' => $held]));
        }
        // A run reaches a new core with the rest of the file already
        // edited: the constraints it bumped. Comparing the whole file would
        // refuse nearly every real run.
        $committed = $tree->committed($root, $file);
        if (null === $committed || $patches !== self::patchesOf($committed, $file)) {
            throw new RuntimeException(Text::t('@file has uncommitted changes to its patches; commit them or pass --force', ['@file' => $file]));
        }
    }

    /**
     * One document that holds declarations: its text, and the patches it declares.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function document(string $root, string $file): array
    {
        $text = @\file_get_contents($root.\DIRECTORY_SEPARATOR.$file);
        if (false === $text) {
            throw new RuntimeException(Text::t('@file is not readable', ['@file' => $file]));
        }
        $patches = self::patchesOf($text, $file);
        if (null === $patches) {
            throw new RuntimeException(Text::t('@file is not readable JSON', ['@file' => $file]));
        }

        return [$text, $patches];
    }

    /**
     * The patch declarations one document holds, null when it is not readable JSON.
     *
     * @param string $file composer.json, which holds them under `extra`, or a patches file, whose whole subject they are
     *
     * @return array<string, mixed>|null
     */
    private static function patchesOf(string $text, string $file): ?array
    {
        $decoded = \json_decode($text, true);
        if (!\is_array($decoded)) {
            return null;
        }

        return (array) (Declaration::COMPOSER_JSON === $file ? ($decoded['extra']['patches'] ?? []) : ($decoded['patches'] ?? []));
    }
}

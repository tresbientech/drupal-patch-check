<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Write;

use RuntimeException;
use TresBienTech\Drupatch\Read\PatchConfig;
use TresBienTech\Drupatch\Read\Scope;
use TresBienTech\Drupatch\Text;

/**
 * Every **declaration rewrite** a run makes, and the one question git is asked before the run writes anything.
 */
class Declarations
{
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
     * @param list<array{package: string, title: string, source: string, file: string, shape: string, provenance: array<string, string>}> $declared
     *
     * @return list<string>
     */
    public static function documentsIn(array $declared, Scope $scope): array
    {
        $out = [];
        foreach ($declared as $declaration) {
            if ($scope->has($declaration['package'], $declaration['source'])) {
                $out[$declaration['file']] = $declaration['file'];
            }
        }

        return \array_values($out);
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
     * Points the declarations at what the run wrote. Each change lands in the document that held its entry, in the shape it was written in, composer.json first.
     *
     * @param list<array{action: string, package: string, title: string, path: string, provenance: array<string, string>}> $changes
     * @param list<array{package: string, title: string, source: string, file: string, shape: string}>                     $declared which document holds each entry
     *
     * @throws RuntimeException when a document cannot be read or written
     *
     * @return list<string> the documents written, in the order they were written
     */
    public function write(array $changes, array $declared): array
    {
        $written = [];
        foreach (ConfigRewriter::byFile($changes, $declared) as $file => $group) {
            [$text, $patches] = self::document($this->root, $file);
            $patches = ConfigRewriter::apply($patches, $group);
            $updated = PatchConfig::COMPOSER_JSON === $file
                ? ConfigRewriter::intoComposerJson($text, $patches)
                : ConfigRewriter::intoPatchesFile($text, $patches);
            if (!SiteFile::put($this->root.\DIRECTORY_SEPARATOR.$file, $updated)) {
                throw new RuntimeException(Text::t('@file could not be written', ['@file' => $file]));
            }
            $written[] = $file;
        }

        return $written;
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

        return (array) (PatchConfig::COMPOSER_JSON === $file ? ($decoded['extra']['patches'] ?? []) : ($decoded['patches'] ?? []));
    }
}

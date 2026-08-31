<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\PatchConfig;

/**
 * What a site's patch declarations resolve to: the patches it applies,
 * the text of the local ones, and anything the reader could not read.
 */
final class Resolution
{
    /**
     * @param list<array{package: string, title: string, source: string}> $patches
     * @param array<string, string>                                       $files
     * @param list<string>                                                $notes
     * @param list<array{package: string, title: string, reason: string}> $skipped
     * @param list<string>                                                $unsent
     */
    public function __construct(
        public readonly array $patches,
        public readonly array $files,
        public readonly array $notes,
        /** Path of the external patches file, empty when the declarations are inline. */
        public readonly string $file,
        /**
         * One entry per declared patch the run did not judge: the package
         * it is on, its title, and why. The report groups these, so the
         * parts travel apart rather than as a formatted line.
         */
        public readonly array $skipped,
        /** One line per patch whose text did not fit, naming it and why. */
        public readonly array $unsent,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->patches;
    }
}

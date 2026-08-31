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
     */
    public function __construct(
        public readonly array $patches,
        public readonly array $files,
        public readonly array $notes,
        /** Path of the external patches file, empty when the declarations are inline. */
        public readonly string $file,
        /** Declared patches on packages outside drupal/, which are not sent. */
        public readonly int $outside,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->patches;
    }
}

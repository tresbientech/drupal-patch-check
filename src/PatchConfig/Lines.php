<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\PatchConfig;

/**
 * Where each patch source sits in the document that declares it.
 *
 * A patch is declared as a value in composer.json or in an external
 * patches file, and a reviewer acts on it there: a shipped entry is
 * deleted, a re-rolled one repointed. Anchoring to the declaration works
 * whether the source is a URL or a local path, and the declaring document
 * is always tracked in the site's repository.
 *
 * The document is scanned as text rather than parsed. A decoded document
 * has no line numbers left, and a source string is distinctive enough
 * that the first line carrying it is the line that declares it.
 */
final class Lines
{
    /**
     * @param list<string> $lines the document split on its line endings
     */
    private function __construct(private readonly array $lines)
    {
    }

    public static function in(string $document): self
    {
        $lines = \preg_split('/\r\n|\r|\n/', $document);

        return new self(false === $lines ? [] : $lines);
    }

    /**
     * The 1-based line declaring this source, or the first line when the
     * document does not carry it.
     *
     * A source declared twice anchors to its first occurrence: the two
     * entries are the same patch, and the earlier one is where a reader
     * looks first.
     */
    public function of(string $source): int
    {
        if ('' === $source) {
            return 1;
        }
        foreach ($this->lines as $index => $line) {
            if (\str_contains($line, $source)) {
                return $index + 1;
            }
        }

        return 1;
    }
}

<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Read;

use TresBienTech\Drupatch\Source\Provenance;

/**
 * One **declaration**: a patch the site declares, the document holding it, and the shape it is written in.
 */
class Declaration
{
    /** The document that holds declarations under `extra.patches`. */
    public const COMPOSER_JSON = 'composer.json';

    /** Where an expanded entry keeps the patch's title. */
    private const TITLE = 'description';

    /** Where an expanded entry keeps the patch's source. */
    private const SOURCE = 'url';

    public function __construct(
        public readonly string $package,
        public readonly string $title,
        public readonly string $source,
        /** composer.json, or the patches file the site's manager names. */
        public readonly string $document,
        /** Written as an object carrying `description` and `url`, which 2.x alone reads, rather than as a source under its title. */
        public readonly bool $expanded,
        /**
         * Where a copied patch's bytes came from. The compact shape has nowhere to keep one.
         *
         * @var array<string, string>
         */
        public readonly array $provenance,
    ) {
    }

    /**
     * One entry of a document, in either shape; null when it names no title or no source.
     *
     * @param int|string $key   the entry's key, which is its title in the compact shape
     * @param mixed      $entry what the site wrote, by hand
     */
    public static function of(string $package, int|string $key, mixed $entry, string $document): ?self
    {
        [$title, $source] = \is_array($entry) ? [$entry[self::TITLE] ?? null, $entry[self::SOURCE] ?? null] : [$key, $entry];
        if (!\is_string($title) || !\is_string($source)) {
            return null;
        }

        return \is_array($entry)
            ? new self($package, $title, \trim($source), $document, true, Provenance::read($entry['extra'] ?? null))
            : new self($package, $title, \trim($source), $document, false, []);
    }

    /**
     * The key a patch is matched by, wherever it is named: its package and its title.
     */
    public static function keyOf(string $package, string $title): string
    {
        return $package."\0".$title;
    }

    public function key(): string
    {
        return self::keyOf($this->package, $this->title);
    }

    /**
     * The first of these declarations under the key, null when none is.
     *
     * @param list<self> $declarations
     */
    public static function find(array $declarations, string $key): ?self
    {
        foreach ($declarations as $declaration) {
            if ($declaration->key() === $key) {
                return $declaration;
            }
        }

        return null;
    }

    /**
     * This declaration naming another source and carrying other provenance.
     *
     * @param array<string, string> $provenance
     */
    public function at(string $source, array $provenance): self
    {
        return new self($this->package, $this->title, $source, $this->document, $this->expanded, $provenance);
    }

    /**
     * This declaration in the expanded shape, with its source and its provenance.
     *
     * An entry the site wrote keeps its keys in its own order. A new entry
     * leads with the title and the source, then the keys a writer adds.
     *
     * @param array<mixed> $written the entry as the site wrote it, or the keys a new one adds
     *
     * @return array<mixed>
     */
    public function definition(array $written): array
    {
        $out = isset($written[self::TITLE]) ? $written : [self::TITLE => $this->title, self::SOURCE => $this->source] + $written;
        $out[self::SOURCE] = $this->source;
        if ([] !== $this->provenance) {
            $out['extra'][Provenance::KEY] = $this->provenance;
        }

        return $out;
    }
}

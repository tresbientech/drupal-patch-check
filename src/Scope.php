<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch;

/**
 * The packages and the patches a run acts on, from --package and --patch. Empty lists mean the whole site.
 */
class Scope
{
    /**
     * @param list<string> $packages composer names or drupal.org project names, either spelling
     * @param list<string> $sources  declared patch sources, a path or a URL, matched as written
     */
    public function __construct(
        public readonly array $packages,
        public readonly array $sources,
    ) {
    }

    public static function whole(): self
    {
        return new self([], []);
    }

    public function isWhole(): bool
    {
        return [] === $this->packages && [] === $this->sources;
    }

    /**
     * Whether the package is named, or none is.
     */
    public function hasPackage(string $package): bool
    {
        if ([] === $this->packages) {
            return true;
        }
        $key = self::key($package);
        foreach ($this->packages as $name) {
            if (self::key($name) === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a declared patch is in scope: its package is named or none is, and its source is named or none is.
     */
    public function has(string $package, string $source): bool
    {
        return $this->hasPackage($package) && ([] === $this->sources || \in_array($source, $this->sources, true));
    }

    /**
     * The --patch sources the site does not declare.
     *
     * @param list<string> $declared
     *
     * @return list<string>
     */
    public function unknownSources(array $declared): array
    {
        return \array_values(\array_diff($this->sources, $declared));
    }

    /**
     * A package named the way --package accepts it: either spelling of drupal/webform, in any case, reduces to the same key.
     */
    public static function key(string $package): string
    {
        $name = \strtolower(\trim($package));
        $slash = \strrpos($name, '/');

        return false === $slash ? $name : \substr($name, $slash + 1);
    }
}

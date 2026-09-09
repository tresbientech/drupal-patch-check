<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch;

/**
 * A patch declared as a URL that names neither a merge request nor a commit: a file on drupal.org, or a diff on any other host.
 */
class UrlPatch
{
    private function __construct(
        /** The project directory the copy goes under. */
        public readonly string $project,
        /** The URL the site declared, which is where the bytes are read from. */
        public readonly string $url,
        /** What the URL ends in, which is what the copy is called. */
        private readonly string $name,
    ) {
    }

    /**
     * The patch a declared URL names, or null when the URL ends in no file name or the package names no drupal.org project.
     */
    public static function of(string $source, string $package): ?self
    {
        $source = \trim($source);
        if (!PatchConfig::isUrl($source)) {
            return null;
        }
        $path = \parse_url($source, \PHP_URL_PATH);
        // basename() answers with the last segment of a path ending in a
        // separator, which names a directory rather than a file.
        $name = \is_string($path) && !\str_ends_with($path, '/') ? \basename($path) : '';
        $project = \str_replace('drupal/', '', $package);
        // A separator left in the project would place the copy outside the
        // directory the site named.
        if ('' === $name || '' === $project || \str_contains($project, '/') || \str_contains($project, '\\')) {
            return null;
        }

        return new self($project, $source, $name);
    }

    /**
     * Where the diff is read from: the URL the site declared, query string and all.
     */
    public function diff(): string
    {
        return $this->url;
    }

    /**
     * Where the site keeps its copy.
     */
    public function file(string $directory): string
    {
        return $directory.'/'.$this->project.'/'.$this->name;
    }
}

<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Source;

/**
 * A patch declared as a URL that names neither a merge request nor a commit: a file on drupal.org, or a diff on any other host.
 */
class UrlPatch
{
    /** drupal.org's own hosts. git.drupalcode.org is `MergeRequest::HOST`. */
    private const DRUPAL_ORG_HOSTS = ['drupal.org', 'www.drupal.org'];

    private function __construct(
        /** The folder the copy goes under, named after the package. */
        public readonly string $folder,
        /** The URL the site declared, which is where the bytes are read from. */
        public readonly string $url,
        /** What the URL ends in, which is what the copy is called. */
        private readonly string $name,
    ) {
    }

    /**
     * Whether a declared source is a URL, which is what decides where its bytes come from.
     */
    public static function isUrl(string $source): bool
    {
        $lowered = \strtolower(\trim($source));

        return \str_starts_with($lowered, 'http://') || \str_starts_with($lowered, 'https://');
    }

    /**
     * Whether a URL is on drupal.org or git.drupalcode.org, whose patch URLs are public pages. A URL that does not parse is on neither.
     */
    public static function isDrupalOrg(string $url): bool
    {
        if (!self::isUrl($url)) {
            return false;
        }
        $host = \strtolower((string) \parse_url(\trim($url), \PHP_URL_HOST));

        return \in_array($host, [...self::DRUPAL_ORG_HOSTS, \parse_url(MergeRequest::HOST, \PHP_URL_HOST)], true);
    }

    /**
     * The patch a declared URL names, or null when the URL ends in no file name or the package names no drupal.org project.
     */
    public static function of(string $source, string $package): ?self
    {
        $source = \trim($source);
        if (!self::isUrl($source)) {
            return null;
        }
        $path = \parse_url($source, \PHP_URL_PATH);
        // basename() answers with the last segment of a path ending in a
        // separator, which names a directory rather than a file.
        $name = \is_string($path) && !\str_ends_with($path, '/') ? \basename($path) : '';
        $folder = IssueReference::folderOf($package);
        // A separator left in the folder would place the copy outside the
        // directory the site named.
        if ('' === $name || '' === $folder || \str_contains($folder, '/') || \str_contains($folder, '\\')) {
            return null;
        }

        return new self($folder, $source, $name);
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
        return $directory.'/'.$this->folder.'/'.$this->name;
    }
}

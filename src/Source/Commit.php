<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Source;

/**
 * A commit a site declared a patch from. Its URL already pins the bytes; the site copies them so the install stops fetching them.
 */
class Commit
{
    /** A commit on a project or on an issue fork, in either form drupalcode serves. */
    private const PATTERN = '#^https://git\.drupalcode\.org/(project|issue)/([a-z0-9_]+(?:-\d+)?)/-/commit/([0-9a-f]{40})\.(patch|diff)$#';

    /** How much of the commit the file name holds. The header holds all of it. */
    private const SHORT = 12;

    private function __construct(
        public readonly string $project,
        public readonly string $sha,
        /** The URL the site declared, which is where the bytes are read from. */
        public readonly string $url,
    ) {
    }

    /**
     * The commit a declared source names, or null when it names none.
     */
    public static function of(string $source): ?self
    {
        if (1 !== \preg_match(self::PATTERN, \trim($source), $found)) {
            return null;
        }
        // An issue fork is named `<project>-<issue>`, and the patch belongs
        // to the project the fork was made from.
        $project = 'issue' === $found[1] ? (string) \preg_replace('/-\d+$/', '', $found[2]) : $found[2];

        return new self($project, $found[3], \trim($source));
    }

    /**
     * Where the diff is read from. A commit declared in the mail form is read as a plain diff, which is the form the copy is named for.
     */
    public function diff(): string
    {
        return \preg_replace('/\.patch$/', '.diff', $this->url) ?? $this->url;
    }

    /**
     * Where the site keeps its copy, named by the commit it holds.
     */
    public function file(string $directory): string
    {
        return $directory.'/'.$this->project.'/commit-'.\substr($this->sha, 0, self::SHORT).'.diff';
    }
}

<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch;

/**
 * A merge request a site declared a patch from: where its state is read, where its diff is taken between two commits, and where the copy of it belongs in the site.
 */
class MergeRequest
{
    /** The host every merge request the plugin knows lives on. */
    public const HOST = 'https://git.drupalcode.org';

    private function __construct(
        public readonly string $project,
        public readonly string $iid,
        /** The request itself, without the extension a declaration names it by. */
        public readonly string $url,
    ) {
    }

    /**
     * The merge request a declared source names, or null when it names none.
     */
    public static function of(string $source): ?self
    {
        $pattern = '#^'.\preg_quote(self::HOST, '#').'/project/([^/]+)/-/merge_requests/(\d+)\.(patch|diff)$#';
        if (1 !== \preg_match($pattern, \trim($source), $found)) {
            return null;
        }

        return new self($found[1], $found[2], self::HOST.'/project/'.$found[1].'/-/merge_requests/'.$found[2]);
    }

    /**
     * The declarations naming a merge request, in the order the site wrote them. Reads the sources alone, so a caller with no plan and no service answer can ask.
     *
     * @param list<array<string, string>> $declarations each carrying the source the site declared
     *
     * @return list<array<string, string>>
     */
    public static function among(array $declarations): array
    {
        $out = [];
        foreach ($declarations as $declaration) {
            if (null !== self::of($declaration['source'])) {
                $out[] = $declaration;
            }
        }

        return $out;
    }

    /**
     * Where the request's commits are read. The endpoint answers without credentials for a public project.
     */
    public function api(): string
    {
        return self::HOST.'/api/v4/projects/'.\rawurlencode('project/'.$this->project).'/merge_requests/'.$this->iid;
    }

    /**
     * The diff between two commits, in the form the merge request's own `.diff` serves.
     */
    public function compare(string $base, string $head): string
    {
        return self::HOST.'/project/'.$this->project.'/-/compare/'.$base.'...'.$head.'?format=diff';
    }

    /**
     * Where the site keeps its copy, under the directory it names for patches.
     */
    public function file(string $directory): string
    {
        return $directory.'/'.$this->project.'/mr'.$this->iid.'.diff';
    }
}

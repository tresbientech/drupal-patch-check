<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Source;

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
     * One merge request of a project by its number, null when the number is no number.
     *
     * Both commands reach a request this way once they know the issue it
     * belongs to, so the path is built here rather than at each call.
     */
    public static function byNumber(string $project, string $iid): ?self
    {
        return self::of(self::HOST.'/project/'.$project.'/-/merge_requests/'.$iid.'.diff');
    }

    /**
     * The declarations naming a merge request, in the order the site wrote them. Reads the sources alone, so a caller with no plan and no service answer can ask.
     *
     * @param list<array{source: string}> $declarations each carrying the source the site declared
     *
     * @return list<array{source: string}>
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
     * Where one project's own record is read, which is how a merge request's source project resolves to a path.
     */
    public static function projectApi(int $id): string
    {
        return self::HOST.'/api/v4/projects/'.$id;
    }

    /**
     * The issue number a fork path names, empty when the path names no issue fork.
     *
     * drupal.org opens one GitLab project per issue, `issue/<name>-<number>`,
     * and every merge request on that issue is pushed from it.
     */
    public static function issueIn(string $path): string
    {
        return 1 === \preg_match('#^issue/[a-z0-9_]+-(\d+)$#', \trim($path), $found) ? $found[1] : '';
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

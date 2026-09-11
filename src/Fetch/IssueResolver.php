<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Fetch;

use TresBienTech\Drupatch\Source\IssueReference;
use TresBienTech\Drupatch\Source\MergeRequest;
use TresBienTech\Drupatch\Text;

/**
 * The issue a merge request belongs to, read over the site's own network.
 *
 * drupal.org opens one GitLab project per issue and pushes every merge request
 * on that issue from it, so a request's `source_project_id` names its issue.
 * The branch name and the title carry the number by convention alone, and a
 * request that merely mentions another issue carries it too.
 *
 * @phpstan-import-type Candidate from \TresBienTech\Drupatch\Source\Ranking
 */
class IssueResolver
{
    /** Most merge requests one search answers with. An issue with more than this has not been seen. */
    private const CAP = 50;

    /** @var array<int, string> the path of each project this run asked about */
    private array $paths = [];

    public function __construct(private readonly PatchText $text)
    {
    }

    /**
     * Every merge request on one issue, confirmed against the fork it was pushed from.
     *
     * A search on the number narrows to a handful server-side, on the title
     * and the body alike, so a request that merely mentions the number comes
     * back too. The fork path is the only thing that says which issue a
     * request belongs to, so each candidate is confirmed by it.
     *
     * @return list<Candidate>|string the candidates in the order the host listed them, or why there are none
     */
    public function on(IssueReference $issue): array|string
    {
        $found = $this->text->askJson($issue->search(self::CAP));
        if (\is_string($found)) {
            return $found;
        }
        $out = [];
        foreach ($found as $request) {
            if (!\is_array($request) || !$this->belongsTo($request, $issue->number)) {
                continue;
            }
            $out[] = [
                'iid' => (string) ($request['iid'] ?? ''),
                'title' => \is_string($request['title'] ?? null) ? $request['title'] : '',
                'target' => \is_string($request['target_branch'] ?? null) ? $request['target_branch'] : '',
                'draft' => true === ($request['draft'] ?? null),
                'state' => \is_string($request['state'] ?? null) ? $request['state'] : '',
                'updated' => \is_string($request['updated_at'] ?? null) ? $request['updated_at'] : '',
            ];
        }

        return $out;
    }

    /**
     * Whether one search hit was pushed from this issue's own fork.
     *
     * @param array<string, mixed> $request
     */
    private function belongsTo(array $request, string $number): bool
    {
        $project = $request['source_project_id'] ?? null;
        if (!\is_int($project)) {
            return false;
        }
        $path = $this->paths[$project] ?? null;
        if (null === $path) {
            $fork = $this->text->askJson(MergeRequest::projectApi($project));
            $path = \is_array($fork) && \is_string($fork['path_with_namespace'] ?? null) ? $fork['path_with_namespace'] : '';
            $this->paths[$project] = $path;
        }

        return MergeRequest::issueIn($path) === $number;
    }

    /**
     * The issue behind one merge request, and the request's own title, or why neither could be read.
     *
     * @param string|null $issue the issue the request has to belong to; null takes whichever it belongs to
     *
     * @return array{issue: string, title: string, project: int}|string
     */
    public function behind(MergeRequest $request, ?string $issue = null): array|string
    {
        $behind = $this->forkOf($request);
        if (\is_array($behind) && null !== $issue && $behind['issue'] !== $issue) {
            return Text::t('merge request @iid belongs to issue @other', ['@iid' => $request->iid, '@other' => $behind['issue']]);
        }

        return $behind;
    }

    /**
     * @return array{issue: string, title: string, project: int}|string
     */
    private function forkOf(MergeRequest $request): array|string
    {
        $answer = $this->text->askJson($request->api());
        if (\is_string($answer)) {
            return $answer;
        }
        $project = $answer['source_project_id'] ?? null;
        $title = $answer['title'] ?? '';
        if (!\is_int($project)) {
            return 'the merge request names no source project, so the issue behind it cannot be read';
        }
        $fork = $this->text->askJson(MergeRequest::projectApi($project));
        if (\is_string($fork)) {
            return $fork;
        }
        $path = \is_string($fork['path_with_namespace'] ?? null) ? $fork['path_with_namespace'] : '';
        $issue = MergeRequest::issueIn($path);
        if ('' === $issue) {
            return Text::t('the merge request is pushed from @path, which is no issue fork', ['@path' => $path]);
        }

        return ['issue' => $issue, 'title' => \is_string($title) ? $title : '', 'project' => $project];
    }
}

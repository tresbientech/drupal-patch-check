<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Command;

use TresBienTech\Drupatch\Fetch\IssueResolver;
use TresBienTech\Drupatch\Render\AddReport;
use TresBienTech\Drupatch\Source\IssueReference;
use TresBienTech\Drupatch\Source\MergeRequest;
use TresBienTech\Drupatch\Source\Ranking;
use TresBienTech\Drupatch\Text;

/**
 * How a run reaches one merge request from an issue: the one `--mr` names, or the one taken from the ranked list.
 *
 * @phpstan-import-type Candidate from Ranking
 */
trait PicksMergeRequest
{
    /**
     * The request `--mr` names on this issue, once the fork it was pushed from confirms the issue; or why it is refused.
     *
     * @return array{request: MergeRequest, title: string}|string
     */
    private function named(IssueReference $issue, string $wanted, IssueResolver $resolver): array|string
    {
        $request = MergeRequest::byNumber($issue->project, $wanted);
        if (null === $request) {
            return Text::t('--mr takes a merge request number; @wanted is not one', ['@wanted' => $wanted]);
        }
        $behind = $resolver->behind($request, $issue->number);

        return \is_string($behind) ? $behind : ['request' => $request, 'title' => $behind['title']];
    }

    /**
     * The issue's merge requests ranked against the installed release, and the one this run takes: the only one, or the one a person picks.
     *
     * @return array{request: MergeRequest, ordered: list<Candidate>, at: int}|string
     */
    private function picked(IssueReference $issue, IssueResolver $resolver, string $version): array|string
    {
        $candidates = $resolver->on($issue);
        if (\is_string($candidates)) {
            return $candidates;
        }
        if ([] === $candidates) {
            return Text::t('issue @issue has no merge request on @host', ['@issue' => $issue->number, '@host' => MergeRequest::HOST]);
        }
        $ordered = Ranking::order($candidates, $version);
        $at = Ranking::best($ordered);
        if (\count($ordered) > 1) {
            if (!$this->getIO()->isInteractive()) {
                return Text::t('issue @issue has @count merge requests; name one with --mr', ['@issue' => $issue->number, '@count' => \count($ordered)]);
            }
            foreach (AddReport::candidates($ordered, $issue->number) as $line) {
                $this->getIO()->write($line);
            }
            $picked = $this->getIO()->select(AddReport::QUESTION, \array_column($ordered, 'iid'), (string) $at);
            $at = \is_string($picked) ? (int) $picked : $at;
        }
        // The iid is the host's, and a request with none cannot be named.
        $request = MergeRequest::byNumber($issue->project, $ordered[$at]['iid']);

        return null === $request
            ? Text::t('the host listed a merge request numbered @iid', ['@iid' => $ordered[$at]['iid']])
            : ['request' => $request, 'ordered' => $ordered, 'at' => $at];
    }
}

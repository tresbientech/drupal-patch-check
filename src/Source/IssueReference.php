<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Source;

/**
 * A drupal.org issue named by URL, in either place a person copies one from.
 *
 * The number is the same on both sides of the issue migration, so a queue that
 * moved to GitLab reads the same as one that has not.
 */
class IssueReference
{
    /** The issue queue on drupal.org. */
    private const DRUPAL_ORG = '#^https://www\.drupal\.org/project/([a-z0-9_]+)/issues/(\d+)$#';

    /** The same issue as a GitLab work item. */
    private const WORK_ITEM = '#^https://git\.drupalcode\.org/project/([a-z0-9_]+)/-/work_items/(\d+)$#';

    private function __construct(
        /** The drupal.org machine name, which is also the GitLab project path. */
        public readonly string $project,
        public readonly string $number,
    ) {
    }

    /**
     * The issue a reference names, or null when it names none.
     */
    public static function of(string $reference): ?self
    {
        $reference = \rtrim(\trim($reference), '/');
        foreach ([self::DRUPAL_ORG, self::WORK_ITEM] as $pattern) {
            if (1 === \preg_match($pattern, $reference, $found)) {
                return new self($found[1], $found[2]);
            }
        }

        return null;
    }

    /** A drupal.org issue number as a declaration writes one, with no longer run of digits around it. */
    private const NUMBER = '(?<!\d)(\d{6,8})(?!\d)';

    /**
     * The issue a patch declaration names, for a patch on `$project`.
     *
     * Two places carry it. A site titles its declarations by hand and
     * drupal.org's convention leads with the number, so the title is read
     * from the front alone: further along, a number is a version, a date or
     * a comment as often as it is an issue. A patch file is named after what
     * it carries, so its own name is read wherever the number sits, and the
     * first one wins because `2466553-175.patch` names the issue and then
     * the comment. The directories above the file are left out, since a
     * project name holds digits of its own.
     */
    public static function inDeclaration(string $project, string $title, string $source): ?self
    {
        if (1 === \preg_match('/^\s*#?'.self::NUMBER.'/', $title, $found)) {
            return new self($project, $found[1]);
        }

        return 1 === \preg_match('/'.self::NUMBER.'/', \basename($source), $found)
            ? new self($project, $found[1])
            : null;
    }

    /**
     * Where the project's merge requests are searched for this number. The endpoint answers without credentials for a public project.
     */
    public function search(int $cap): string
    {
        return MergeRequest::HOST.'/api/v4/projects/'.\rawurlencode('project/'.$this->project)
            .'/merge_requests?state=all&per_page='.$cap.'&search='.$this->number;
    }
}

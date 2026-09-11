<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests;

use ArrayObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Fetch\IssueResolver;
use TresBienTech\Drupatch\Fetch\PatchText;
use TresBienTech\Drupatch\Source\IssueReference;
use TresBienTech\Drupatch\Source\MergeRequest;

/**
 * The issue behind a merge request, read from the fork it was pushed from.
 */
#[CoversClass(IssueResolver::class)]
class IssueResolverTest extends TestCase
{
    private const MR = 'https://git.drupalcode.org/project/webform/-/merge_requests/940.diff';

    private const API = 'https://git.drupalcode.org/api/v4/projects/project%2Fwebform/merge_requests/940';

    private const FORK = 'https://git.drupalcode.org/api/v4/projects/243137';

    /**
     * A resolver whose hosts answer what a case says, and 404 for anything else.
     *
     * @param array<string, array{int, string}> $answers
     * @param ArrayObject<int, string>|null     $asked
     */
    private static function resolver(array $answers, ?ArrayObject $asked = null): IssueResolver
    {
        $fetch = StubHost::fetch($answers, $asked);

        return new IssueResolver(new PatchText(\sys_get_temp_dir(), $fetch, ''));
    }

    /**
     * Where the run's URLs are recorded, so a case can say what it asked and in which order.
     *
     * @return ArrayObject<int, string>
     */
    private static function log(): ArrayObject
    {
        return new ArrayObject();
    }

    private static function request(): MergeRequest
    {
        $request = MergeRequest::of(self::MR);
        self::assertNotNull($request);

        return $request;
    }

    /**
     * @return array<string, array{int, string}>
     */
    private static function answers(string $path = 'issue/webform-3521733'): array
    {
        return [
            self::API => [200, (string) \json_encode(['source_project_id' => 243137, 'title' => 'Fix the back/forward cache'])],
            self::FORK => [200, (string) \json_encode(['path_with_namespace' => $path])],
        ];
    }

    public function testTheForkPathNamesTheIssue(): void
    {
        $behind = self::resolver(self::answers())->behind(self::request());

        self::assertSame(['issue' => '3521733', 'title' => 'Fix the back/forward cache', 'project' => 243137], $behind);
    }

    // The number is read from the fork the request was pushed from, so one
    // lookup leads to the other.
    public function testItAsksTheRequestThenItsSourceProject(): void
    {
        $asked = self::log();

        self::resolver(self::answers(), $asked)->behind(self::request());

        self::assertSame([self::API, self::FORK], $asked->getArrayCopy());
    }

    // --mr names a number on a project, and the fork says which issue that
    // number belongs to.
    public function testARequestOnAnotherIssueIsRefusedWhenOneIsNamed(): void
    {
        $resolver = self::resolver(self::answers());

        self::assertSame('merge request 940 belongs to issue 3521733', $resolver->behind(self::request(), '3218426'));
        self::assertSame('3521733', $resolver->behind(self::request(), '3521733')['issue'] ?? null);
    }

    // A merge request opened from a branch of the project itself belongs to
    // no issue, whatever its title says.
    public function testAProjectPathNamesNoIssue(): void
    {
        $behind = self::resolver(self::answers('project/webform'))->behind(self::request());

        self::assertSame('the merge request is pushed from project/webform, which is no issue fork', $behind);
    }

    public function testARequestWithNoSourceProjectIsRefused(): void
    {
        $answers = [self::API => [200, (string) \json_encode(['title' => 'Fix'])]];

        self::assertSame(
            'the merge request names no source project, so the issue behind it cannot be read',
            self::resolver($answers)->behind(self::request())
        );
    }

    public function testAHostThatRefusesIsReported(): void
    {
        self::assertSame('the host answered 404', self::resolver([])->behind(self::request()));
    }

    public function testAnAnswerThatIsNotJsonIsReported(): void
    {
        $answers = [self::API => [200, 'not json']];

        self::assertSame('the host answered with something that is not JSON', self::resolver($answers)->behind(self::request()));
    }
    private const SEARCH = 'https://git.drupalcode.org/api/v4/projects/project%2Fwebform/merge_requests?state=all&per_page=50&search=3521733';

    /** The fork of another issue, which a request mentioning ours is pushed from. */
    private const OTHER_FORK = 'https://git.drupalcode.org/api/v4/projects/999';

    private static function issue(): IssueReference
    {
        $issue = IssueReference::of('https://www.drupal.org/project/webform/issues/3521733');
        self::assertNotNull($issue);

        return $issue;
    }

    /**
     * One search hit, as GitLab lists it.
     *
     * @return array<string, mixed>
     */
    private static function hit(int $iid, int $source = 243137, string $target = '6.2.x', string $updated = '2026-09-09'): array
    {
        return [
            'iid' => $iid,
            'title' => 'fix: #3521733 patch '.$iid,
            'target_branch' => $target,
            'draft' => false,
            'state' => 'opened',
            'updated_at' => $updated.'T00:00:00Z',
            'source_project_id' => $source,
        ];
    }

    public function testItListsTheRequestsItsForkConfirms(): void
    {
        $answers = self::answers() + [
            self::SEARCH => [200, (string) \json_encode([self::hit(940), self::hit(912, target: '6.x', updated: '2026-09-01')])],
        ];

        $found = self::resolver($answers)->on(self::issue());

        self::assertSame([
            ['iid' => '940', 'title' => 'fix: #3521733 patch 940', 'target' => '6.2.x', 'draft' => false, 'state' => 'opened', 'updated' => '2026-09-09T00:00:00Z'],
            ['iid' => '912', 'title' => 'fix: #3521733 patch 912', 'target' => '6.x', 'draft' => false, 'state' => 'opened', 'updated' => '2026-09-01T00:00:00Z'],
        ], $found);
    }

    // The search reads the body too, so a request that names our number while
    // belonging to another issue comes back and has to be dropped.
    public function testARequestThatOnlyMentionsTheNumberIsDropped(): void
    {
        $answers = self::answers() + [
            self::SEARCH => [200, (string) \json_encode([self::hit(940), self::hit(7, source: 999)])],
            self::OTHER_FORK => [200, (string) \json_encode(['path_with_namespace' => 'issue/webform-4000000'])],
        ];

        self::assertSame(['940'], \array_column((array) self::resolver($answers)->on(self::issue()), 'iid'));
    }

    // A request opened from a branch of the project itself belongs to no
    // issue, whatever its title says.
    public function testARequestFromTheProjectItselfIsDropped(): void
    {
        $answers = self::answers('project/webform') + [
            self::SEARCH => [200, (string) \json_encode([self::hit(940)])],
        ];

        self::assertSame([], self::resolver($answers)->on(self::issue()));
    }

    // Candidates on one issue share a fork, so the run reads its path once.
    public function testTheForkIsReadOnceForEveryRequestOnIt(): void
    {
        $answers = self::answers() + [
            self::SEARCH => [200, (string) \json_encode([self::hit(940), self::hit(912), self::hit(901)])],
        ];
        $asked = self::log();

        self::resolver($answers, $asked)->on(self::issue());

        self::assertSame([self::SEARCH, self::FORK], $asked->getArrayCopy());
    }

    public function testAnIssueWithNoRequestListsNone(): void
    {
        $answers = self::answers() + [self::SEARCH => [200, '[]']];

        self::assertSame([], self::resolver($answers)->on(self::issue()));
    }

    public function testASearchTheHostRefusedIsReported(): void
    {
        self::assertSame('the host answered 404', self::resolver([])->on(self::issue()));
    }
}

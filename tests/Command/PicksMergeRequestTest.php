<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Command;

use Composer\IO\BufferIO;
use Composer\IO\IOInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Command\PicksMergeRequest;
use TresBienTech\Drupatch\Fetch\IssueResolver;
use TresBienTech\Drupatch\Fetch\PatchText;
use TresBienTech\Drupatch\Source\IssueReference;
use TresBienTech\Drupatch\Tests\StubHost;

/**
 * How add and reroll reach one merge request from an issue, against a stub host.
 */
#[CoversClass(PicksMergeRequest::class)]
class PicksMergeRequestTest extends TestCase
{
    private const SEARCH = 'https://git.drupalcode.org/api/v4/projects/project%2Fwebform/merge_requests?state=all&per_page=50&search=3521733';

    private const REQUEST = 'https://git.drupalcode.org/api/v4/projects/project%2Fwebform/merge_requests/940';

    private const FORK = 'https://git.drupalcode.org/api/v4/projects/243137';

    /**
     * A command using the trait, its IO a buffer a case can answer through.
     *
     * @param list<string>|null $answers what a person types, null for a run nobody can ask
     */
    private static function command(?array $answers = null): object
    {
        $io = new BufferIO();
        if (null !== $answers) {
            $io->setUserInputs($answers);
        }

        return new class($io) {
            use PicksMergeRequest;

            public function __construct(private readonly IOInterface $io)
            {
            }

            public function getIO(): IOInterface
            {
                return $this->io;
            }

            /**
             * @return array{request: \TresBienTech\Drupatch\Source\MergeRequest, title: string}|string
             */
            public function byNumber(IssueReference $issue, string $wanted, IssueResolver $resolver): array|string
            {
                return $this->named($issue, $wanted, $resolver);
            }

            /**
             * @return array{request: \TresBienTech\Drupatch\Source\MergeRequest, ordered: list<array{iid: string, title: string, target: string, draft: bool, state: string, updated: string}>, at: int}|string
             */
            public function fromList(IssueReference $issue, IssueResolver $resolver, string $version): array|string
            {
                return $this->picked($issue, $resolver, $version);
            }
        };
    }

    /**
     * @param list<array{0: int, 1: string}> $hits iid and target branch per search hit
     */
    private static function resolver(array $hits): IssueResolver
    {
        $listed = [];
        foreach ($hits as [$iid, $target]) {
            $listed[] = ['iid' => $iid, 'title' => 'fix: #3521733 patch '.$iid, 'target_branch' => $target, 'draft' => false, 'state' => 'opened', 'updated_at' => '2026-09-09T00:00:00Z', 'source_project_id' => 243137];
        }

        return new IssueResolver(new PatchText(\sys_get_temp_dir(), StubHost::fetch([
            self::SEARCH => [200, (string) \json_encode($listed)],
            self::REQUEST => [200, (string) \json_encode(['source_project_id' => 243137, 'title' => 'Fix the back/forward cache'])],
            self::FORK => [200, (string) \json_encode(['path_with_namespace' => 'issue/webform-3521733'])],
        ]), ''));
    }

    private static function issue(): IssueReference
    {
        $issue = IssueReference::of('https://www.drupal.org/project/webform/issues/3521733');
        self::assertNotNull($issue);

        return $issue;
    }

    public function testOneCandidateIsTakenWithoutAPrompt(): void
    {
        $found = self::command()->fromList(self::issue(), self::resolver([[940, '6.2.x']]), '6.2.9');

        self::assertIsArray($found);
        self::assertSame('940', $found['request']->iid);
    }

    public function testSeveralCandidatesWithNobodyToAskAreRefusedNamingMr(): void
    {
        $found = self::command()->fromList(self::issue(), self::resolver([[940, '6.2.x'], [912, '6.x']]), '6.2.9');

        self::assertSame('issue 3521733 has 2 merge requests; name one with --mr', $found);
    }

    // The list is ranked against the release, so the second entry is the dev branch's.
    public function testAPersonPicksFromTheRankedList(): void
    {
        $found = self::command(['1'])->fromList(self::issue(), self::resolver([[912, '6.x'], [940, '6.2.x']]), '6.2.9');

        self::assertIsArray($found);
        self::assertSame(['940', '912'], \array_column($found['ordered'], 'iid'));
        self::assertSame('912', $found['request']->iid);
    }

    public function testAnIssueWithNoRequestIsRefused(): void
    {
        $found = self::command()->fromList(self::issue(), self::resolver([]), '6.2.9');

        self::assertSame('issue 3521733 has no merge request on https://git.drupalcode.org', $found);
    }

    public function testMrTakesTheRequestItsForkConfirms(): void
    {
        $found = self::command()->byNumber(self::issue(), '940', self::resolver([]));

        self::assertIsArray($found);
        self::assertSame(['940', 'Fix the back/forward cache'], [$found['request']->iid, $found['title']]);
    }

    public function testMrThatIsNotANumberIsRefused(): void
    {
        self::assertSame('--mr takes a merge request number; abc is not one', self::command()->byNumber(self::issue(), 'abc', self::resolver([])));
    }
}

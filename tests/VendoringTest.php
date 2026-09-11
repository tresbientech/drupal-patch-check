<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Fetch\PatchText;
use TresBienTech\Drupatch\Fetch\Vendoring;
use TresBienTech\Drupatch\Manager;
use TresBienTech\Drupatch\Read\Scope;
use TresBienTech\Drupatch\Source\Provenance;
use TresBienTech\Drupatch\Tests\Write\FakeGit;
use TresBienTech\Drupatch\Write\WorkingTree;

class VendoringTest extends TestCase
{
    private const MR = 'https://git.drupalcode.org/project/webform/-/merge_requests/940.patch';

    private const DIFF = "diff --git a/a.php b/a.php\n--- a/a.php\n+++ b/a.php\n@@ -1 +1 @@\n-one\n+two\n";

    private const REFS = '{"sha":"bbb","diff_refs":{"base_sha":"aaa","head_sha":"bbb","start_sha":"aaa"}}';

    private string $root = '';

    private ?WorkingTree $tree = null;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir().'/drupatch-pin-'.\bin2hex(\random_bytes(6));
        \mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        Scratch::remove($this->root);
    }

    /**
     * @param array<string, array{int, string}> $answers status and body per URL
     * @param ArrayObject<int, string>|null     $asked   every URL the run reached
     */
    private function vendoring(array $answers, ?ArrayObject $asked = null, string $cache = '', string $manager = '2.0.0'): Vendoring
    {
        $fetch = StubHost::fetch($answers, $asked);

        return new Vendoring($this->root, new PatchText($this->root, $fetch, $cache), 'patch', Manager::ofVersion($manager), $this->tree);
    }

    /**
     * A vendored file as pin wrote it, and the record its declaration holds.
     *
     * @return array<string, string>
     */
    private function vendored(string $base, string $head): array
    {
        \mkdir($this->root.'/patch/webform', 0o777, true);
        \file_put_contents($this->root.'/patch/webform/mr940.diff', self::DIFF);

        return Provenance::of([
            'mr' => 'https://git.drupalcode.org/project/webform/-/merge_requests/940',
            'base' => $base,
            'head' => $head,
            'fetched' => '2026-09-01',
        ]);
    }

    /**
     * Where the run's URLs are recorded, so a case can say a host was never reached.
     *
     * @return ArrayObject<int, string>
     */
    private static function log(): ArrayObject
    {
        return new ArrayObject();
    }

    /**
     * @param array<string, string> $provenance what the declaration records about the copy
     *
     * @return list<array{package: string, title: string, source: string, provenance: array<string, string>}>
     */
    private static function declarations(string $source = self::MR, array $provenance = []): array
    {
        return [['package' => 'drupal/webform', 'title' => 'fix the alter hook', 'source' => $source, 'provenance' => $provenance]];
    }

    /**
     * @return array<string, array{int, string}>
     */
    private static function answers(): array
    {
        return [
            'https://git.drupalcode.org/api/v4/projects/project%2Fwebform/merge_requests/940' => [200, self::REFS],
            'https://git.drupalcode.org/project/webform/-/compare/aaa...bbb?format=diff' => [200, self::DIFF],
        ];
    }

    public function testItWritesTheDiffAndReportsItsProvenance(): void
    {
        $result = $this->vendoring(self::answers())->run(self::declarations(), new Scope([], []), false);

        self::assertSame([], $result->refused);
        self::assertSame('patch/webform/mr940.diff', $result->vendored[0]['path']);
        // The file holds the diff and nothing else; the record goes on the
        // declaration.
        self::assertSame(self::DIFF, (string) \file_get_contents($this->root.'/patch/webform/mr940.diff'));
        self::assertSame([
            'mr' => 'https://git.drupalcode.org/project/webform/-/merge_requests/940',
            'base' => 'aaa',
            'head' => 'bbb',
            'fetched' => \date('Y-m-d'),
        ], $result->vendored[0]['provenance']);
    }

    public function testADryRunAsksAndWritesNothing(): void
    {
        $result = $this->vendoring(self::answers())->run(self::declarations(), new Scope([], []), true);

        self::assertSame('patch/webform/mr940.diff', $result->vendored[0]['path']);
        self::assertFileDoesNotExist($this->root.'/patch/webform/mr940.diff');
    }

    // drupalcode throttles hard, and one refusal is not the run's answer.
    public function testAThrottledLookupRefusesThatPatchAlone(): void
    {
        $answers = self::answers();
        $answers['https://git.drupalcode.org/api/v4/projects/project%2Fwebform/merge_requests/940'] = [429, ''];
        $declarations = \array_merge(self::declarations(), [[
            'package' => 'drupal/token',
            'title' => 'fix the other thing',
            'source' => 'https://git.drupalcode.org/project/token/-/merge_requests/12.diff',
        ]]);
        $answers['https://git.drupalcode.org/api/v4/projects/project%2Ftoken/merge_requests/12'] = [200, self::REFS];
        $answers['https://git.drupalcode.org/project/token/-/compare/aaa...bbb?format=diff'] = [200, self::DIFF];

        $result = $this->vendoring($answers)->run($declarations, new Scope([], []), false);

        self::assertSame('the host answered 429', $result->refused[0]['reason']);
        self::assertSame('', $result->refused[0]['lifts']);
        self::assertSame('drupal/webform', $result->refused[0]['package']);
        self::assertSame('patch/token/mr12.diff', $result->vendored[0]['path']);
    }

    public function testAnAnswerThatIsNotADiffIsRefused(): void
    {
        $answers = self::answers();
        $answers['https://git.drupalcode.org/project/webform/-/compare/aaa...bbb?format=diff'] = [200, '<!DOCTYPE html>'];

        $result = $this->vendoring($answers)->run(self::declarations(), new Scope([], []), false);

        self::assertSame('what came back is not a diff', $result->refused[0]['reason']);
        self::assertSame([], $result->vendored);
    }

    public function testARequestWithNoCommitsYetIsRefused(): void
    {
        $answers = self::answers();
        $answers['https://git.drupalcode.org/api/v4/projects/project%2Fwebform/merge_requests/940'] = [200, '{"sha":null,"diff_refs":null}'];

        $result = $this->vendoring($answers)->run(self::declarations(), new Scope([], []), false);

        self::assertSame('the merge request names no commits yet', $result->refused[0]['reason']);
    }

    // The file is the site's now, and taking new bytes is its own move.
    // The declaration still has to name it, so an interrupted run finishes.
    public function testAPatchAlreadyVendoredKeepsItsBytes(): void
    {
        $held = $this->vendored('aaa', 'bbb');

        $result = $this->vendoring(self::answers(), $asked = self::log())->run(self::declarations(provenance: $held), new Scope([], []), false);

        self::assertSame([], $result->vendored);
        self::assertSame([], $result->refused);
        self::assertSame([], $result->moved);
        self::assertSame('patch/webform/mr940.diff', $result->kept[0]['path']);
        self::assertSame(['https://git.drupalcode.org/api/v4/projects/project%2Fwebform/merge_requests/940'], $asked->getArrayCopy());
    }

    // Somebody pushed to the request since the site copied it. The bytes stay
    // as they are until a person asks for the new ones.
    public function testAMovedRequestIsReportedAndNotTaken(): void
    {
        $recorded = $this->vendored('aaa', 'old');
        $held = (string) \file_get_contents($this->root.'/patch/webform/mr940.diff');

        $result = $this->vendoring(self::answers())->run(self::declarations(provenance: $recorded), new Scope([], []), false);

        self::assertSame('patch/webform/mr940.diff', $result->moved[0]['path']);
        self::assertSame([], $result->vendored);
        self::assertSame($held, \file_get_contents($this->root.'/patch/webform/mr940.diff'));
    }

    public function testRefreshTakesTheNewBytes(): void
    {
        $recorded = $this->vendored('aaa', 'old');

        $result = $this->vendoring(self::answers())->run(self::declarations(provenance: $recorded), new Scope([], []), false, true);

        self::assertSame('patch/webform/mr940.diff', $result->vendored[0]['path']);
        self::assertSame([], $result->moved);
        self::assertSame('bbb', $result->vendored[0]['provenance']['head']);
    }

    // The moved check and the copy that follows it are about the same
    // request, so one run asks the host about it once.
    public function testRefreshAsksAboutTheRequestOnce(): void
    {
        $recorded = $this->vendored('aaa', 'old');
        $api = 'https://git.drupalcode.org/api/v4/projects/project%2Fwebform/merge_requests/940';

        $this->vendoring(self::answers(), $asked = self::log())->run(self::declarations(provenance: $recorded), new Scope([], []), false, true);

        self::assertCount(1, \array_keys($asked->getArrayCopy(), $api, true));
    }

    public function testRefreshLeavesAFileGitReportsAsChanged(): void
    {
        $recorded = $this->vendored('aaa', 'old');
        $this->tree = new WorkingTree(new FakeGit(0, ' M patch/webform/mr940.diff'));

        $result = $this->vendoring(self::answers())->run(self::declarations(provenance: $recorded), new Scope([], []), false, true);

        self::assertSame(WorkingTree::UNCOMMITTED, $result->refused[0]['reason']);
        self::assertSame('--force', $result->refused[0]['lifts']);
        self::assertSame(self::DIFF, (string) \file_get_contents($this->root.'/patch/webform/mr940.diff'));
    }

    // A commit URL pins itself, so a run asks nothing about one it holds.
    public function testAVendoredCommitIsNeverAskedAbout(): void
    {
        $sha = '0207b39d318f3b62bbaa396d79f1ac6d2b53e40a';
        $url = 'https://git.drupalcode.org/project/webform/-/commit/'.$sha.'.diff';
        \mkdir($this->root.'/patch/webform', 0o777, true);
        \file_put_contents($this->root.'/patch/webform/commit-0207b39d318f.diff', self::DIFF);

        $result = $this->vendoring([], $asked = self::log())->run(self::declarations($url), new Scope([], []), false, true);

        self::assertSame('patch/webform/commit-0207b39d318f.diff', $result->kept[0]['path']);
        self::assertSame([], $asked->getArrayCopy());
    }

    // The most common URL a site declares is an uploaded file on drupal.org.
    // It is copied as it stands, under the name it ends in.
    public function testAnUploadedFileIsCopiedAsItStands(): void
    {
        $url = 'https://www.drupal.org/files/issues/2022-02-25/webform-3131794-15.patch';

        $result = $this->vendoring([$url => [200, self::DIFF]])->run(self::declarations($url), new Scope([], []), false);

        self::assertSame('patch/webform/webform-3131794-15.patch', $result->vendored[0]['path']);
        self::assertSame(self::DIFF, (string) \file_get_contents($this->root.'/patch/webform/webform-3131794-15.patch'));
        self::assertSame($url, $result->vendored[0]['provenance']['url']);
    }

    public function testALocalDeclarationIsNotItsBusiness(): void
    {
        $result = $this->vendoring(self::answers(), $asked = self::log())->run(self::declarations('patches/webform.patch'), new Scope([], []), false);

        self::assertSame([], $result->vendored);
        self::assertSame([], $result->refused);
        self::assertSame([], $result->kept);
        self::assertSame([], $asked->getArrayCopy());
    }

    // The diff between two commits never changes, so the copy composer keeps
    // for a day is the one to read. What the merge request points at does
    // change, so that answer is asked for again every run.
    public function testTheDiffIsReadFromTheCacheAndTheLookupIsNot(): void
    {
        $cache = $this->root.'/cache';
        $asked = self::log();
        $this->vendoring(self::answers(), $asked, $cache)->run(self::declarations(), new Scope([], []), false);
        \unlink($this->root.'/patch/webform/mr940.diff');
        $this->vendoring(self::answers(), $asked, $cache)->run(self::declarations(), new Scope([], []), false);

        $compare = 'https://git.drupalcode.org/project/webform/-/compare/aaa...bbb?format=diff';
        $api = 'https://git.drupalcode.org/api/v4/projects/project%2Fwebform/merge_requests/940';
        self::assertCount(1, \array_keys($asked->getArrayCopy(), $compare, true));
        self::assertCount(2, \array_keys($asked->getArrayCopy(), $api, true));
    }

    // A commit URL pins its own bytes, so the run reads it as declared and
    // asks the merge request endpoint nothing.
    public function testACommitIsCopiedFromTheUrlTheSiteNamed(): void
    {
        $sha = '0207b39d318f3b62bbaa396d79f1ac6d2b53e40a';
        $url = 'https://git.drupalcode.org/project/webform/-/commit/'.$sha.'.diff';
        $asked = self::log();

        $result = $this->vendoring([$url => [200, self::DIFF]], $asked)->run(self::declarations($url), new Scope([], []), false);

        self::assertSame('patch/webform/commit-0207b39d318f.diff', $result->vendored[0]['path']);
        self::assertSame([$url], $asked->getArrayCopy());
        $record = $result->vendored[0]['provenance'];
        self::assertSame($sha, $record['commit']);
        self::assertArrayNotHasKey('mr', $record);
    }

    public function testAScopeNarrowsWhatItTouches(): void
    {
        $result = $this->vendoring(self::answers(), $asked = self::log())->run(self::declarations(), new Scope(['drupal/token'], []), false);

        self::assertSame([], $result->vendored);
        self::assertSame([], $asked->getArrayCopy());
    }

    // A repository written by an older release still holds the `# drupatch`
    // line. A run that keeps such a copy moves the object onto the
    // declaration and cuts it from the file.
    public function testAnOldHeaderIsLiftedOntoTheDeclaration(): void
    {
        $line = '# drupatch {"mr":"https://git.drupalcode.org/project/webform/-/merge_requests/940","base":"aaa","head":"bbb"}'."\n";
        \mkdir($this->root.'/patch/webform', 0o777, true);
        \file_put_contents($this->root.'/patch/webform/mr940.diff', $line.self::DIFF);

        $result = $this->vendoring(self::answers())->run(self::declarations(), new Scope([], []), false);

        self::assertSame(
            ['mr' => 'https://git.drupalcode.org/project/webform/-/merge_requests/940', 'base' => 'aaa', 'head' => 'bbb'],
            $result->kept[0]['provenance']
        );
        self::assertSame(self::DIFF, (string) \file_get_contents($this->root.'/patch/webform/mr940.diff'));
    }

    // 1.x holds a compact declaration, which has nowhere to put the record,
    // so the line stays where it is.
    public function testTheOldHeaderStaysOnTheOneLine(): void
    {
        $line = '# drupatch {"mr":"https://git.drupalcode.org/project/webform/-/merge_requests/940","head":"bbb"}'."\n";
        \mkdir($this->root.'/patch/webform', 0o777, true);
        \file_put_contents($this->root.'/patch/webform/mr940.diff', $line.self::DIFF);

        $result = $this->vendoring(self::answers(), manager: '1.7.3')->run(self::declarations(), new Scope([], []), false);

        self::assertSame([], $result->kept[0]['provenance']);
        self::assertSame($line.self::DIFF, (string) \file_get_contents($this->root.'/patch/webform/mr940.diff'));
    }

    public function testADryRunLiftsNothingFromTheFile(): void
    {
        $line = '# drupatch {"mr":"https://git.drupalcode.org/project/webform/-/merge_requests/940","head":"bbb"}'."\n";
        \mkdir($this->root.'/patch/webform', 0o777, true);
        \file_put_contents($this->root.'/patch/webform/mr940.diff', $line.self::DIFF);

        $this->vendoring(self::answers())->run(self::declarations(), new Scope([], []), true);

        self::assertSame($line.self::DIFF, (string) \file_get_contents($this->root.'/patch/webform/mr940.diff'));
    }

    // Once a copy is in the site the declaration names the file, so the
    // record on it is the only thing that still names the merge request.
    public function testARefreshReachesTheRequestThroughTheRecord(): void
    {
        $recorded = $this->vendored('aaa', 'old');
        $declared = self::declarations('patch/webform/mr940.diff', $recorded);

        $result = $this->vendoring(self::answers())->run($declared, new Scope([], []), false, true);

        self::assertSame('patch/webform/mr940.diff', $result->vendored[0]['path']);
        self::assertSame('bbb', $result->vendored[0]['provenance']['head']);
        self::assertSame(self::DIFF, (string) \file_get_contents($this->root.'/patch/webform/mr940.diff'));
    }

    // A bare run on a site whose copies are all in place asks nothing, so
    // adding a patch costs no request per patch already there.
    public function testABareRunAsksNothingAboutACopyTheDeclarationNamesByPath(): void
    {
        $recorded = $this->vendored('aaa', 'old');
        $declared = self::declarations('patch/webform/mr940.diff', $recorded);

        $result = $this->vendoring(self::answers(), $asked = self::log())->run($declared, new Scope([], []), false);

        self::assertSame([], $asked->getArrayCopy());
        self::assertSame([[], [], [], []], [$result->vendored, $result->kept, $result->moved, $result->refused]);
    }

    public function testARefreshOfADeclarationWithNoRecordFindsNothing(): void
    {
        $this->vendored('aaa', 'old');
        $declared = self::declarations('patch/webform/mr940.diff');

        $result = $this->vendoring(self::answers(), $asked = self::log())->run($declared, new Scope([], []), false, true);

        self::assertSame([], $asked->getArrayCopy());
        self::assertSame([], $result->vendored);
    }

    // A refresh measures the record again from the request's own commits, so
    // a key an earlier run wrote and this one did not measure is gone.
    public function testARefreshRebuildsTheRecord(): void
    {
        $recorded = $this->vendored('aaa', 'old') + ['rerolled' => '6.2.9'];
        $declared = self::declarations('patch/webform/mr940.diff', $recorded);

        $record = $this->vendoring(self::answers())->run($declared, new Scope([], []), false, true)->vendored[0]['provenance'];

        self::assertSame(['mr', 'base', 'head', 'fetched'], \array_keys($record));
        self::assertSame('bbb', $record['head'], 'the head comes from the request');
        self::assertSame(\date('Y-m-d'), $record['fetched']);
    }
}

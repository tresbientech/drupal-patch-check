<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Header;
use TresBienTech\Drupatch\PatchText;
use TresBienTech\Drupatch\Scope;
use TresBienTech\Drupatch\Tests\Write\FakeGit;
use TresBienTech\Drupatch\Vendoring;
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
        self::remove($this->root);
    }

    private static function remove(string $path): void
    {
        if (\is_dir($path)) {
            foreach (\array_diff((array) \scandir($path), ['.', '..']) as $entry) {
                self::remove($path.'/'.$entry);
            }
            @\rmdir($path);

            return;
        }
        @\unlink($path);
    }

    /**
     * @param array<string, array{int, string}> $answers status and body per URL
     * @param ArrayObject<int, string>|null     $asked   every URL the run reached
     */
    private function vendoring(array $answers, ?ArrayObject $asked = null, string $cache = ''): Vendoring
    {
        $fetch = static function (string $url) use ($answers, $asked): array {
            $asked?->append($url);
            [$status, $body] = $answers[$url] ?? [404, ''];

            return ['status' => $status, 'body' => $body];
        };

        return new Vendoring($this->root, new PatchText($this->root, $fetch, $cache), 'patch', $this->tree);
    }

    /**
     * A vendored file as pin wrote it, at the commits given.
     */
    private function vendored(string $base, string $head): void
    {
        \mkdir($this->root.'/patch/webform', 0o777, true);
        \file_put_contents($this->root.'/patch/webform/mr940.diff', Header::line([
            'mr' => 'https://git.drupalcode.org/project/webform/-/merge_requests/940',
            'base' => $base,
            'head' => $head,
            'fetched' => '2026-09-01',
            'sha256' => Header::hash(self::DIFF),
        ]).self::DIFF);
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
     * @return list<array{package: string, title: string, source: string}>
     */
    private static function declarations(string $source = self::MR): array
    {
        return [['package' => 'drupal/webform', 'title' => 'fix the alter hook', 'source' => $source]];
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

    public function testItWritesTheDiffWithItsProvenance(): void
    {
        $result = $this->vendoring(self::answers())->run(self::declarations(), new Scope([], []), false);

        self::assertSame([], $result['refused']);
        self::assertSame('patch/webform/mr940.diff', $result['vendored'][0]['path']);
        $written = (string) \file_get_contents($this->root.'/patch/webform/mr940.diff');
        self::assertSame(self::DIFF, Header::body($written));
        $header = Header::read($written);
        self::assertSame('https://git.drupalcode.org/project/webform/-/merge_requests/940', $header['mr']);
        self::assertSame('aaa', $header['base']);
        self::assertSame('bbb', $header['head']);
        self::assertSame(Header::hash(self::DIFF), $header['sha256']);
        self::assertSame(\date('Y-m-d'), $header['fetched']);
    }

    public function testADryRunAsksAndWritesNothing(): void
    {
        $result = $this->vendoring(self::answers())->run(self::declarations(), new Scope([], []), true);

        self::assertSame('patch/webform/mr940.diff', $result['vendored'][0]['path']);
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

        self::assertSame('the host answered 429', $result['refused'][0]['reason']);
        self::assertSame('drupal/webform', $result['refused'][0]['package']);
        self::assertSame('patch/token/mr12.diff', $result['vendored'][0]['path']);
    }

    public function testAnAnswerThatIsNotADiffIsRefused(): void
    {
        $answers = self::answers();
        $answers['https://git.drupalcode.org/project/webform/-/compare/aaa...bbb?format=diff'] = [200, '<!DOCTYPE html>'];

        $result = $this->vendoring($answers)->run(self::declarations(), new Scope([], []), false);

        self::assertSame('what came back is not a diff', $result['refused'][0]['reason']);
        self::assertSame([], $result['vendored']);
    }

    public function testARequestWithNoCommitsYetIsRefused(): void
    {
        $answers = self::answers();
        $answers['https://git.drupalcode.org/api/v4/projects/project%2Fwebform/merge_requests/940'] = [200, '{"sha":null,"diff_refs":null}'];

        $result = $this->vendoring($answers)->run(self::declarations(), new Scope([], []), false);

        self::assertSame('the merge request names no commits yet', $result['refused'][0]['reason']);
    }

    // The file is the site's now, and taking new bytes is its own move.
    // The declaration still has to name it, so an interrupted run finishes.
    public function testAPatchAlreadyVendoredKeepsItsBytes(): void
    {
        $this->vendored('aaa', 'bbb');

        $result = $this->vendoring(self::answers(), $asked = self::log())->run(self::declarations(), new Scope([], []), false);

        self::assertSame([], $result['vendored']);
        self::assertSame([], $result['refused']);
        self::assertSame([], $result['moved']);
        self::assertSame('patch/webform/mr940.diff', $result['kept'][0]['path']);
        self::assertSame(['https://git.drupalcode.org/api/v4/projects/project%2Fwebform/merge_requests/940'], $asked->getArrayCopy());
    }

    // Somebody pushed to the request since the site copied it. The bytes stay
    // as they are until a person asks for the new ones.
    public function testAMovedRequestIsReportedAndNotTaken(): void
    {
        $this->vendored('aaa', 'old');
        $held = (string) \file_get_contents($this->root.'/patch/webform/mr940.diff');

        $result = $this->vendoring(self::answers())->run(self::declarations(), new Scope([], []), false);

        self::assertSame('patch/webform/mr940.diff', $result['moved'][0]['path']);
        self::assertSame([], $result['vendored']);
        self::assertSame($held, \file_get_contents($this->root.'/patch/webform/mr940.diff'));
    }

    public function testRefreshTakesTheNewBytes(): void
    {
        $this->vendored('aaa', 'old');

        $result = $this->vendoring(self::answers())->run(self::declarations(), new Scope([], []), false, true);

        self::assertSame('patch/webform/mr940.diff', $result['vendored'][0]['path']);
        self::assertSame([], $result['moved']);
        self::assertSame('bbb', Header::read((string) \file_get_contents($this->root.'/patch/webform/mr940.diff'))['head']);
    }

    // The moved check and the copy that follows it are about the same
    // request, so one run asks the host about it once.
    public function testRefreshAsksAboutTheRequestOnce(): void
    {
        $this->vendored('aaa', 'old');
        $api = 'https://git.drupalcode.org/api/v4/projects/project%2Fwebform/merge_requests/940';

        $this->vendoring(self::answers(), $asked = self::log())->run(self::declarations(), new Scope([], []), false, true);

        self::assertCount(1, \array_keys($asked->getArrayCopy(), $api, true));
    }

    public function testRefreshLeavesAFileGitReportsAsChanged(): void
    {
        $this->vendored('aaa', 'old');
        $this->tree = new WorkingTree(new FakeGit(0, ' M patch/webform/mr940.diff'));

        $result = $this->vendoring(self::answers())->run(self::declarations(), new Scope([], []), false, true);

        self::assertSame(WorkingTree::UNCOMMITTED, $result['refused'][0]['reason']);
        self::assertSame('old', Header::read((string) \file_get_contents($this->root.'/patch/webform/mr940.diff'))['head']);
    }

    // A commit URL pins itself, so a run asks nothing about one it holds.
    public function testAVendoredCommitIsNeverAskedAbout(): void
    {
        $sha = '0207b39d318f3b62bbaa396d79f1ac6d2b53e40a';
        $url = 'https://git.drupalcode.org/project/webform/-/commit/'.$sha.'.diff';
        \mkdir($this->root.'/patch/webform', 0o777, true);
        \file_put_contents($this->root.'/patch/webform/commit-0207b39d318f.diff', Header::line(['commit' => $sha]).self::DIFF);

        $result = $this->vendoring([], $asked = self::log())->run(self::declarations($url), new Scope([], []), false, true);

        self::assertSame('patch/webform/commit-0207b39d318f.diff', $result['kept'][0]['path']);
        self::assertSame([], $asked->getArrayCopy());
    }

    // The most common URL a site declares is an uploaded file on drupal.org.
    // It is copied as it stands, under the name it ends in.
    public function testAnUploadedFileIsCopiedAsItStands(): void
    {
        $url = 'https://www.drupal.org/files/issues/2022-02-25/webform-3131794-15.patch';

        $result = $this->vendoring([$url => [200, self::DIFF]])->run(self::declarations($url), new Scope([], []), false);

        self::assertSame('patch/webform/webform-3131794-15.patch', $result['vendored'][0]['path']);
        $header = Header::read((string) \file_get_contents($this->root.'/patch/webform/webform-3131794-15.patch'));
        self::assertSame($url, $header['url']);
        self::assertSame(Header::hash(self::DIFF), $header['sha256']);
    }

    public function testALocalDeclarationIsNotItsBusiness(): void
    {
        $result = $this->vendoring(self::answers(), $asked = self::log())->run(self::declarations('patches/webform.patch'), new Scope([], []), false);

        self::assertSame([], $result['vendored']);
        self::assertSame([], $result['refused']);
        self::assertSame([], $result['kept']);
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

        self::assertSame('patch/webform/commit-0207b39d318f.diff', $result['vendored'][0]['path']);
        self::assertSame([$url], $asked->getArrayCopy());
        $header = Header::read((string) \file_get_contents($this->root.'/patch/webform/commit-0207b39d318f.diff'));
        self::assertSame($sha, $header['commit']);
        self::assertArrayNotHasKey('mr', $header);
    }

    public function testAScopeNarrowsWhatItTouches(): void
    {
        $result = $this->vendoring(self::answers(), $asked = self::log())->run(self::declarations(), new Scope(['drupal/token'], []), false);

        self::assertSame([], $result['vendored']);
        self::assertSame([], $asked->getArrayCopy());
    }
}

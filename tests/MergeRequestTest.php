<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests;

use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Source\Header;
use TresBienTech\Drupatch\Source\MergeRequest;
use TresBienTech\Drupatch\Source\Provenance;

class MergeRequestTest extends TestCase
{
    public function testAPatchAndADiffNameTheSameRequest(): void
    {
        $patch = MergeRequest::of('https://git.drupalcode.org/project/webform/-/merge_requests/940.patch');
        $diff = MergeRequest::of('https://git.drupalcode.org/project/webform/-/merge_requests/940.diff');

        self::assertNotNull($patch);
        self::assertNotNull($diff);
        self::assertSame('webform', $patch->project);
        self::assertSame('940', $patch->iid);
        self::assertSame('https://git.drupalcode.org/project/webform/-/merge_requests/940', $patch->url);
        self::assertEquals($patch, $diff);
    }

    public function testAnythingElseNamesNone(): void
    {
        foreach ([
            'patches/webform.patch',
            'https://www.drupal.org/files/issues/2026-01-01/webform-3521733-12.patch',
            'https://github.com/drupal/webform/pull/12.patch',
            'https://git.drupalcode.org/project/webform/-/merge_requests/940',
            'https://git.drupalcode.org/project/webform/-/commit/abc.diff',
            '',
        ] as $source) {
            self::assertNull(MergeRequest::of($source), $source);
        }
    }

    // The plugin counts these on every run, with no plan and no answer
    // from the service to read a verdict from.
    public function testTheDeclarationsNamingARequestAreTheOnesReturned(): void
    {
        $declarations = [
            ['package' => 'drupal/webform', 'title' => 'a', 'source' => 'https://git.drupalcode.org/project/webform/-/merge_requests/940.patch'],
            ['package' => 'drupal/webform', 'title' => 'b', 'source' => 'https://git.drupalcode.org/project/webform/-/commit/abc.diff'],
            ['package' => 'drupal/webform', 'title' => 'c', 'source' => 'https://www.drupal.org/files/issues/2026-01-01/webform-3521733-12.patch'],
            ['package' => 'drupal/webform', 'title' => 'd', 'source' => 'patches/webform/fix.patch'],
        ];

        self::assertSame([$declarations[0]], MergeRequest::among($declarations));
    }

    public function testTheApiAnswersWithoutCredentials(): void
    {
        $mr = MergeRequest::of('https://git.drupalcode.org/project/webform/-/merge_requests/940.patch');

        self::assertNotNull($mr);
        self::assertSame('https://git.drupalcode.org/api/v4/projects/project%2Fwebform/merge_requests/940', $mr->api());
    }

    // The diff is asked for between two commits, so the bytes cannot change
    // between the lookup and the fetch.
    public function testTheDiffIsAskedForBetweenTwoCommits(): void
    {
        $mr = MergeRequest::of('https://git.drupalcode.org/project/webform/-/merge_requests/940.patch');

        self::assertNotNull($mr);
        self::assertSame(
            'https://git.drupalcode.org/project/webform/-/compare/aaa...bbb?format=diff',
            $mr->compare('aaa', 'bbb')
        );
    }

    public function testTheFileIsNamedAfterTheRequest(): void
    {
        $mr = MergeRequest::of('https://git.drupalcode.org/project/webform/-/merge_requests/940.patch');

        self::assertNotNull($mr);
        self::assertSame('patch/webform/mr940.diff', $mr->file('patch'));
        self::assertSame('patches/webform/mr940.diff', $mr->file('patches'));
    }

    public function testAFileWithNoHeaderHasNone(): void
    {
        self::assertSame([], Header::read("diff --git a/a b/a\n"));
        self::assertSame([], Header::read("diff --git a/a b/a\n# drupatch {\"mr\":\"m\"}\n"));
        self::assertSame([], Header::read('# drupatch {"mr"'));
    }

    // A repository written by an older release still holds the line, and a
    // run that touches such a file moves the object onto the declaration.
    // The hash the line carried is dropped: the patch lock keeps its own.
    public function testAnOldHeaderReadsBackAsAProvenanceRecord(): void
    {
        $body = "diff --git a/a b/a\n+one\n";
        $line = '# drupatch {"mr":"m","base":"aaa","head":"bbb","fetched":"2026-09-09","sha256":"abc"}'."\n";

        self::assertSame($body, Header::body($line.$body));
        self::assertSame(
            ['mr' => 'm', 'base' => 'aaa', 'head' => 'bbb', 'fetched' => '2026-09-09'],
            Provenance::of(Header::read($line.$body))
        );
    }

    // A file nobody pinned is its own body, so a caller reads one thing.
    public function testAFileWithNoHeaderIsAllBody(): void
    {
        self::assertSame("diff --git a/a b/a\n", Header::body("diff --git a/a b/a\n"));
    }

    // drupal.org opens one GitLab project per issue and pushes every merge
    // request on that issue from it.
    public function testAForkPathNamesItsIssue(): void
    {
        self::assertSame('3521733', MergeRequest::issueIn('issue/webform-3521733'));
        self::assertSame('12', MergeRequest::issueIn('issue/token_filter-12'));
    }

    public function testAnythingElseNamesNoIssue(): void
    {
        foreach ([
            'project/webform',
            'issue/webform',
            'issue/webform-3521733/nested',
            'issue/Webform-3521733',
            'issue/webform-abc',
            '',
        ] as $path) {
            self::assertSame('', MergeRequest::issueIn($path), $path);
        }
    }

    public function testAProjectIsReadByItsOwnId(): void
    {
        self::assertSame('https://git.drupalcode.org/api/v4/projects/243137', MergeRequest::projectApi(243137));
    }
}

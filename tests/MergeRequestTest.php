<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests;

use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Header;
use TresBienTech\Drupatch\MergeRequest;

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

    public function testTheHeaderReadsBackWhatItWrote(): void
    {
        $line = Header::line(['mr' => 'https://git.drupalcode.org/project/webform/-/merge_requests/940', 'base' => 'aaa', 'head' => 'bbb', 'fetched' => '2026-09-09', 'sha256' => 'ccc']);

        self::assertStringStartsWith('# drupatch {"mr":"https://git.drupalcode.org/project/webform/-/merge_requests/940","base":"aaa","head":"bbb","fetched":"2026-09-09","sha256":"ccc"}', $line);
        self::assertSame('bbb', Header::read($line."diff --git a/a b/a\n")['head'] ?? '');
    }

    // A key written in any order comes back in one order, because the file
    // is read by two languages and diffed by people.
    public function testTheKeysAreWrittenInOneOrder(): void
    {
        $line = Header::line(['sha256' => 'ccc', 'head' => 'bbb', 'mr' => 'm', 'fetched' => 'd', 'base' => 'aaa']);

        self::assertSame('# drupatch {"mr":"m","base":"aaa","head":"bbb","fetched":"d","sha256":"ccc"}'."\n", $line);
    }

    public function testAFileWithNoHeaderHasNone(): void
    {
        self::assertSame([], Header::read("diff --git a/a b/a\n"));
        self::assertSame([], Header::read("diff --git a/a b/a\n# drupatch {\"mr\":\"m\"}\n"));
        self::assertSame([], Header::read('# drupatch {"mr"'));
    }

    public function testTheHashCoversWhatSitsUnderTheHeader(): void
    {
        $body = "diff --git a/a b/a\n+one\n";
        $file = Header::line(['mr' => 'm', 'sha256' => Header::hash($body)]).$body;

        self::assertSame($body, Header::body($file));
        self::assertSame(Header::read($file)['sha256'], Header::hash(Header::body($file)));
    }

    // A file nobody pinned is its own body, so a caller reads one thing.
    public function testAFileWithNoHeaderIsAllBody(): void
    {
        self::assertSame("diff --git a/a b/a\n", Header::body("diff --git a/a b/a\n"));
    }
}

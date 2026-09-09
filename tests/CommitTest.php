<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests;

use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Commit;

/**
 * A commit URL pins its own bytes. The site copies them so its install stops
 * asking a host for code, and the copy says which commit it holds.
 */
class CommitTest extends TestCase
{
    private const SHA = '0207b39d318f3b62bbaa396d79f1ac6d2b53e40a';

    public function testAProjectCommitIsRead(): void
    {
        $commit = Commit::of('https://git.drupalcode.org/project/website_feedback/-/commit/'.self::SHA.'.diff');

        self::assertNotNull($commit);
        self::assertSame('website_feedback', $commit->project);
        self::assertSame(self::SHA, $commit->sha);
    }

    // Real sites declare commits on the issue fork the work was pushed to.
    public function testAnIssueForkCommitBelongsToItsProject(): void
    {
        $commit = Commit::of('https://git.drupalcode.org/issue/drupal-3519447/-/commit/'.self::SHA.'.patch');

        self::assertNotNull($commit);
        self::assertSame('drupal', $commit->project);
        self::assertSame(self::SHA, $commit->sha);
    }

    public function testAnythingElseNamesNoCommit(): void
    {
        foreach ([
            'https://git.drupalcode.org/project/webform/-/merge_requests/940.patch',
            'https://git.drupalcode.org/project/webform/-/commit/abc.diff',
            'https://evil.example/project/webform/-/commit/'.self::SHA.'.diff',
            'https://git.drupalcode.org/project/webform/-/commit/'.self::SHA,
            'patches/webform.patch',
        ] as $source) {
            self::assertNull(Commit::of($source), $source);
        }
    }

    // The file is a plain diff, so a commit declared in the mail form is read
    // in the form the file is named for.
    public function testTheMailFormIsReadAsADiff(): void
    {
        $commit = Commit::of('https://git.drupalcode.org/issue/drupal-3519447/-/commit/'.self::SHA.'.patch');

        self::assertNotNull($commit);
        self::assertSame('https://git.drupalcode.org/issue/drupal-3519447/-/commit/'.self::SHA.'.diff', $commit->diff());
    }

    public function testTheFileIsNamedAfterTheCommit(): void
    {
        $commit = Commit::of('https://git.drupalcode.org/project/website_feedback/-/commit/'.self::SHA.'.diff');

        self::assertNotNull($commit);
        self::assertSame('patch/website_feedback/commit-0207b39d318f.diff', $commit->file('patch'));
    }
}

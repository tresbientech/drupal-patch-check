<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Source\IssueReference;

#[CoversClass(IssueReference::class)]
class IssueReferenceTest extends TestCase
{
    // The number is the same on both sides of the issue migration, so a queue
    // that moved to GitLab reads the same as one that has not.
    public function testBothFormsOfTheSameIssueReadAlike(): void
    {
        $queue = IssueReference::of('https://www.drupal.org/project/webform/issues/3521733');
        $item = IssueReference::of('https://git.drupalcode.org/project/webform/-/work_items/3521733');

        self::assertNotNull($queue);
        self::assertNotNull($item);
        self::assertEquals($queue, $item);
        self::assertSame('webform', $queue->project);
        self::assertSame('3521733', $queue->number);
    }

    public function testATrailingSlashAndSurroundingSpaceAreIgnored(): void
    {
        $found = IssueReference::of('  https://www.drupal.org/project/webform/issues/3521733/  ');

        self::assertNotNull($found);
        self::assertSame('3521733', $found->number);
    }

    public function testAnythingElseNamesNoIssue(): void
    {
        foreach ([
            'https://www.drupal.org/project/webform/issues/',
            'https://www.drupal.org/project/webform/issues/abc',
            'https://www.drupal.org/node/3521733',
            'https://git.drupalcode.org/project/webform/-/merge_requests/940.diff',
            'https://evil.example/project/webform/issues/3521733',
            'http://www.drupal.org/project/webform/issues/3521733',
            'webform 3521733',
            '',
        ] as $reference) {
            self::assertNull(IssueReference::of($reference), $reference);
        }
    }

    public function testTheSearchAsksTheProjectForTheNumber(): void
    {
        $found = IssueReference::of('https://www.drupal.org/project/webform/issues/3521733');

        self::assertNotNull($found);
        self::assertSame(
            'https://git.drupalcode.org/api/v4/projects/project%2Fwebform/merge_requests?state=all&per_page=50&search=3521733',
            $found->search(50)
        );
    }

    // A site titles its declarations by hand, and drupal.org's convention is
    // to lead with the issue number. A number further along is a version, a
    // date or a comment as often as it is an issue.
    public function testATitleLeadingWithAnIssueNumberNamesThatIssue(): void
    {
        foreach ([
            '3218426' => '3218426',
            '3218426: dblog cap' => '3218426',
            '#3218426 dblog cap' => '3218426',
            '  3218426 - cap the table' => '3218426',
            '2866029: Views' => '2866029',
        ] as $title => $number) {
            $found = IssueReference::inDeclaration('drupal', (string) $title, 'patches/x.patch');

            self::assertNotNull($found, (string) $title);
            self::assertSame($number, $found->number, (string) $title);
            self::assertSame('drupal', $found->project, (string) $title);
        }
    }

    // A patch file is named after what it carries, so a title naming no
    // issue falls through to the file. The first number wins, because
    // `2466553-175.patch` names the issue and then the comment on it.
    public function testAFileNameCarriesTheIssueWhenTheTitleDoesNot(): void
    {
        foreach ([
            'patches/drupal/2466553-175.patch' => '2466553',
            'patchs/language_cookie/361132-fix.patch' => '361132',
            './resources/patches/core/3218426.patch' => '3218426',
            'patches/3120360-menu-item-extras.diff' => '3120360',
        ] as $source => $number) {
            $found = IssueReference::inDeclaration('drupal', 'a title with no number', (string) $source);

            self::assertNotNull($found, (string) $source);
            self::assertSame($number, $found->number, (string) $source);
        }
    }

    public function testADeclarationNamingNoIssueIsLeftAlone(): void
    {
        foreach ([
            ['dblog cap', 'patches/webform/fix.patch'],
            ['bump to 10.3.1', 'patches/bump.patch'],
            ['patch 42', 'patches/42.patch'],
            // A directory that holds digits is not the file's own name.
            ['no number', 'patches/2466553/fix.patch'],
            // A longer run of digits is a date, a hash or a timestamp.
            ['no number', 'patches/20260910123456-fix.patch'],
            ['', ''],
        ] as [$title, $source]) {
            self::assertNull(IssueReference::inDeclaration('drupal', $title, $source), $title.' '.$source);
        }
    }
}

<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use TresBienTech\Drupatch\PatchText;

/**
 * What one declared source yields: the text that travels, or why the run could not get it.
 */
class PatchTextTest extends TestCase
{
    private const DIFF = "diff --git a/x b/x\n--- a/x\n+++ b/x\n@@ -1 +1 @@\n-a\n+b\n";

    private const URL = 'https://git.acme-internal.com/drupal/webform/-/merge_requests/4.patch';

    private string $root;

    private string $cache;

    /** @var list<string> */
    private array $asked = [];

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir().'/drupatch-text-'.\bin2hex(\random_bytes(6));
        $this->cache = $this->root.'/cache';
        \mkdir($this->root.'/patches', 0o777, true);
        \file_put_contents($this->root.'/patches/local.patch', self::DIFF);
    }

    protected function tearDown(): void
    {
        foreach (['patches/local.patch', 'patches/big.patch'] as $file) {
            @\unlink($this->root.'/'.$file);
        }
        foreach ((array) \glob($this->cache.'/*') as $file) {
            @\unlink((string) $file);
        }
        @\rmdir($this->cache);
        @\rmdir($this->root.'/patches');
        @\rmdir($this->root);
    }

    public function testAFileOnDiskTravelsUnderItsDeclaredPath(): void
    {
        $read = $this->reader()->read('patches/local.patch');

        self::assertSame(['patches/local.patch' => self::DIFF], $read['files']);
        self::assertSame('', $read['reason']);
    }

    public function testAUrlIsFetchedAndTravelsUnderItsUrl(): void
    {
        $read = $this->reader()->read(self::URL);

        self::assertSame([self::URL => self::DIFF], $read['files']);
        self::assertSame([self::URL], $this->asked);
    }

    public function testAHostThatRefusesSaysWhatItAnswered(): void
    {
        $read = $this->reader([self::URL => ['status' => 401, 'body' => '']])->read(self::URL);

        self::assertSame('the host answered 401', $read['reason']);
        self::assertFalse($read['withheld'], 'the run has no patch, so the row goes with it');
    }

    public function testAHostThatCannotBeReachedSaysSo(): void
    {
        $reader = new PatchText($this->root, static function (string $url): array {
            throw new RuntimeException('could not resolve host');
        }, '');

        self::assertStringContainsString('could not resolve host', $reader->read(self::URL)['reason']);
    }

    // A login page arrives with a 200, so the status alone does not say
    // the run has a patch.
    public function testALoginPageIsNotAPatch(): void
    {
        $html = "<!DOCTYPE html>\n<title>Sign in</title>\n<form action=\"/login\"></form>\n";

        $read = $this->reader([self::URL => ['status' => 200, 'body' => $html]])->read(self::URL);

        self::assertSame('what came back is not a diff', $read['reason']);
    }

    public function testAPathOutsideTheSiteRootIsNotRead(): void
    {
        self::assertSame('no file at that path', $this->reader()->read('../../etc/hosts')['reason']);
    }

    public function testAPathWithNoFileSaysSo(): void
    {
        self::assertSame('no file at that path', $this->reader()->read('patches/gone.patch')['reason']);
    }

    // Over the cap is a patch the run has and holds back, so the patch
    // keeps its row and its verdict.
    public function testAFileOverTheCapIsHeldBackRatherThanDropped(): void
    {
        \file_put_contents($this->root.'/patches/big.patch', \str_repeat('x', PatchText::MAX_BYTES + 1));

        $read = $this->reader()->read('patches/big.patch');

        self::assertTrue($read['withheld']);
        self::assertSame('above the 16 MB cap', $read['reason']);
    }

    public function testABodyOverTheCapIsHeldBackToo(): void
    {
        $read = $this->reader([self::URL => [
            'status' => 200, 'body' => \str_repeat('x', PatchText::MAX_BYTES + 1),
        ]])->read(self::URL);

        self::assertTrue($read['withheld']);
    }

    public function testASecondReadOfTheSameUrlAsksTheHostOnce(): void
    {
        $reader = $this->reader([], $this->cache);

        $reader->read(self::URL);
        $second = $reader->read(self::URL);

        self::assertSame([self::URL], $this->asked, 'the second read came from the cache');
        self::assertSame([self::URL => self::DIFF], $second['files']);
    }

    public function testACachedPatchOlderThanADayIsFetchedAgain(): void
    {
        $reader = $this->reader([], $this->cache);
        $reader->read(self::URL);
        $kept = (array) \glob($this->cache.'/*.patch');
        \touch((string) $kept[0], \time() - 86401);

        $reader->read(self::URL);

        self::assertSame([self::URL, self::URL], $this->asked);
    }

    // A series applies its later diffs onto blobs its earlier commits
    // marked up, so the merge wants the one-diff-per-file form beside it.
    public function testAMergeRequestPatchAlsoFetchesItsSquashedForm(): void
    {
        $mr = 'https://git.drupalcode.org/project/webform/-/merge_requests/22.patch';

        $read = $this->reader()->read($mr);

        self::assertSame([$mr, 'https://git.drupalcode.org/project/webform/-/merge_requests/22.diff'], \array_keys($read['files']));
        self::assertCount(2, $this->asked);
    }

    public function testAUrlThatIsNotAMergeRequestPatchIsFetchedOnce(): void
    {
        $this->reader()->read('https://www.drupal.org/files/issues/a.patch');

        self::assertCount(1, $this->asked);
    }

    // The squashed form only improves a merge, so losing it costs the
    // patch nothing.
    public function testASquashedFormThatFailsLeavesTheDeclaredPatchAlone(): void
    {
        $mr = 'https://git.drupalcode.org/project/webform/-/merge_requests/22.patch';
        $read = $this->reader([
            'https://git.drupalcode.org/project/webform/-/merge_requests/22.diff' => ['status' => 404, 'body' => ''],
        ])->read($mr);

        self::assertSame([$mr], \array_keys($read['files']));
        self::assertSame('', $read['reason']);
    }

    public function testAMergeRequestDiffUrlHasNoSiblingOfItsOwn(): void
    {
        self::assertSame('', PatchText::sibling('https://git.drupalcode.org/project/webform/-/merge_requests/22.diff'));
    }

    /**
     * A reader whose hosts answer what a case says, and a patch for any URL a case does not name.
     *
     * @param array<string, array{status: int, body: string}> $hosts
     */
    private function reader(array $hosts = [], string $cache = ''): PatchText
    {
        return new PatchText($this->root, function (string $url) use ($hosts): array {
            $this->asked[] = $url;

            return $hosts[$url] ?? ['status' => 200, 'body' => self::DIFF];
        }, $cache);
    }
}

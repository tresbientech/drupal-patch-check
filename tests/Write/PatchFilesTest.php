<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Write;

use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Header;
use TresBienTech\Drupatch\Plan\Plan;
use TresBienTech\Drupatch\Render\Report;
use TresBienTech\Drupatch\Tests\PlanFactory;
use TresBienTech\Drupatch\Write\Decisions;
use TresBienTech\Drupatch\Write\PatchFiles;
use TresBienTech\Drupatch\Write\WorkingTree;

class PatchFilesTest extends TestCase
{
    use PlanFactory;

    private string $root;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir().'/drupatch-write-'.\bin2hex(\random_bytes(6));
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
     * @return list<string>
     */
    private static function paths(string $pattern): array
    {
        $found = \glob($pattern);

        return false === $found ? [] : $found;
    }

    private function writer(Plan $plan): PatchFiles
    {
        return new PatchFiles($this->root, null, self::declaring($plan));
    }

    /**
     * The declarations of a site that declared exactly what the plan names.
     *
     * @return list<array{package: string, title: string, source: string}>
     */
    private static function declaring(Plan $plan): array
    {
        $out = [];
        foreach ($plan->patches as $row) {
            $out[] = ['package' => $row->package, 'title' => $row->title, 'source' => $row->source];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $reroll
     */
    private function plan(array $reroll, string $source = 'patches/webform/alter.patch'): Plan
    {
        return $this->planFrom(['patches' => [$this->rerolledRow($reroll, ['source' => $source, 'version' => '6.3.2'])]]);
    }

    private function declare(string $source, string $body = "old diff\n"): void
    {
        $full = $this->root.'/'.$source;
        $dir = \dirname($full);
        if (!\is_dir($dir)) {
            \mkdir($dir, 0o777, true);
        }
        \file_put_contents($full, $body);
    }

    public function testACleanRerollReplacesTheFileTheSiteDeclared(): void
    {
        $this->declare('patches/core/htaccess.patch');
        $plan = $this->plan(['status' => 'clean', 'patch' => "new diff\n", 'verified' => true], 'patches/core/htaccess.patch');

        $result = $this->writer($plan)->write($plan);

        self::assertCount(1, $result['written']);
        self::assertSame('patches/core/htaccess.patch', $result['written'][0]['path']);
        self::assertSame("new diff\n", \file_get_contents($this->root.'/patches/core/htaccess.patch'));
    }

    // The service parsed the merged file and it will not compile. Writing
    // the diff would hand over a patch known to break the site.
    public function testARerollThatLeavesABrokenFileIsNotWritten(): void
    {
        $this->declare('patches/core/htaccess.patch');
        $plan = $this->plan([
            'status' => 'clean',
            'patch' => "new diff\n",
            'verified' => false,
            'syntax_errors' => ['src/Form.php: unexpected } on line 4'],
        ], 'patches/core/htaccess.patch');

        $result = $this->writer($plan)->write($plan);

        self::assertSame([], $result['written']);
        self::assertCount(1, $result['refused']);
        self::assertSame(
            'its re-roll leaves a file that does not parse: src/Form.php: unexpected } on line 4',
            $result['refused'][0]['reason'],
        );
        self::assertSame("old diff\n", \file_get_contents($this->root.'/patches/core/htaccess.patch'), 'the file the site declares is untouched');
    }

    public function testAConflictedRerollIsWrittenBesideTheFileItCameFrom(): void
    {
        $this->declare('patches/core/htaccess.patch');
        $plan = $this->plan([
            'status' => 'conflicts',
            'patch' => "part\n",
            'conflicts' => [[
                'file' => 'src/Form.php',
                'regions' => 1,
                'hunks' => [['line' => 42, 'release_line' => 40, 'release' => "new code\n", 'patch' => "patched code\n"]],
            ]],
        ], 'patches/core/htaccess.patch');

        $result = $this->writer($plan)->write($plan);

        self::assertSame('patches/core/htaccess.conflict.patch', $result['written'][0]['path']);
        self::assertFalse('clean' === $result['written'][0]['status']);
        $body = (string) \file_get_contents($this->root.'/patches/core/htaccess.conflict.patch');
        self::assertStringContainsString('<<<<<<< release src/Form.php:40', $body);
    }

    public function testAConflictedRerollLeavesTheDeclaredFileAlone(): void
    {
        $this->declare('patches/core/htaccess.patch', "the working patch\n");
        $plan = $this->plan([
            'status' => 'conflicts',
            'conflicts' => [['file' => 'a.php', 'regions' => 1, 'hunks' => [['line' => 1, 'release' => "a\n", 'patch' => "b\n"]]]],
        ], 'patches/core/htaccess.patch');

        $this->writer($plan)->write($plan);

        self::assertSame("the working patch\n", \file_get_contents($this->root.'/patches/core/htaccess.patch'));
    }

    public function testAFileTheReleaseRemovedIsWrittenWithoutRegionsToDecide(): void
    {
        $this->declare('patches/core/claro.patch');
        $plan = $this->plan([
            'status' => 'conflicts',
            'conflicts' => [['file' => 'themes/claro/claro.theme', 'regions' => 1, 'removed' => true, 'hunks' => [['line' => 0, 'release' => "file does not exist in the release\n", 'patch' => "-function claro_x() {}\n"]]]],
        ], 'patches/core/claro.patch');

        $result = $this->writer($plan)->write($plan);
        $text = (string) \file_get_contents($this->root.'/patches/core/claro.conflict.patch');

        self::assertStringContainsString('themes/claro/claro.theme is not in the release', $text);
        self::assertStringContainsString('-function claro_x() {}', $text, 'the hunks stay, so the patch can be read');
        self::assertStringNotContainsString(PatchFiles::REGION_OPEN, $text);
        self::assertStringNotContainsString('<<<<<<<', $text);
        self::assertStringNotContainsString(Report::REROLL, $text, 'there is nothing to send back');
        self::assertSame(0, $result['written'][0]['regions']);
        self::assertSame([], $result['written'][0]['open']);
        self::assertSame(['themes/claro/claro.theme'], $result['written'][0]['removed']);
    }

    public function testAConflictedFileTheReleaseStillHasKeepsItsRegions(): void
    {
        $this->declare('patches/core/htaccess.patch');
        $plan = $this->plan([
            'status' => 'conflicts',
            'conflicts' => [['file' => 'a.php', 'regions' => 1, 'hunks' => [['line' => 1, 'release' => "a\n", 'patch' => "b\n"]]]],
        ], 'patches/core/htaccess.patch');

        $result = $this->writer($plan)->write($plan);
        $text = (string) \file_get_contents($this->root.'/patches/core/htaccess.conflict.patch');

        self::assertStringContainsString(PatchFiles::REGION_OPEN.'0 a.php', $text);
        self::assertSame([['file' => 'a.php', 'region' => 0]], $result['written'][0]['open']);
        self::assertSame([], $result['written'][0]['removed']);
    }

    public function testADiffExtensionIsReplacedRatherThanDoubled(): void
    {
        $this->declare('patches/core/10023.diff');
        $plan = $this->plan([
            'status' => 'conflicts',
            'conflicts' => [['file' => 'a.php', 'regions' => 1, 'hunks' => [['line' => 1, 'release' => "a\n", 'patch' => "b\n"]]]],
        ], 'patches/core/10023.diff');

        self::assertSame('patches/core/10023.conflict.patch', $this->writer($plan)->write($plan)['written'][0]['path']);
    }

    public function testAnyOtherExtensionIsKeptAndTheSuffixAppended(): void
    {
        $this->declare('patches/core/fix.txt');
        $plan = $this->plan([
            'status' => 'conflicts',
            'conflicts' => [['file' => 'a.php', 'regions' => 1, 'hunks' => [['line' => 1, 'release' => "a\n", 'patch' => "b\n"]]]],
        ], 'patches/core/fix.txt');

        self::assertSame('patches/core/fix.txt.conflict.patch', $this->writer($plan)->write($plan)['written'][0]['path']);
    }

    public function testEachPackageIsWrittenUnderItsOwnDeclaredDirectory(): void
    {
        $this->declare('patches/core/htaccess.patch');
        $this->declare('patches/pathauto/translated.patch');
        $plan = $this->planFrom(['patches' => [
            $this->rerolledRow(['status' => 'clean', 'patch' => "a\n"], ['package' => 'drupal/core', 'source' => 'patches/core/htaccess.patch']),
            $this->rerolledRow(['status' => 'clean', 'patch' => "b\n"], ['package' => 'drupal/pathauto', 'source' => 'patches/pathauto/translated.patch']),
        ]]);

        $result = $this->writer($plan)->write($plan);

        self::assertSame(
            ['patches/core/htaccess.patch', 'patches/pathauto/translated.patch'],
            \array_map(static fn ($file): string => $file['path'], $result['written'])
        );
    }

    public function testACleanRerollRemovesTheConflictFileAnEarlierRunLeft(): void
    {
        $this->declare('patches/core/htaccess.patch');
        $this->declare('patches/core/htaccess.conflict.patch', "stale\n");
        $plan = $this->plan(['status' => 'clean', 'patch' => "new diff\n"], 'patches/core/htaccess.patch');

        $this->writer($plan)->write($plan);

        self::assertFileDoesNotExist($this->root.'/patches/core/htaccess.conflict.patch');
    }

    public function testARefusedFileIsNamedAndTheRestOfTheRunStillWrites(): void
    {
        $this->declare('patches/core/htaccess.patch', "mine\n");
        $this->declare('patches/pathauto/translated.patch');
        $plan = $this->planFrom(['patches' => [
            $this->rerolledRow(['status' => 'clean', 'patch' => "a\n"], ['package' => 'drupal/core', 'source' => 'patches/core/htaccess.patch']),
            $this->rerolledRow(['status' => 'clean', 'patch' => "b\n"], ['package' => 'drupal/pathauto', 'source' => 'patches/pathauto/translated.patch']),
        ]]);
        $writer = new PatchFiles($this->root, new WorkingTree(new FakeGit(0, ' M patches/core/htaccess.patch', 'patches/core/htaccess.patch')), self::declaring($plan));

        $result = $writer->write($plan);

        self::assertCount(1, $result['refused']);
        self::assertSame('drupal/core', $result['refused'][0]['package']);
        self::assertSame(WorkingTree::UNCOMMITTED, $result['refused'][0]['reason']);
        self::assertSame("mine\n", \file_get_contents($this->root.'/patches/core/htaccess.patch'));
        self::assertSame(['patches/pathauto/translated.patch'], \array_map(static fn ($file): string => $file['path'], $result['written']));
    }

    public function testAFileAlreadyHoldingTheseBytesIsNotRefusedForBeingUntracked(): void
    {
        $this->declare('patches/core/htaccess.patch', "the same diff\n");
        $plan = $this->plan(['status' => 'clean', 'patch' => "the same diff\n"], 'patches/core/htaccess.patch');
        $writer = new PatchFiles($this->root, new WorkingTree(new FakeGit(0, '?? patches/core/htaccess.patch')), self::declaring($plan));

        $result = $writer->write($plan);

        self::assertSame([], $result['refused']);
        self::assertSame(['patches/core/htaccess.patch'], \array_map(static fn ($file): string => $file['path'], $result['written']));
    }

    public function testAPatchDeclaredAsAUrlIsNamedRatherThanWritten(): void
    {
        $plan = $this->plan(['status' => 'clean', 'patch' => "diff\n"], 'https://www.drupal.org/files/issues/a.patch');

        $result = $this->writer($plan)->write($plan);

        self::assertSame([], $result['written']);
        self::assertSame([], self::paths($this->root.'/*'));
        self::assertCount(1, $result['refused']);
        self::assertSame(PatchFiles::URL_DECLARED, $result['refused'][0]['reason']);
        self::assertSame('', $result['refused'][0]['lifts']);
    }

    public function testAUrlWhoseRerollIsUnavailablePointsAtItsMergeRequest(): void
    {
        $plan = $this->plan(['status' => 'unavailable', 'error' => 'the patch names no base blobs'], 'https://git.drupalcode.org/project/webform/-/merge_requests/12.diff');

        $result = $this->writer($plan)->write($plan);

        self::assertSame([], $result['written']);
        self::assertSame('the patch names no base blobs; the fix belongs upstream: https://git.drupalcode.org/project/webform/-/merge_requests/12', $result['refused'][0]['reason']);
    }

    public function testAMergedUrlPatchPointsNowhereBecauseTheFixIsAlreadyThere(): void
    {
        $plan = $this->planFrom(['patches' => [$this->rerolledRow(
            ['status' => 'clean', 'patch' => '', 'note' => 'the merge changes nothing: the patch is already in the release'],
            ['source' => 'https://git.drupalcode.org/project/webform/-/merge_requests/12.diff', 'version' => '6.3.2', 'verdict' => 'merged'],
        )]]);

        $result = $this->writer($plan)->write($plan);

        self::assertSame([], $result['written']);
        self::assertSame('the merge changes nothing: the patch is already in the release', $result['refused'][0]['reason']);
        self::assertTrue($result['refused'][0]['shipped']);
    }

    // The re-roll writes to files the site holds, so a URL declaration is
    // sent to the command that copies it into the site.
    public function testAUrlDeclarationIsSentToPin(): void
    {
        $plan = $this->plan(['status' => 'conflicts', 'patch' => "diff\n", 'conflicts' => [['file' => 'a.php', 'regions' => 1, 'hunks' => [['line' => 1, 'release' => "a\n", 'patch' => "b\n"]]]]], 'https://example.test/files/a.patch');

        $result = $this->writer($plan)->write($plan);

        self::assertSame([], $result['written']);
        self::assertSame(PatchFiles::URL_DECLARED, $result['refused'][0]['reason']);
        self::assertStringContainsString('composer drupatch:pin', $result['refused'][0]['reason']);
    }

    // The file the site copied says where its bytes came from. A re-roll
    // changes the bytes, so it says which release they were merged against
    // and hashes what it wrote.
    public function testARerollOverACopiedPatchKeepsItsProvenance(): void
    {
        $header = Header::line([
            'mr' => 'https://git.drupalcode.org/project/webform/-/merge_requests/940',
            'base' => 'aaa',
            'head' => 'bbb',
            'fetched' => '2026-09-01',
            'sha256' => Header::hash("old\n"),
        ]);
        $this->declare('patch/webform/mr940.diff', $header."old\n");
        $plan = $this->plan(['status' => 'clean', 'patch' => "new\n"], 'patch/webform/mr940.diff');

        $result = $this->writer($plan)->write($plan);

        self::assertSame('patch/webform/mr940.diff', $result['written'][0]['path']);
        $written = (string) \file_get_contents($this->root.'/patch/webform/mr940.diff');
        self::assertSame("new\n", Header::body($written));
        $read = Header::read($written);
        self::assertSame('https://git.drupalcode.org/project/webform/-/merge_requests/940', $read['mr']);
        self::assertSame('bbb', $read['head']);
        self::assertSame('6.3.2', $read['rerolled']);
        self::assertSame(Header::hash("new\n"), $read['sha256']);
    }

    // A patch the site wrote by hand has no header, and a re-roll of it
    // stays a plain diff.
    public function testARerollOverAPlainPatchAddsNoHeader(): void
    {
        $this->declare('patches/a.patch', "old\n");
        $plan = $this->plan(['status' => 'clean', 'patch' => "new\n"], 'patches/a.patch');

        $this->writer($plan)->write($plan);

        self::assertSame("new\n", \file_get_contents($this->root.'/patches/a.patch'));
    }

    public function testALocalPatchWhoseRerollMergedNothingStillGetsItsConflictFile(): void
    {
        $plan = $this->plan([
            'status' => 'conflicts',
            'conflicts' => [['file' => 'a.php', 'regions' => 1, 'hunks' => [['line' => 1, 'release' => "a\n", 'patch' => "b\n"]]]],
        ]);

        $result = $this->writer($plan)->write($plan);

        self::assertCount(1, $result['written']);
        self::assertStringEndsWith('.conflict.patch', $result['written'][0]['path']);
    }

    public function testASourceLeavingTheSiteRootIsRefused(): void
    {
        $plan = $this->plan(['status' => 'clean', 'patch' => "diff\n"], '../outside/evil.patch');

        $result = $this->writer($plan)->write($plan);

        self::assertSame([], $result['written']);
        self::assertCount(1, $result['refused']);
        self::assertStringContainsString('outside the site', $result['refused'][0]['reason']);
    }

    public function testASecondRunWritesTheSameBytesAndNoSecondFile(): void
    {
        $this->declare('patches/core/htaccess.patch');
        $plan = $this->plan(['status' => 'clean', 'patch' => "new diff\n"], 'patches/core/htaccess.patch');
        $writer = $this->writer($plan);

        $first = $writer->write($plan);
        $before = \filemtime($this->root.'/'.$first['written'][0]['path']);
        $second = $writer->write($plan);

        self::assertSame($first['written'][0]['path'], $second['written'][0]['path']);
        self::assertCount(1, self::paths($this->root.'/patches/core/*'));
        self::assertSame($before, \filemtime($this->root.'/'.$second['written'][0]['path']));
    }

    public function testAPatchWithNoRerollWritesNothing(): void
    {
        $plan = $this->planFrom(['patches' => [$this->row(['verdict' => 'applies'])]]);

        $result = $this->writer($plan)->write($plan);

        self::assertSame([], $result['written']);
        self::assertSame([], self::paths($this->root.'/*'));
    }

    public function testAnUnavailableRerollWritesNothingAndCarriesTheServersReason(): void
    {
        $plan = $this->plan(['status' => 'unavailable', 'error' => 'the patch names no base blobs']);

        $result = $this->writer($plan)->write($plan);

        self::assertSame([], $result['written']);
        self::assertCount(1, $result['refused']);
        self::assertSame('drupal/webform', $result['refused'][0]['package']);
        self::assertSame('the patch names no base blobs', $result['refused'][0]['reason']);
    }

    public function testARerollTheServerCouldNotBuildIsStillNamedWithoutAReason(): void
    {
        $plan = $this->plan(['status' => 'unavailable']);

        $result = $this->writer($plan)->write($plan);

        self::assertCount(1, $result['refused']);
        self::assertSame(PatchFiles::NO_REROLL, $result['refused'][0]['reason']);
    }

    public function testACleanRerollWithNoDiffWritesNothing(): void
    {
        $plan = $this->plan(['status' => 'clean', 'patch' => '']);

        self::assertSame([], $this->writer($plan)->write($plan)['written']);
    }

    // The directory is created before anything is written into it, and a
    // suite running as root would not notice an unusable mode.
    public function testTheDirectoryItCreatesIsUsableByItsOwner(): void
    {
        $plan = $this->plan([
            'status' => 'conflicts',
            'conflicts' => [['file' => 'a.php', 'regions' => 1, 'hunks' => [['line' => 1, 'release' => "a\n", 'patch' => "b\n"]]]],
        ], 'patches/webform/alter.patch');

        $result = $this->writer($plan)->write($plan);
        $dir = \dirname($this->root.\DIRECTORY_SEPARATOR.$result['written'][0]['path']);

        self::assertDirectoryExists($dir);
        self::assertSame(0o700, \fileperms($dir) & 0o700, 'the owner must be able to read, write and enter it');
    }

    public function testAConflictHunkFallsBackToThePatchLineWhenTheReleaseGivesNone(): void
    {
        $plan = $this->plan([
            'status' => 'conflicts',
            'conflicts' => [['file' => 'src/Form.php', 'regions' => 1, 'hunks' => [['line' => 42, 'release' => "a\n", 'patch' => "b\n"]]]],
        ]);

        $result = $this->writer($plan)->write($plan);

        self::assertStringContainsString('src/Form.php:42', (string) \file_get_contents($this->root.'/'.$result['written'][0]['path']));
    }

    public function testEachRegionSitsBetweenSentinelsNamingItsFileAndIndex(): void
    {
        $this->declare('patches/core/htaccess.patch');
        $plan = $this->plan([
            'status' => 'conflicts',
            'conflicts' => [[
                'file' => 'src/Form.php',
                'regions' => 2,
                'hunks' => [
                    ['line' => 42, 'release_line' => 40, 'release' => "new code\n", 'patch' => "patched code\n"],
                    ['line' => 90, 'release_line' => 88, 'release' => "more\n", 'patch' => "patched more\n"],
                ],
            ]],
        ], 'patches/core/htaccess.patch');

        $this->writer($plan)->write($plan);

        $body = (string) \file_get_contents($this->root.'/patches/core/htaccess.conflict.patch');
        self::assertStringContainsString("# drupatch region 0 src/Form.php\n<<<<<<< release src/Form.php:40", $body);
        self::assertStringContainsString(">>>>>>> patch\n# drupatch end 0 src/Form.php\n", $body);
        self::assertStringContainsString("# drupatch region 1 src/Form.php\n<<<<<<< release src/Form.php:88", $body);
        self::assertStringContainsString(">>>>>>> patch\n# drupatch end 1 src/Form.php\n", $body);
    }

    public function testTheRegionIndexRestartsPerFile(): void
    {
        $this->declare('patches/core/htaccess.patch');
        $plan = $this->plan([
            'status' => 'conflicts',
            'conflicts' => [
                ['file' => 'a.php', 'regions' => 1, 'hunks' => [['line' => 1, 'release' => "a\n", 'patch' => "b\n"]]],
                ['file' => 'b.php', 'regions' => 1, 'hunks' => [['line' => 1, 'release' => "c\n", 'patch' => "d\n"]]],
            ],
        ], 'patches/core/htaccess.patch');

        $this->writer($plan)->write($plan);

        $body = (string) \file_get_contents($this->root.'/patches/core/htaccess.conflict.patch');
        self::assertStringContainsString('# drupatch region 0 a.php', $body);
        self::assertStringContainsString('# drupatch region 0 b.php', $body);
    }

    public function testTheConflictFileSaysToKeepTheSentinels(): void
    {
        $this->declare('patches/core/htaccess.patch');
        $plan = $this->plan([
            'status' => 'conflicts',
            'conflicts' => [['file' => 'a.php', 'regions' => 1, 'hunks' => [['line' => 1, 'release' => "a\n", 'patch' => "b\n"]]]],
        ], 'patches/core/htaccess.patch');

        $this->writer($plan)->write($plan);

        $body = (string) \file_get_contents($this->root.'/patches/core/htaccess.conflict.patch');
        self::assertStringContainsString('keep the region and end lines', $body);
    }

    public function testTheConflictFileNamesTheCommandThatFinishesIt(): void
    {
        $this->declare('patches/core/htaccess.patch');
        $plan = $this->plan([
            'status' => 'conflicts',
            'conflicts' => [['file' => 'a.php', 'regions' => 1, 'hunks' => [['line' => 1, 'release' => "a\n", 'patch' => "b\n"]]]],
        ], 'patches/core/htaccess.patch');

        $this->writer($plan)->write($plan);

        $body = (string) \file_get_contents($this->root.'/patches/core/htaccess.conflict.patch');
        self::assertStringContainsString('then run composer drupatch:reroll', $body);
    }

    public function testTheWriterOutputParsesBackAsNothingDecided(): void
    {
        $this->declare('patches/core/htaccess.patch');
        $plan = $this->plan([
            'status' => 'conflicts',
            'patch' => "part\n",
            'conflicts' => [[
                'file' => 'src/Form.php',
                'regions' => 2,
                'hunks' => [
                    ['line' => 42, 'release_line' => 40, 'release' => "new code\n", 'patch' => "patched code\n"],
                    ['line' => 90, 'release_line' => 88, 'release' => "more\n", 'patch' => "patched more\n"],
                ],
            ]],
        ], 'patches/core/htaccess.patch');

        $this->writer($plan)->write($plan);

        $body = (string) \file_get_contents($this->root.'/patches/core/htaccess.conflict.patch');
        self::assertSame([], Decisions::read($body, 'patches/core/htaccess.conflict.patch'));
    }

    public function testCarriesTheRegionsTheMergeDecidedIntoTheWrittenFile(): void
    {
        $plan = $this->planFrom(['patches' => [$this->rerolledRow([
            'status' => 'clean',
            'patch' => "diff --git a/a b/a\n",
            'unioned' => [['file' => 'src/Form.php', 'line' => 12]],
        ], ['source' => 'patches/a.patch'])]]);

        $written = $this->writer($plan)->write($plan)['written'];

        self::assertSame([['file' => 'src/Form.php', 'line' => 12]], $written[0]['unioned']);
    }

    public function testARowTheSiteNeverDeclaredIsRefused(): void
    {
        $plan = $this->planFrom(['patches' => [$this->rerolledRow(
            ['status' => 'clean', 'patch' => "new diff\n", 'verified' => true],
            ['source' => 'web/sites/default/settings.php']
        )]]);
        $writer = new PatchFiles($this->root, null, [
            ['package' => 'drupal/other', 'title' => 'Something else', 'source' => 'patches/other.patch'],
        ]);

        $result = $writer->write($plan);

        self::assertSame([], $result['written']);
        self::assertSame(PatchFiles::NOT_DECLARED, $result['refused'][0]['reason']);
        self::assertFileDoesNotExist($this->root.'/web/sites/default/settings.php');
    }

    public function testTheDeclaredSourceDecidesTheWriteTarget(): void
    {
        $plan = $this->planFrom(['patches' => [$this->rerolledRow(
            ['status' => 'clean', 'patch' => "new diff\n", 'verified' => true],
            ['source' => 'web/sites/default/settings.php']
        )]]);
        $writer = new PatchFiles($this->root, null, [
            ['package' => 'drupal/webform', 'title' => 'Fix the alter hook', 'source' => 'patches/webform/alter.patch'],
        ]);

        $result = $writer->write($plan);

        self::assertSame('patches/webform/alter.patch', $result['written'][0]['path']);
        self::assertFileDoesNotExist($this->root.'/web/sites/default/settings.php');
    }

    // The service says why it built no re-roll. Its words beat the
    // plugin's fallback, which claimed it had built nothing at all.
    public function testARefusalTakesTheServicesOwnWords(): void
    {
        $cases = [
            ['reroll' => ['status' => 'unavailable', 'error' => 'no release takes this patch'], 'want' => 'no release takes this patch'],
            ['reroll' => ['status' => 'clean', 'verified' => true, 'note' => 'the merge changes nothing: the patch is already in the release'], 'want' => 'the merge changes nothing: the patch is already in the release'],
            ['reroll' => ['status' => 'unavailable'], 'want' => PatchFiles::NO_REROLL],
        ];

        foreach ($cases as $case) {
            $plan = $this->plan($case['reroll']);
            $result = (new PatchFiles($this->root, null, []))->write($plan);

            self::assertCount(1, $result['refused'], 'a row with no patch to write is refused');
            self::assertSame($case['want'], $result['refused'][0]['reason']);
        }
    }
}

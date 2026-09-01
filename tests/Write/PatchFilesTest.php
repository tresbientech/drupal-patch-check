<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Write;

use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Plan\Plan;
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

    private function adopter(Plan $plan): PatchFiles
    {
        return new PatchFiles($this->root, null, self::declaring($plan), true);
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
        self::assertSame('--fix', $result['refused'][0]['lifts']);
    }

    public function testAnAdoptedUrlPatchLandsUnderItsProject(): void
    {
        $plan = $this->plan(['status' => 'clean', 'patch' => "diff\n"], 'https://www.drupal.org/files/issues/2022-02-25/pathauto-3131794-15.patch');

        $result = $this->adopter($plan)->write($plan);

        self::assertSame('patches/webform/pathauto-3131794-15.patch', $result['written'][0]['path']);
        self::assertSame([], $result['refused']);
    }

    public function testAnAdoptedUrlDropsTheQueryString(): void
    {
        $plan = $this->plan(['status' => 'clean', 'patch' => "diff\n"], 'https://example.test/files/a.patch?id=7&raw=1');

        self::assertSame('patches/webform/a.patch', $this->adopter($plan)->write($plan)['written'][0]['path']);
    }

    public function testAnAdoptedUrlWithNoProjectUsesThePackageName(): void
    {
        $plan = $this->planFrom(['patches' => [$this->rerolledRow(
            ['status' => 'clean', 'patch' => "diff\n"],
            ['package' => 'drupal/menu_item_extras', 'project' => '', 'source' => 'https://example.test/a.patch']
        )]]);

        self::assertSame('patches/menu_item_extras/a.patch', $this->adopter($plan)->write($plan)['written'][0]['path']);
    }

    public function testAnAdoptedUrlWhoseRerollConflictsGetsAConflictFileBesideIt(): void
    {
        $plan = $this->plan([
            'status' => 'conflicts',
            'conflicts' => [['file' => 'a.php', 'regions' => 1, 'hunks' => [['line' => 1, 'release' => "a\n", 'patch' => "b\n"]]]],
        ], 'https://example.test/files/a.patch');

        $result = $this->adopter($plan)->write($plan);

        self::assertSame('patches/webform/a.conflict.patch', $result['written'][0]['path']);
        self::assertFalse('clean' === $result['written'][0]['status']);
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
        self::assertStringContainsString('then run composer drupal-patch-check --resolve', $body);
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

    public function testAdoptResolvesFromTheDeclaredUrl(): void
    {
        $plan = $this->planFrom(['patches' => [$this->rerolledRow(
            ['status' => 'clean', 'patch' => "diff\n"],
            ['source' => 'https://evil.test/steal.patch']
        )]]);
        $writer = new PatchFiles($this->root, null, [
            ['package' => 'drupal/webform', 'title' => 'Fix the alter hook', 'source' => 'https://www.drupal.org/files/real.patch'],
        ], true);

        $result = $writer->write($plan);

        self::assertStringEndsWith('real.patch', $result['written'][0]['path']);
        self::assertSame([], self::paths($this->root.'/patches/webform/steal.patch'));
    }
}

<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Tests\Write;

use PHPUnit\Framework\TestCase;
use Tresbien\Drupatch\Plan\Plan;
use Tresbien\Drupatch\Tests\PlanFactory;
use Tresbien\Drupatch\Write\PatchFiles;

final class PatchFilesTest extends TestCase
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
        foreach (self::paths($this->root.'/*/*') as $file) {
            @\unlink($file);
        }
        foreach (self::paths($this->root.'/*') as $dir) {
            @\rmdir($dir);
        }
        @\rmdir($this->root);
    }

    /**
     * @return list<string>
     */
    private static function paths(string $pattern): array
    {
        $found = \glob($pattern);

        return false === $found ? [] : $found;
    }

    /**
     * @param array<string, mixed> $reroll
     */
    private function plan(array $reroll, string $source = 'patchs/webform.patch'): Plan
    {
        return $this->planFrom(['patches' => [$this->rerolledRow($reroll, ['source' => $source, 'version' => '6.3.2'])]]);
    }

    public function testACleanRerollBecomesAUsablePatchFile(): void
    {
        $plan = $this->plan(['status' => 'clean', 'patch' => "diff --git a/x b/x\n", 'verified' => true]);

        $written = PatchFiles::forPlan($this->root, $plan)->write($plan);

        self::assertCount(1, $written);
        self::assertTrue($written[0]->isUsable());
        self::assertTrue($written[0]->verified);
        self::assertStringEndsWith('.patch', $written[0]->path);
        self::assertStringNotContainsString('.conflict.', $written[0]->path);
        self::assertSame("diff --git a/x b/x\n", \file_get_contents($this->root.'/'.$written[0]->path));
    }

    public function testAConflictedRerollIsNamedSoNoPatchManagerReadsIt(): void
    {
        $plan = $this->plan([
            'status' => 'conflicts',
            'patch' => "diff --git a/x b/x\n",
            'conflicts' => [[
                'file' => 'src/Form.php',
                'regions' => 1,
                'hunks' => [['line' => 42, 'release_line' => 40, 'release' => "new code\n", 'patch' => "patched code\n"]],
            ]],
        ]);

        $written = PatchFiles::forPlan($this->root, $plan)->write($plan);

        self::assertFalse($written[0]->isUsable());
        self::assertStringEndsWith('.conflict.patch', $written[0]->path);
        $body = (string) \file_get_contents($this->root.'/'.$written[0]->path);
        self::assertStringContainsString('<<<<<<< release src/Form.php:40', $body);
        self::assertStringContainsString('patched code', $body);
        self::assertStringContainsString('>>>>>>> patch', $body);
    }

    public function testAConflictHunkFallsBackToThePatchLineWhenTheReleaseGivesNone(): void
    {
        $plan = $this->plan([
            'status' => 'conflicts',
            'conflicts' => [['file' => 'src/Form.php', 'regions' => 1, 'hunks' => [['line' => 42, 'release' => "a\n", 'patch' => "b\n"]]]],
        ]);

        $written = PatchFiles::forPlan($this->root, $plan)->write($plan);

        self::assertStringContainsString('src/Form.php:42', (string) \file_get_contents($this->root.'/'.$written[0]->path));
    }

    public function testWritesBesideThePatchesTheSiteAlreadyKeeps(): void
    {
        $plan = $this->plan(['status' => 'clean', 'patch' => "diff\n"], 'patchs/webform.patch');

        self::assertStringStartsWith('patchs/', PatchFiles::forPlan($this->root, $plan)->write($plan)[0]->path);
    }

    public function testFallsBackToPatchesWhenEveryPatchIsAURL(): void
    {
        $plan = $this->plan(['status' => 'clean', 'patch' => "diff\n"], 'https://www.drupal.org/files/issues/a.patch');

        self::assertStringStartsWith('patches/', PatchFiles::forPlan($this->root, $plan)->write($plan)[0]->path);
    }

    public function testASecondRunWritesTheSameBytesAndNoSecondFile(): void
    {
        $plan = $this->plan(['status' => 'clean', 'patch' => "diff --git a/x b/x\n"]);
        $writer = PatchFiles::forPlan($this->root, $plan);

        $first = $writer->write($plan);
        $before = \filemtime($this->root.'/'.$first[0]->path);
        $second = $writer->write($plan);

        self::assertSame($first[0]->path, $second[0]->path);
        self::assertCount(1, self::paths($this->root.'/patchs/*'));
        self::assertSame($before, \filemtime($this->root.'/'.$second[0]->path));
    }

    public function testAPatchWithNoRerollWritesNothing(): void
    {
        $plan = $this->planFrom(['patches' => [$this->row(['verdict' => 'still-needed'])]]);

        self::assertSame([], PatchFiles::forPlan($this->root, $plan)->write($plan));
        self::assertSame([], self::paths($this->root.'/*'));
    }

    public function testAnUnavailableRerollWritesNothing(): void
    {
        $plan = $this->plan(['status' => 'unavailable', 'error' => 'the patch names no base blobs']);

        self::assertSame([], PatchFiles::forPlan($this->root, $plan)->write($plan));
    }

    public function testACleanRerollWithNoDiffWritesNothing(): void
    {
        $plan = $this->plan(['status' => 'clean', 'patch' => '']);

        self::assertSame([], PatchFiles::forPlan($this->root, $plan)->write($plan));
    }
}

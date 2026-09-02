<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Write;

use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Plan\Plan;
use TresBienTech\Drupatch\Tests\PlanFactory;
use TresBienTech\Drupatch\Write\ConfigRewriter;

final class ConfigRewriterTest extends TestCase
{
    use PlanFactory;

    private function plan(): Plan
    {
        return $this->planFrom(['patches' => [
            $this->row(['package' => 'drupal/core', 'title' => 'Menu cache', 'source' => 'https://www.drupal.org/files/issues/c.patch', 'verdict' => 'merged']),
            $this->row(['title' => 'Fix a', 'source' => 'patches/a.patch', 'verdict' => 'conflicts']),
            $this->row(['title' => 'Fix b', 'source' => 'patches/b.patch', 'verdict' => 'applies']),
            $this->row(['package' => 'drupal/token', 'title' => 'Fix d', 'source' => 'patches/d.patch', 'verdict' => 'conflicts']),
        ]]);
    }

    /**
     * @return list<array{path: string, status: string, package: string, title: string, verified: bool, unioned: list<array{file: string, line: int}>, regions: int}>
     */
    private function written(): array
    {
        return [
            $this->writtenFile('patches/webform-fix-a-1234abcd.patch'),
            $this->writtenFile('patches/token-fix-d-5678efgh.conflict.patch', 'conflicts', 'drupal/token', 'Fix d', false),
        ];
    }

    public function testDropsWhatShippedAndRepointsWhatWasRerolled(): void
    {
        $changes = ConfigRewriter::changes($this->plan(), $this->written());

        self::assertCount(2, $changes);
        self::assertTrue('dropped' === $changes[0]['action']);
        self::assertSame('drupal/core', $changes[0]['package']);
        self::assertSame('repointed', $changes[1]['action']);
        self::assertSame('patches/webform-fix-a-1234abcd.patch', $changes[1]['path']);
    }

    public function testNeverNamesAConflictFile(): void
    {
        foreach (ConfigRewriter::changes($this->plan(), $this->written()) as $change) {
            self::assertStringNotContainsString('.conflict.', $change['path']);
            self::assertNotSame('drupal/token', $change['package']);
        }
    }

    public function testLeavesAPatchThatStillAppliesAlone(): void
    {
        $patches = ['drupal/webform' => ['Fix a' => 'patches/a.patch', 'Fix b' => 'patches/b.patch']];

        $applied = ConfigRewriter::apply($patches, ConfigRewriter::changes($this->plan(), $this->written()));

        self::assertSame([
            'drupal/webform' => ['Fix a' => 'patches/webform-fix-a-1234abcd.patch', 'Fix b' => 'patches/b.patch'],
        ], $applied);
    }

    public function testDropsAPackageWhoseLastPatchShipped(): void
    {
        $patches = ['drupal/core' => ['Menu cache' => 'https://www.drupal.org/files/issues/c.patch']];

        self::assertSame([], ConfigRewriter::apply($patches, ConfigRewriter::changes($this->plan(), $this->written())));
    }

    public function testRepointsAnEntryWrittenAsAnObject(): void
    {
        $patches = ['drupal/webform' => ['Fix a' => ['url' => 'patches/a.patch', 'depth' => 2]]];

        $applied = ConfigRewriter::apply($patches, ConfigRewriter::changes($this->plan(), $this->written()));

        self::assertSame([
            'drupal/webform' => ['Fix a' => ['url' => 'patches/webform-fix-a-1234abcd.patch', 'depth' => 2]],
        ], $applied, 'the entry points at the re-roll and keeps everything else it said');
    }

    public function testDropsAnEntryWrittenAsAListObject(): void
    {
        $patches = ['drupal/core' => [
            ['description' => 'Menu cache', 'url' => 'https://www.drupal.org/files/issues/c.patch'],
            ['description' => 'Another fix', 'url' => 'https://www.drupal.org/files/issues/d.patch'],
        ]];

        $applied = ConfigRewriter::apply($patches, ConfigRewriter::changes($this->plan(), $this->written()));

        self::assertSame([
            'drupal/core' => [['description' => 'Another fix', 'url' => 'https://www.drupal.org/files/issues/d.patch']],
        ], $applied, 'the merged entry went, the other stayed, and a list stayed a list');
    }

    public function testRepointsAnEntryWrittenAsAListObject(): void
    {
        $patches = ['drupal/webform' => [['description' => 'Fix a', 'url' => 'patches/a.patch']]];

        $applied = ConfigRewriter::apply($patches, ConfigRewriter::changes($this->plan(), $this->written()));

        self::assertSame([
            'drupal/webform' => [['description' => 'Fix a', 'url' => 'patches/webform-fix-a-1234abcd.patch']],
        ], $applied);
    }

    public function testLeavesEveryOtherKeyAndTheIndentationAlone(): void
    {
        $text = <<<'JSON'
            {
              "name": "test/site",
              "require": {
                "drupal/core": "^11.4"
              },
              "extra": {
                "patches": {
                  "drupal/core": {
                    "Menu cache": "https://www.drupal.org/files/issues/c.patch"
                  },
                  "drupal/webform": {
                    "Fix a": "patches/a.patch"
                  }
                },
                "enable-patching": true
              }
            }
            JSON;
        $applied = ConfigRewriter::apply(
            ['drupal/core' => ['Menu cache' => 'https://www.drupal.org/files/issues/c.patch'], 'drupal/webform' => ['Fix a' => 'patches/a.patch']],
            ConfigRewriter::changes($this->plan(), $this->written())
        );

        $updated = ConfigRewriter::intoComposerJson($text, $applied);

        self::assertSame([
            'name' => 'test/site',
            'require' => ['drupal/core' => '^11.4'],
            'extra' => [
                'patches' => ['drupal/webform' => ['Fix a' => 'patches/webform-fix-a-1234abcd.patch']],
                'enable-patching' => true,
            ],
        ], \json_decode($updated, true), 'only the settled entries changed, and every other key stayed');
        self::assertStringContainsString('  "name": "test/site"', $updated, 'the file keeps its own indentation');
    }

    public function testRewritesAnExternalPatchesFileInItsOwnShape(): void
    {
        $text = (string) \json_encode(['patches' => ['drupal/webform' => ['Fix a' => 'patches/a.patch']]], \JSON_PRETTY_PRINT);
        $applied = ConfigRewriter::apply(['drupal/webform' => ['Fix a' => 'patches/a.patch']], ConfigRewriter::changes($this->plan(), $this->written()));

        $updated = \json_decode(ConfigRewriter::intoPatchesFile($text, $applied), true);

        self::assertSame(['patches' => ['drupal/webform' => ['Fix a' => 'patches/webform-fix-a-1234abcd.patch']]], $updated);
    }

    public function testRewritesABarePatchesFileWithoutWrappingIt(): void
    {
        $text = (string) \json_encode(['drupal/webform' => ['Fix a' => 'patches/a.patch']], \JSON_PRETTY_PRINT);
        $applied = ConfigRewriter::apply(['drupal/webform' => ['Fix a' => 'patches/a.patch']], ConfigRewriter::changes($this->plan(), $this->written()));

        $updated = \json_decode(ConfigRewriter::intoPatchesFile($text, $applied), true);

        self::assertSame(['drupal/webform' => ['Fix a' => 'patches/webform-fix-a-1234abcd.patch']], $updated, 'a bare file is not wrapped in a patches key');
    }

    public function testAPlanWithNothingSettledChangesNothing(): void
    {
        $plan = $this->planFrom(['patches' => [$this->row(['verdict' => 'applies'])]]);

        self::assertSame([], ConfigRewriter::changes($plan, []));
    }

    public function testARerollThatWasNotWrittenIsNotRepointed(): void
    {
        self::assertSame([], \array_filter(
            ConfigRewriter::changes($this->plan(), []),
            static fn (array $change): bool => 'repointed' === $change['action']
        ));
    }

    public function testARerollWrittenOverItsOwnDeclarationIsNotRepointed(): void
    {
        $plan = $this->planFrom(['patches' => [
            $this->row(['title' => 'Fix a', 'source' => 'patches/a.patch', 'verdict' => 'conflicts']),
        ]]);
        $written = [$this->writtenFile('patches/a.patch', 'clean', 'drupal/webform', 'Fix a')];

        self::assertSame([], ConfigRewriter::changes($plan, $written));
    }

    public function testAnAdoptedUrlIsRepointedAtTheFileItWasWrittenTo(): void
    {
        $plan = $this->planFrom(['patches' => [
            $this->row(['title' => 'Fix a', 'source' => 'https://example.test/a.patch', 'verdict' => 'conflicts']),
        ]]);
        $written = [$this->writtenFile('patches/webform/a.patch', 'clean', 'drupal/webform', 'Fix a')];

        $changes = ConfigRewriter::changes($plan, $written);

        self::assertCount(1, $changes);
        self::assertSame('repointed', $changes[0]['action']);
        self::assertSame('patches/webform/a.patch', $changes[0]['path']);
    }
}

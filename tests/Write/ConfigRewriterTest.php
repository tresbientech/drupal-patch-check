<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Write;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use TresBienTech\Drupatch\Plan\Plan;
use TresBienTech\Drupatch\Tests\PlanFactory;
use TresBienTech\Drupatch\Write\ConfigRewriter;

class ConfigRewriterTest extends TestCase
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
     * @return list<array{path: string, provenance: array<string, string>, status: string, package: string, title: string, verified: bool, unioned: list<array{file: string, line: int}>, regions: int}>
     */
    private function written(): array
    {
        return [
            $this->writtenFile('patches/webform-fix-a-1234abcd.patch'),
            $this->writtenFile('patches/token-fix-d-5678efgh.conflict.patch', 'conflicts', 'drupal/token', 'Fix d', false),
        ];
    }

    /**
     * The plan's changes to the packages one document declares, which is what a rewrite hands that document.
     *
     * @param array<string, mixed> $patches
     *
     * @return list<array{action: string, package: string, title: string, path: string, provenance: array<string, string>}>
     */
    private function changesOn(array $patches): array
    {
        return \array_values(\array_filter(
            ConfigRewriter::changes($this->plan(), $this->written()),
            static fn (array $change): bool => isset($patches[$change['package']]),
        ));
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

        $applied = ConfigRewriter::apply($patches, $this->changesOn($patches));

        self::assertSame([
            'drupal/webform' => ['Fix a' => 'patches/webform-fix-a-1234abcd.patch', 'Fix b' => 'patches/b.patch'],
        ], $applied);
    }

    public function testDropsAPackageWhoseLastPatchShipped(): void
    {
        $patches = ['drupal/core' => ['Menu cache' => 'https://www.drupal.org/files/issues/c.patch']];

        self::assertSame([], ConfigRewriter::apply($patches, $this->changesOn($patches)));
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

    public function testRepointsAndDropsInTheExpandedForm(): void
    {
        $patches = ['drupal/webform' => [
            ['description' => 'Fix a', 'url' => 'patches/a.patch', 'sha256' => 'abc'],
            ['description' => 'Fix b', 'url' => 'patches/b.patch'],
        ], 'drupal/core' => [
            ['description' => 'Menu cache', 'url' => 'https://www.drupal.org/files/issues/c.patch'],
        ]];

        $applied = ConfigRewriter::apply($patches, ConfigRewriter::changes($this->plan(), $this->written()));

        self::assertSame(['drupal/webform' => [
            ['description' => 'Fix a', 'url' => 'patches/webform-fix-a-1234abcd.patch', 'sha256' => 'abc'],
            ['description' => 'Fix b', 'url' => 'patches/b.patch'],
        ]], $applied, 'the entry keeps every key it had, and the dropped package is gone');
    }

    // A dropped entry must not turn the list into a JSON object.
    public function testTheExpandedFormStaysAListWhenAnEntryIsDropped(): void
    {
        $patches = ['drupal/core' => [
            ['description' => 'Kept', 'url' => 'patches/kept.patch'],
            ['description' => 'Menu cache', 'url' => 'https://www.drupal.org/files/issues/c.patch'],
        ]];

        $applied = ConfigRewriter::apply($patches, $this->changesOn($patches));

        self::assertSame([0], \array_keys($applied['drupal/core']));
    }

    public function testGroupsEachChangeUnderTheFileThatHeldItsEntry(): void
    {
        $declarations = [
            ['package' => 'drupal/core', 'title' => 'Menu cache', 'source' => 'c.patch', 'file' => 'a.json', 'shape' => 'compact'],
            ['package' => 'drupal/webform', 'title' => 'Fix a', 'source' => 'patches/a.patch', 'file' => 'composer.json', 'shape' => 'compact'],
        ];

        $grouped = ConfigRewriter::byFile(ConfigRewriter::changes($this->plan(), $this->written()), $declarations);

        self::assertSame(['composer.json', 'a.json'], \array_keys($grouped), 'composer.json is written first, whatever the patches file is called');
        self::assertSame(['Fix a'], \array_column($grouped['composer.json'], 'title'));
        self::assertSame(['Menu cache'], \array_column($grouped['a.json'], 'title'));
    }

    public function testAChangeNoDeclarationNamesBelongsToComposerJson(): void
    {
        $grouped = ConfigRewriter::byFile(ConfigRewriter::changes($this->plan(), $this->written()), []);

        self::assertSame(['composer.json'], \array_keys($grouped));
    }

    public function testWritesAPatchesFileKeepingItsOtherKeysAndIndentation(): void
    {
        $text = <<<'JSON'
            {
              "patches": {
                "drupal/core": {
                  "Menu cache": "https://www.drupal.org/files/issues/c.patch"
                },
                "drupal/webform": {
                  "Fix a": "patches/a.patch"
                }
              },
              "note": "ours"
            }
            JSON;
        $applied = ConfigRewriter::apply(
            ['drupal/core' => ['Menu cache' => 'https://www.drupal.org/files/issues/c.patch'], 'drupal/webform' => ['Fix a' => 'patches/a.patch']],
            ConfigRewriter::changes($this->plan(), $this->written())
        );

        $updated = ConfigRewriter::intoPatchesFile($text, $applied);

        self::assertSame([
            'patches' => ['drupal/webform' => ['Fix a' => 'patches/webform-fix-a-1234abcd.patch']],
            'note' => 'ours',
        ], \json_decode($updated, true));
        self::assertStringContainsString('  "note": "ours"', $updated);
    }

    // 2.x reads a package's shape from its first entry, so an entry gaining a
    // record takes the whole package to the expanded shape with it.
    public function testARecordMovesTheWholePackageToTheExpandedShape(): void
    {
        $record = ['mr' => 'https://git.drupalcode.org/project/webform/-/merge_requests/940', 'head' => 'bbb'];
        $patches = ['drupal/webform' => ['Fix a' => 'patches/a.patch', 'Fix b' => 'patches/b.patch']];
        $changes = [['action' => 'repointed', 'package' => 'drupal/webform', 'title' => 'Fix a', 'path' => 'patch/webform/mr940.diff', 'provenance' => $record]];

        self::assertSame(['drupal/webform' => [
            ['description' => 'Fix a', 'url' => 'patch/webform/mr940.diff', 'extra' => ['drupatch' => $record]],
            ['description' => 'Fix b', 'url' => 'patches/b.patch'],
        ]], ConfigRewriter::apply($patches, $changes));
    }

    public function testAnEntryAlreadyExpandedKeepsItsOtherKeysBesideTheRecord(): void
    {
        $record = ['mr' => 'https://git.drupalcode.org/project/webform/-/merge_requests/940'];
        $patches = ['drupal/webform' => [['description' => 'Fix a', 'url' => 'patches/a.patch', 'depth' => 2]]];
        $changes = [['action' => 'repointed', 'package' => 'drupal/webform', 'title' => 'Fix a', 'path' => 'patch/webform/mr940.diff', 'provenance' => $record]];

        self::assertSame(['drupal/webform' => [
            ['description' => 'Fix a', 'url' => 'patch/webform/mr940.diff', 'depth' => 2, 'extra' => ['drupatch' => $record]],
        ]], ConfigRewriter::apply($patches, $changes));
    }

    // A run that records nothing leaves the site in the shape it wrote.
    public function testAChangeWithNoRecordKeepsTheCompactShape(): void
    {
        $patches = ['drupal/webform' => ['Fix a' => 'patches/a.patch']];
        $changes = [['action' => 'repointed', 'package' => 'drupal/webform', 'title' => 'Fix a', 'path' => 'patch/webform/mr940.diff', 'provenance' => []]];

        self::assertSame(['drupal/webform' => ['Fix a' => 'patch/webform/mr940.diff']], ConfigRewriter::apply($patches, $changes));
    }

    /**
     * One declaration an add run writes, carrying where its bytes came from.
     *
     * @return list<array{action: string, package: string, title: string, path: string, provenance: array<string, string>}>
     */
    private static function adding(string $package = 'drupal/webform', string $title = '3521733: bfcache'): array
    {
        return [[
            'action' => ConfigRewriter::ADDED,
            'package' => $package,
            'title' => $title,
            'path' => 'patch/webform/mr940.diff',
            'provenance' => ['mr' => 'https://git.drupalcode.org/project/webform/-/merge_requests/940', 'head' => 'bbb'],
        ]];
    }

    public function testAnAddedEntryLandsOnAPackageTheSiteAlreadyPatches(): void
    {
        $patches = ['drupal/webform' => ['Fix a' => 'patches/a.patch']];

        self::assertSame(['drupal/webform' => [
            ['description' => 'Fix a', 'url' => 'patches/a.patch'],
            ['description' => '3521733: bfcache', 'url' => 'patch/webform/mr940.diff', 'extra' => ['drupatch' => ['mr' => 'https://git.drupalcode.org/project/webform/-/merge_requests/940', 'head' => 'bbb']]],
        ]], ConfigRewriter::apply($patches, self::adding()));
    }

    public function testAnAddedEntryOpensAPackageTheSiteDidNotPatch(): void
    {
        $patches = ['drupal/core' => ['Menu cache' => 'patches/c.patch']];

        $applied = ConfigRewriter::apply($patches, self::adding());

        self::assertSame(['drupal/core' => ['Menu cache' => 'patches/c.patch'], 'drupal/webform' => [
            ['description' => '3521733: bfcache', 'url' => 'patch/webform/mr940.diff', 'extra' => ['drupatch' => ['mr' => 'https://git.drupalcode.org/project/webform/-/merge_requests/940', 'head' => 'bbb']]],
        ]], $applied);
    }

    public function testAnAddedEntryOpensThePatchesOfASiteThatDeclaredNone(): void
    {
        self::assertSame(['drupal/webform' => [
            ['description' => '3521733: bfcache', 'url' => 'patch/webform/mr940.diff', 'extra' => ['drupatch' => ['mr' => 'https://git.drupalcode.org/project/webform/-/merge_requests/940', 'head' => 'bbb']]],
        ]], ConfigRewriter::apply([], self::adding()));
    }

    // The patch manager applies a package's patches in declaration order, so
    // a new one is judged on top of the ones already there.
    public function testAnAddedEntryGoesLast(): void
    {
        $patches = ['drupal/webform' => [
            ['description' => 'Fix a', 'url' => 'patches/a.patch'],
            ['description' => 'Fix b', 'url' => 'patches/b.patch'],
        ]];

        $applied = ConfigRewriter::apply($patches, self::adding());

        self::assertSame(['Fix a', 'Fix b', '3521733: bfcache'], \array_column($applied['drupal/webform'], 'description'));
    }

    public function testAnAddedEntryGroupsUnderTheFileItsDeclarationNames(): void
    {
        $declarations = [['package' => 'drupal/webform', 'title' => '3521733: bfcache', 'source' => 'patch/webform/mr940.diff', 'file' => 'patches.json', 'shape' => 'expanded']];

        self::assertSame(['patches.json'], \array_keys(ConfigRewriter::byFile(self::adding(), $declarations)));
    }

    // A change comes from a declaration the run read, so one that matches no
    // entry is a pairing defect, and a silent skip would hide it.
    public function testARepointNamingNoEntryThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no declaration of drupal/webform is titled Gone, so nothing was rewritten');

        ConfigRewriter::apply(['drupal/webform' => ['Fix' => 'patches/fix.patch']], [
            ['action' => ConfigRewriter::REPOINTED, 'package' => 'drupal/webform', 'title' => 'Gone', 'path' => 'patch/gone.diff', 'provenance' => []],
        ]);
    }

    public function testADropNamingNoEntryThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no declaration of drupal/token is titled Fix, so nothing was rewritten');

        ConfigRewriter::apply(['drupal/webform' => ['Fix' => 'patches/fix.patch']], [
            ['action' => ConfigRewriter::DROPPED, 'package' => 'drupal/token', 'title' => 'Fix', 'path' => '', 'provenance' => []],
        ]);
    }
}

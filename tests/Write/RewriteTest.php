<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Write;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TresBienTech\Drupatch\Manager;
use TresBienTech\Drupatch\Plan\Plan;
use TresBienTech\Drupatch\Read\Declaration;
use TresBienTech\Drupatch\Read\PatchConfig;
use TresBienTech\Drupatch\Tests\PlanFactory;
use TresBienTech\Drupatch\Tests\Scratch;
use TresBienTech\Drupatch\Write\Declarations;

/**
 * What a rewrite decides from a plan, and what each document declares once it is written.
 */
#[CoversClass(Declarations::class)]
#[CoversClass(Declaration::class)]
class RewriteTest extends TestCase
{
    use PlanFactory;

    private string $root;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir().'/drupatch-rewrite-'.\bin2hex(\random_bytes(6));
        \mkdir($this->root);
    }

    protected function tearDown(): void
    {
        Scratch::remove($this->root);
    }

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
            Declarations::changes($this->plan(), $this->written()),
            static fn (array $change): bool => isset($patches[$change['package']]),
        ));
    }

    /**
     * Rewrites the site's documents the way a run does, over the declarations read from them and any an add run writes.
     *
     * @param list<array{action: string, package: string, title: string, path: string, provenance: array<string, string>}> $changes
     * @param array<string, mixed>                                                                                         $extra   what composer.json's extra says about where declarations live
     * @param list<Declaration>                                                                                            $adding
     *
     * @return list<string> the documents written
     */
    private function write(array $changes, array $extra, array $adding = []): array
    {
        $declared = PatchConfig::declared($extra, $this->root, Manager::ofVersion('2.0.0'));

        return Declarations::checked($this->root, [], null)->write($changes, [...$declared, ...$adding]);
    }

    /**
     * Declares these patches in composer.json, rewrites them, and returns what composer.json declares after.
     *
     * @param array<string, mixed>                                                                                         $patches
     * @param list<array{action: string, package: string, title: string, path: string, provenance: array<string, string>}> $changes
     * @param list<Declaration>                                                                                            $adding
     *
     * @return array<mixed>
     */
    private function rewritten(array $patches, array $changes, array $adding = []): array
    {
        \file_put_contents($this->root.'/composer.json', (string) \json_encode(['extra' => ['patches' => $patches]], \JSON_PRETTY_PRINT));
        $this->write($changes, ['patches' => $patches], $adding);

        return (array) ($this->read('composer.json')['extra']['patches'] ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $file): array
    {
        return (array) \json_decode((string) \file_get_contents($this->root.'/'.$file), true);
    }

    public function testDropsWhatShippedAndRepointsWhatWasRerolled(): void
    {
        $changes = Declarations::changes($this->plan(), $this->written());

        self::assertCount(2, $changes);
        self::assertTrue('dropped' === $changes[0]['action']);
        self::assertSame('drupal/core', $changes[0]['package']);
        self::assertSame('repointed', $changes[1]['action']);
        self::assertSame('patches/webform-fix-a-1234abcd.patch', $changes[1]['path']);
    }

    public function testNeverNamesAConflictFile(): void
    {
        foreach (Declarations::changes($this->plan(), $this->written()) as $change) {
            self::assertStringNotContainsString('.conflict.', $change['path']);
            self::assertNotSame('drupal/token', $change['package']);
        }
    }

    public function testLeavesAPatchThatStillAppliesAlone(): void
    {
        $patches = ['drupal/webform' => ['Fix a' => 'patches/a.patch', 'Fix b' => 'patches/b.patch']];

        self::assertSame([
            'drupal/webform' => ['Fix a' => 'patches/webform-fix-a-1234abcd.patch', 'Fix b' => 'patches/b.patch'],
        ], $this->rewritten($patches, $this->changesOn($patches)));
    }

    public function testDropsAPackageWhoseLastPatchShipped(): void
    {
        $patches = ['drupal/core' => ['Menu cache' => 'https://www.drupal.org/files/issues/c.patch']];

        self::assertSame([], $this->rewritten($patches, $this->changesOn($patches)));
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
        \file_put_contents($this->root.'/composer.json', $text);

        $this->write(Declarations::changes($this->plan(), $this->written()), (array) $this->read('composer.json')['extra']);

        $updated = (string) \file_get_contents($this->root.'/composer.json');
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

        self::assertSame([], Declarations::changes($plan, []));
    }

    public function testARerollThatWasNotWrittenIsNotRepointed(): void
    {
        self::assertSame([], \array_filter(
            Declarations::changes($this->plan(), []),
            static fn (array $change): bool => 'repointed' === $change['action']
        ));
    }

    public function testARerollWrittenOverItsOwnDeclarationIsNotRepointed(): void
    {
        $plan = $this->planFrom(['patches' => [
            $this->row(['title' => 'Fix a', 'source' => 'patches/a.patch', 'verdict' => 'conflicts']),
        ]]);
        $written = [$this->writtenFile('patches/a.patch', 'clean', 'drupal/webform', 'Fix a')];

        self::assertSame([], Declarations::changes($plan, $written));
    }

    public function testAnAdoptedUrlIsRepointedAtTheFileItWasWrittenTo(): void
    {
        $plan = $this->planFrom(['patches' => [
            $this->row(['title' => 'Fix a', 'source' => 'https://example.test/a.patch', 'verdict' => 'conflicts']),
        ]]);
        $written = [$this->writtenFile('patches/webform/a.patch', 'clean', 'drupal/webform', 'Fix a')];

        $changes = Declarations::changes($plan, $written);

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

        self::assertSame(['drupal/webform' => [
            ['description' => 'Fix a', 'url' => 'patches/webform-fix-a-1234abcd.patch', 'sha256' => 'abc'],
            ['description' => 'Fix b', 'url' => 'patches/b.patch'],
        ]], $this->rewritten($patches, $this->changesOn($patches)), 'the entry keeps every key it had, and the dropped package is gone');
    }

    // A dropped entry must not turn the list into a JSON object.
    public function testTheExpandedFormStaysAListWhenAnEntryIsDropped(): void
    {
        $patches = ['drupal/core' => [
            ['description' => 'Menu cache', 'url' => 'https://www.drupal.org/files/issues/c.patch'],
            ['description' => 'Kept', 'url' => 'patches/kept.patch'],
        ]];

        $rewritten = $this->rewritten($patches, $this->changesOn($patches));

        self::assertSame([0], \array_keys((array) $rewritten['drupal/core']));
    }

    public function testGroupsEachChangeUnderTheFileThatHeldItsEntry(): void
    {
        \file_put_contents($this->root.'/composer.json', (string) \json_encode(['extra' => ['patches' => ['drupal/webform' => ['Fix a' => 'patches/a.patch']]]]));
        \file_put_contents($this->root.'/a.json', (string) \json_encode(['patches' => ['drupal/core' => ['Menu cache' => 'https://www.drupal.org/files/issues/c.patch']]]));
        $extra = ['patches' => ['drupal/webform' => ['Fix a' => 'patches/a.patch']], 'composer-patches' => ['patches-file' => 'a.json']];

        $written = $this->write(Declarations::changes($this->plan(), $this->written()), $extra);

        self::assertSame(['composer.json', 'a.json'], $written, 'composer.json is written first, whatever the patches file is called');
        self::assertSame(['drupal/webform' => ['Fix a' => 'patches/webform-fix-a-1234abcd.patch']], $this->read('composer.json')['extra']['patches'] ?? null);
        self::assertSame([], $this->read('a.json')['patches'] ?? null);
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
        \file_put_contents($this->root.'/patches.json', $text);

        $this->write(Declarations::changes($this->plan(), $this->written()), ['composer-patches' => ['patches-file' => 'patches.json']]);

        $updated = (string) \file_get_contents($this->root.'/patches.json');
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
        ]], $this->rewritten($patches, $changes));
    }

    public function testAnEntryAlreadyExpandedKeepsItsOtherKeysBesideTheRecord(): void
    {
        $record = ['mr' => 'https://git.drupalcode.org/project/webform/-/merge_requests/940'];
        $patches = ['drupal/webform' => [['description' => 'Fix a', 'url' => 'patches/a.patch', 'depth' => 2]]];
        $changes = [['action' => 'repointed', 'package' => 'drupal/webform', 'title' => 'Fix a', 'path' => 'patch/webform/mr940.diff', 'provenance' => $record]];

        self::assertSame(['drupal/webform' => [
            ['description' => 'Fix a', 'url' => 'patch/webform/mr940.diff', 'depth' => 2, 'extra' => ['drupatch' => $record]],
        ]], $this->rewritten($patches, $changes));
    }

    // A run that records nothing leaves the site in the shape it wrote.
    public function testAChangeWithNoRecordKeepsTheCompactShape(): void
    {
        $patches = ['drupal/webform' => ['Fix a' => 'patches/a.patch']];
        $changes = [['action' => 'repointed', 'package' => 'drupal/webform', 'title' => 'Fix a', 'path' => 'patch/webform/mr940.diff', 'provenance' => []]];

        self::assertSame(['drupal/webform' => ['Fix a' => 'patch/webform/mr940.diff']], $this->rewritten($patches, $changes));
    }

    public function testAPackageNoChangeTouchesIsWrittenBackAsItStood(): void
    {
        $patches = [
            'drupal/webform' => ['Fix a' => 'patches/a.patch'],
            'drupal/pathauto' => ['Fix p' => ' patches/p.patch '],
            'drupal/token' => ['patches/token-one.patch', 'patches/token-two.patch'],
        ];
        $changes = [['action' => 'repointed', 'package' => 'drupal/webform', 'title' => 'Fix a', 'path' => 'patch/webform/mr940.diff', 'provenance' => []]];

        self::assertSame([
            'drupal/webform' => ['Fix a' => 'patch/webform/mr940.diff'],
            'drupal/pathauto' => ['Fix p' => ' patches/p.patch '],
            'drupal/token' => ['patches/token-one.patch', 'patches/token-two.patch'],
        ], $this->rewritten($patches, $changes), 'composer.json holds the untouched packages as the site wrote them');
    }

    public function testASpacedSourceIsTrimmedWhenItsPackageTakesTheExpandedShape(): void
    {
        $record = ['mr' => 'https://git.drupalcode.org/project/webform/-/merge_requests/940', 'head' => 'bbb'];
        $patches = ['drupal/webform' => ['Fix a' => 'patches/a.patch', 'Fix b' => '  patches/b.patch ']];
        $changes = [['action' => 'repointed', 'package' => 'drupal/webform', 'title' => 'Fix a', 'path' => 'patch/webform/mr940.diff', 'provenance' => $record]];

        self::assertSame(['drupal/webform' => [
            ['description' => 'Fix a', 'url' => 'patch/webform/mr940.diff', 'extra' => ['drupatch' => $record]],
            ['description' => 'Fix b', 'url' => 'patches/b.patch'],
        ]], $this->rewritten($patches, $changes), 'composer.json holds the source trimmed');
    }

    public function testATouchedMapOfObjectsIsWrittenAsAList(): void
    {
        $patches = ['drupal/webform' => [
            'Fix a' => ['description' => 'Fix a', 'url' => 'patches/a.patch'],
            'Fix b' => ['description' => 'Fix b', 'url' => 'patches/b.patch'],
        ]];
        $changes = [['action' => 'repointed', 'package' => 'drupal/webform', 'title' => 'Fix a', 'path' => 'patch/webform/mr940.diff', 'provenance' => []]];

        self::assertSame(['drupal/webform' => [
            ['description' => 'Fix a', 'url' => 'patch/webform/mr940.diff'],
            ['description' => 'Fix b', 'url' => 'patches/b.patch'],
        ]], $this->rewritten($patches, $changes), 'composer.json holds the package as a list');
    }

    public function testAStringUnderAnIntegerKeyInAnExpandedPackageIsKeptAsWritten(): void
    {
        $patches = ['drupal/webform' => [
            ['description' => 'Fix a', 'url' => 'patches/a.patch'],
            'patches/b.patch',
        ]];
        $changes = [['action' => 'repointed', 'package' => 'drupal/webform', 'title' => 'Fix a', 'path' => 'patch/webform/mr940.diff', 'provenance' => []]];

        self::assertSame(['drupal/webform' => [
            ['description' => 'Fix a', 'url' => 'patch/webform/mr940.diff'],
            'patches/b.patch',
        ]], $this->rewritten($patches, $changes), 'composer.json holds the string entry as the site wrote it');
    }

    /**
     * One declaration an add run writes, carrying where its bytes came from.
     *
     * @return list<array{action: string, package: string, title: string, path: string, provenance: array<string, string>}>
     */
    private static function adding(): array
    {
        return [[
            'action' => Declarations::ADDED,
            'package' => 'drupal/webform',
            'title' => '3521733: bfcache',
            'path' => 'patch/webform/mr940.diff',
            'provenance' => ['mr' => 'https://git.drupalcode.org/project/webform/-/merge_requests/940', 'head' => 'bbb'],
        ]];
    }

    /**
     * The declaration an add run hands the rewrite, naming the document it lands in.
     */
    private static function added(string $document = 'composer.json'): Declaration
    {
        return new Declaration('drupal/webform', '3521733: bfcache', 'patch/webform/mr940.diff', $document, true, ['mr' => 'https://git.drupalcode.org/project/webform/-/merge_requests/940', 'head' => 'bbb']);
    }

    public function testAnAddedEntryLandsOnAPackageTheSiteAlreadyPatches(): void
    {
        $patches = ['drupal/webform' => ['Fix a' => 'patches/a.patch']];

        self::assertSame(['drupal/webform' => [
            ['description' => 'Fix a', 'url' => 'patches/a.patch'],
            ['description' => '3521733: bfcache', 'url' => 'patch/webform/mr940.diff', 'extra' => ['drupatch' => ['mr' => 'https://git.drupalcode.org/project/webform/-/merge_requests/940', 'head' => 'bbb']]],
        ]], $this->rewritten($patches, self::adding(), [self::added()]));
    }

    public function testAnAddedEntryOpensAPackageTheSiteDidNotPatch(): void
    {
        $patches = ['drupal/core' => ['Menu cache' => 'patches/c.patch']];

        self::assertSame(['drupal/core' => ['Menu cache' => 'patches/c.patch'], 'drupal/webform' => [
            ['description' => '3521733: bfcache', 'url' => 'patch/webform/mr940.diff', 'extra' => ['drupatch' => ['mr' => 'https://git.drupalcode.org/project/webform/-/merge_requests/940', 'head' => 'bbb']]],
        ]], $this->rewritten($patches, self::adding(), [self::added()]));
    }

    public function testAnAddedEntryOpensThePatchesOfASiteThatDeclaredNone(): void
    {
        self::assertSame(['drupal/webform' => [
            ['description' => '3521733: bfcache', 'url' => 'patch/webform/mr940.diff', 'extra' => ['drupatch' => ['mr' => 'https://git.drupalcode.org/project/webform/-/merge_requests/940', 'head' => 'bbb']]],
        ]], $this->rewritten([], self::adding(), [self::added()]));
    }

    // The patch manager applies a package's patches in declaration order, so
    // a new one is judged on top of the ones already there.
    public function testAnAddedEntryGoesLast(): void
    {
        $patches = ['drupal/webform' => [
            ['description' => 'Fix a', 'url' => 'patches/a.patch'],
            ['description' => 'Fix b', 'url' => 'patches/b.patch'],
        ]];

        $rewritten = $this->rewritten($patches, self::adding(), [self::added()]);

        self::assertSame(['Fix a', 'Fix b', '3521733: bfcache'], \array_column((array) $rewritten['drupal/webform'], 'description'));
    }

    public function testAnAddedEntryLandsInTheDocumentItsDeclarationNames(): void
    {
        \file_put_contents($this->root.'/patches.json', '{"patches": {}}');

        self::assertSame(['patches.json'], $this->write(self::adding(), [], [self::added('patches.json')]));
        self::assertSame('3521733: bfcache', $this->read('patches.json')['patches']['drupal/webform'][0]['description'] ?? null);
    }

    // A change comes from a declaration the run read, so one whose entry the
    // document no longer holds is a defect, and a silent skip would hide it.
    public function testARepointNamingNoEntryThrows(): void
    {
        \file_put_contents($this->root.'/composer.json', (string) \json_encode(['extra' => ['patches' => ['drupal/webform' => ['Fix' => 'patches/fix.patch']]]]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no declaration of drupal/webform is titled Gone, so nothing was rewritten');

        Declarations::checked($this->root, [], null)->write(
            [['action' => Declarations::REPOINTED, 'package' => 'drupal/webform', 'title' => 'Gone', 'path' => 'patch/gone.diff', 'provenance' => []]],
            [new Declaration('drupal/webform', 'Gone', 'patches/gone.patch', 'composer.json', false, [])],
        );
    }

    public function testADropNamingNoEntryThrows(): void
    {
        \file_put_contents($this->root.'/composer.json', (string) \json_encode(['extra' => ['patches' => ['drupal/webform' => ['Fix' => 'patches/fix.patch']]]]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no declaration of drupal/token is titled Fix, so nothing was rewritten');

        Declarations::checked($this->root, [], null)->write(
            [['action' => Declarations::DROPPED, 'package' => 'drupal/token', 'title' => 'Fix', 'path' => '', 'provenance' => []]],
            [new Declaration('drupal/token', 'Fix', 'patches/fix.patch', 'composer.json', false, [])],
        );
    }
}

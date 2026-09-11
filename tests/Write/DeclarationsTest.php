<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Write;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TresBienTech\Drupatch\Read\PatchConfig;
use TresBienTech\Drupatch\Tests\Scratch;
use TresBienTech\Drupatch\Write\Declarations;
use TresBienTech\Drupatch\Write\WorkingTree;

/**
 * The question git is asked before a run writes, and the rewrites that follow it.
 */
#[CoversClass(Declarations::class)]
class DeclarationsTest extends TestCase
{
    /** The last argument of `git show` for composer.json's committed copy. */
    private const COMMITTED = 'HEAD:./composer.json';

    private string $root;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir().'/drupatch-declarations-'.\bin2hex(\random_bytes(6));
        \mkdir($this->root);
        \file_put_contents($this->root.'/composer.json', self::composerJson(['Fix' => 'patches/fix.patch']));
    }

    protected function tearDown(): void
    {
        Scratch::remove($this->root);
    }

    /**
     * @param array<string, string> $patches
     */
    private static function composerJson(array $patches, string $require = '^6.2'): string
    {
        return \json_encode(['require' => ['drupal/webform' => $require], 'extra' => ['patches' => ['drupal/webform' => $patches]]], \JSON_PRETTY_PRINT)."\n";
    }

    /**
     * @param array<string, array{int, string}> $answers
     */
    private static function git(array $answers): FakeGit
    {
        return new FakeGit(0, '', answers: $answers);
    }

    /**
     * @return array{action: string, package: string, title: string, path: string, provenance: array<string, string>}
     */
    private static function repoint(string $title, string $path): array
    {
        return ['action' => 'repointed', 'package' => 'drupal/webform', 'title' => $title, 'path' => $path, 'provenance' => []];
    }

    /**
     * @return array{package: string, title: string, source: string, file: string, shape: string}
     */
    private static function declared(string $title, string $source, string $file): array
    {
        return ['package' => 'drupal/webform', 'title' => $title, 'source' => $source, 'file' => $file, 'shape' => 'compact'];
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $file): array
    {
        return (array) \json_decode((string) \file_get_contents($this->root.'/'.$file), true);
    }

    public function testACleanDocumentPassesAndIsRewritten(): void
    {
        $declarations = Declarations::checked($this->root, [PatchConfig::COMPOSER_JSON], new WorkingTree(self::git([PatchConfig::COMPOSER_JSON => [0, '']])));

        $written = $declarations->write([self::repoint('Fix', 'patch/webform/mr940.diff')], [self::declared('Fix', 'patches/fix.patch', PatchConfig::COMPOSER_JSON)]);

        self::assertSame([PatchConfig::COMPOSER_JSON], $written);
        self::assertSame(['Fix' => 'patch/webform/mr940.diff'], $this->read('composer.json')['extra']['patches']['drupal/webform'] ?? null);
    }

    // Reaching a new core means editing constraints, so a check that
    // refused any change to the file would refuse every real run.
    public function testAnEditOutsideThePatchesPasses(): void
    {
        $this->expectNotToPerformAssertions();

        Declarations::checked($this->root, [PatchConfig::COMPOSER_JSON], new WorkingTree(self::git([
            PatchConfig::COMPOSER_JSON => [0, ' M composer.json'],
            self::COMMITTED => [0, self::composerJson(['Fix' => 'patches/fix.patch'], '^6.1')],
        ])));
    }

    public function testAnEditedPatchConfigRefuses(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('composer.json has uncommitted changes to its patches; commit them or pass --force');

        Declarations::checked($this->root, [PatchConfig::COMPOSER_JSON], new WorkingTree(self::git([
            PatchConfig::COMPOSER_JSON => [0, ' M composer.json'],
            self::COMMITTED => [0, self::composerJson(['Fix' => 'patches/other.patch'])],
        ])));
    }

    // No checkout means no committed copy to compare against, so a rewrite
    // could overwrite edits nobody can get back.
    public function testASiteGitCannotReadRefuses(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('composer.json: '.WorkingTree::NOT_A_CHECKOUT.'; pass --force');

        Declarations::checked($this->root, [PatchConfig::COMPOSER_JSON], new WorkingTree(self::git([PatchConfig::COMPOSER_JSON => [128, '']])));
    }

    public function testARepositoryWithNoCommitRefuses(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('composer.json has uncommitted changes to its patches; commit them or pass --force');

        Declarations::checked($this->root, [PatchConfig::COMPOSER_JSON], new WorkingTree(self::git([
            PatchConfig::COMPOSER_JSON => [0, '?? composer.json'],
            self::COMMITTED => [128, ''],
        ])));
    }

    // The run's own rewrites leave the patches differing from the last
    // commit, and a second question would refuse the run's own work.
    public function testGitIsAskedOnceWhateverTheRunWritesAfter(): void
    {
        $git = self::git([PatchConfig::COMPOSER_JSON => [0, '']]);
        $declarations = Declarations::checked($this->root, [PatchConfig::COMPOSER_JSON], new WorkingTree($git));
        $declared = [self::declared('Fix', 'patches/fix.patch', PatchConfig::COMPOSER_JSON)];

        $declarations->write([self::repoint('Fix', 'patch/webform/mr940.diff')], $declared);
        $declarations->write([['action' => 'dropped', 'package' => 'drupal/webform', 'title' => 'Fix', 'path' => '', 'provenance' => []]], $declared);

        self::assertSame([PatchConfig::COMPOSER_JSON], $git->asked);
        self::assertArrayNotHasKey('drupal/webform', (array) ($this->read('composer.json')['extra']['patches'] ?? []));
    }

    public function testEachDocumentGetsItsOwnEntriesInTheShapeItHeldThem(): void
    {
        \file_put_contents($this->root.'/patches.json', (string) \json_encode(['patches' => ['drupal/webform' => [['description' => 'Other', 'url' => 'patches/other.patch']]]], \JSON_PRETTY_PRINT));
        $declared = [
            self::declared('Fix', 'patches/fix.patch', PatchConfig::COMPOSER_JSON),
            ['package' => 'drupal/webform', 'title' => 'Other', 'source' => 'patches/other.patch', 'file' => 'patches.json', 'shape' => 'expanded'],
        ];

        $written = Declarations::checked($this->root, [PatchConfig::COMPOSER_JSON, 'patches.json'], null)
            ->write([self::repoint('Other', 'patch/webform/other.diff'), self::repoint('Fix', 'patch/webform/fix.diff')], $declared);

        self::assertSame([PatchConfig::COMPOSER_JSON, 'patches.json'], $written);
        self::assertSame(['Fix' => 'patch/webform/fix.diff'], $this->read('composer.json')['extra']['patches']['drupal/webform'] ?? null);
        self::assertSame([['description' => 'Other', 'url' => 'patch/webform/other.diff']], $this->read('patches.json')['patches']['drupal/webform'] ?? null);
    }

    public function testTheWholeDocumentCheckRefusesAnUntrackedFile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('patches.json: '.WorkingTree::UNTRACKED.'; commit it or pass --force');

        Declarations::refuseReplacing($this->root, [PatchConfig::COMPOSER_JSON, 'patches.json'], new WorkingTree(self::git([
            PatchConfig::COMPOSER_JSON => [0, ''],
            'patches.json' => [0, '?? patches.json'],
        ])));
    }

    // The move rewrites the requirement and the settings too, so an edit
    // anywhere in composer.json stops it.
    public function testTheWholeDocumentCheckRefusesAnEditOutsideThePatches(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('composer.json: '.WorkingTree::UNCOMMITTED.'; commit it or pass --force');

        Declarations::refuseReplacing($this->root, [PatchConfig::COMPOSER_JSON], new WorkingTree(self::git([PatchConfig::COMPOSER_JSON => [0, ' M composer.json']])));
    }

    public function testAChangeNamingNoEntryLeavesTheDocumentAsItWas(): void
    {
        $before = (string) \file_get_contents($this->root.'/composer.json');

        try {
            Declarations::checked($this->root, [PatchConfig::COMPOSER_JSON], null)
                ->write([self::repoint('Gone', 'patch/gone.diff')], [self::declared('Fix', 'patches/fix.patch', PatchConfig::COMPOSER_JSON)]);
            self::fail('a change naming no entry was written');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('drupal/webform is titled Gone', $e->getMessage());
        }

        self::assertSame($before, \file_get_contents($this->root.'/composer.json'));
    }
}

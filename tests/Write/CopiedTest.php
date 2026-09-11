<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Write;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Write\Copied;

/**
 * What a copy step hands the writers after it: the declarations naming their files, the files it made, and the repoints to write.
 */
#[CoversClass(Copied::class)]
class CopiedTest extends TestCase
{
    /**
     * @param array<string, string> $provenance
     *
     * @return array{package: string, title: string, source: string, path: string, provenance: array<string, string>}
     */
    private static function row(string $title, string $source, string $path, array $provenance = []): array
    {
        return ['package' => 'drupal/webform', 'title' => $title, 'source' => $source, 'path' => $path, 'provenance' => $provenance];
    }

    /**
     * @param array<string, string> $provenance
     *
     * @return array{package: string, title: string, source: string, provenance: array<string, string>}
     */
    private static function declaration(string $title, string $source, array $provenance = []): array
    {
        return ['package' => 'drupal/webform', 'title' => $title, 'source' => $source, 'provenance' => $provenance];
    }

    /**
     * One copy each way: made by this run, already in the site, and moved since the site copied it.
     */
    private static function copied(): Copied
    {
        return Copied::after(
            [
                self::declaration('Made', 'https://example.com/made.diff', ['mr' => 'old']),
                self::declaration('Held', 'https://example.com/held.diff', ['mr' => 'held']),
                self::declaration('Moved', 'https://example.com/moved.diff'),
                self::declaration('Local', 'patches/local.patch'),
            ],
            [self::row('Made', 'https://example.com/made.diff', 'patch/made.diff', ['mr' => 'new'])],
            [self::row('Held', 'https://example.com/held.diff', 'patch/held.diff')],
            [self::row('Moved', 'https://example.com/moved.diff', 'patch/moved.diff')],
            [],
        );
    }

    public function testEachCopiedDeclarationNamesItsFile(): void
    {
        self::assertSame(
            ['patch/made.diff', 'patch/held.diff', 'patch/moved.diff', 'patches/local.patch'],
            \array_column(self::copied()->declarations, 'source'),
        );
    }

    // A copy that brought no record leaves the one the declaration held.
    public function testADeclarationKeepsItsRecordWhenTheCopyBroughtNone(): void
    {
        self::assertSame([['mr' => 'new'], ['mr' => 'held'], [], []], \array_column(self::copied()->declarations, 'provenance'));
    }

    public function testOnlyTheFilesThisRunWroteAreCreated(): void
    {
        self::assertSame(['patch/made.diff'], self::copied()->created());
    }

    public function testEachCopiedDeclarationGetsARepoint(): void
    {
        self::assertSame(
            [
                ['action' => 'repointed', 'package' => 'drupal/webform', 'title' => 'Made', 'path' => 'patch/made.diff', 'provenance' => ['mr' => 'new']],
                ['action' => 'repointed', 'package' => 'drupal/webform', 'title' => 'Held', 'path' => 'patch/held.diff', 'provenance' => []],
                ['action' => 'repointed', 'package' => 'drupal/webform', 'title' => 'Moved', 'path' => 'patch/moved.diff', 'provenance' => []],
            ],
            self::copied()->changes(),
        );
    }

    // A declaration already naming its copy needs no rewrite, unless the run
    // lifted a record off the file onto it.
    public function testACopyDeclaredAtItsPathGetsARepointOnlyForALiftedRecord(): void
    {
        $declared = [self::declaration('Plain', 'patch/plain.diff'), self::declaration('Lifted', 'patch/lifted.diff')];

        $copied = Copied::after($declared, [], [self::row('Plain', 'patch/plain.diff', 'patch/plain.diff'), self::row('Lifted', 'patch/lifted.diff', 'patch/lifted.diff', ['mr' => 'lifted'])], [], []);

        self::assertSame(['Lifted'], \array_column($copied->changes(), 'title'));
    }

    public function testARunThatCopiesNothingHandsOnItsDeclarations(): void
    {
        $declared = [self::declaration('Local', 'patches/local.patch')];

        $copied = Copied::none($declared);

        self::assertSame($declared, $copied->declarations);
        self::assertSame([], $copied->created());
        self::assertSame([], $copied->changes());
    }

    // A re-roll run reads the site again after pinning, and writes over
    // those declarations with the copies it made before.
    public function testALaterReadKeepsTheCopiesThisRunMade(): void
    {
        $later = [self::declaration('Made', 'patch/made.diff')];

        $copied = self::copied()->over($later);

        self::assertSame($later, $copied->declarations);
        self::assertSame(['patch/made.diff'], $copied->created());
    }
}

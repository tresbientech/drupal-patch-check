<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Source\Provenance;

#[CoversClass(Provenance::class)]
class ProvenanceTest extends TestCase
{
    /**
     * Every key the record holds, in the order it writes them. The Go side
     * declares the same set, so a key dropped here is a key the service stops
     * receiving. A hash of the bytes is not among them: the patch lock keeps
     * its own over the same file.
     */
    private const KEYS = ['mr', 'commit', 'url', 'base', 'head', 'fetched', 'rerolled'];

    public function testTheRecordHoldsEveryKeyTheHeaderHeld(): void
    {
        $fields = \array_combine(self::KEYS, self::KEYS);

        self::assertSame($fields, Provenance::of($fields));
    }

    public function testTheKeysAreWrittenInOneOrder(): void
    {
        $out = Provenance::of(\array_reverse(\array_combine(self::KEYS, self::KEYS), true));

        self::assertSame(self::KEYS, \array_keys($out));
    }

    public function testAKeyItDoesNotHoldIsDropped(): void
    {
        self::assertSame(['mr' => 'm'], Provenance::of(['mr' => 'm', 'issue' => '3521733', 'depth' => '1']));
    }

    public function testAnEmptyValueIsNoRecord(): void
    {
        self::assertSame(['head' => 'b'], Provenance::of(['mr' => '', 'base' => '', 'head' => 'b']));
    }

    public function testItReadsBackWhatADefinitionHolds(): void
    {
        $record = ['mr' => 'https://git.drupalcode.org/project/webform/-/merge_requests/940', 'base' => 'aaa', 'head' => 'bbb'];

        self::assertSame($record, Provenance::read(['drupatch' => $record]));
    }

    // `extra.provenance` belongs to the patch manager, which names the
    // resolver that found the patch.
    public function testItReadsNothingFromAnotherKey(): void
    {
        self::assertSame([], Provenance::read(['provenance' => ['mr' => 'm']]));
        self::assertSame([], Provenance::read(null));
        self::assertSame([], Provenance::read(['drupatch' => 'not an object']));
    }

    // A site writes the definition by hand, so anything can be in it.
    public function testAValueThatIsNoStringIsDropped(): void
    {
        self::assertSame(['head' => 'b'], Provenance::read(['drupatch' => ['base' => ['a'], 'head' => 'b', 'fetched' => 42]]));
    }

    // 2.x hashes every patch it locks, a local file included, so a hash here
    // would be a second answer to a question the lock already answers.
    public function testAHashOfTheBytesIsNoPartOfTheRecord(): void
    {
        self::assertSame([], Provenance::of(['sha256' => 'abc']));
        self::assertSame([], Provenance::read(['drupatch' => ['sha256' => 'abc']]));
    }
}

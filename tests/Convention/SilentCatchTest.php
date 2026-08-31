<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Tests\Convention;

use PHPUnit\Framework\TestCase;

/**
 * A function that can fail to answer never returns the value it uses
 * for "no". This keeps that from being a memory exercise.
 */
final class SilentCatchTest extends TestCase
{
    /**
     * Sites where a silent falsy default is the right answer, each with
     * the reason somebody signed. Keys are `<path>::<function>`.
     *
     * @return array<string, string>
     */
    private static function allowed(): array
    {
        // 'Resolve/Candidates.php::supports' => 'why the caller cannot use the reason',
        return [];
    }

    public function testTheSourceHasNoSilentFalsyCatch(): void
    {
        $allowed = self::allowed();
        $offenders = \array_values(\array_filter(
            SilentCatch::inTree(\dirname(__DIR__, 2).'/src'),
            static fn (string $site): bool => !isset($allowed[$site]),
        ));

        self::assertSame([], $offenders, 'a catch block answers "no" for a question it could not read; report why, rethrow, or add it to '.self::class.'::allowed() with a reason');
    }

    public function testASilentFalsyCatchIsReported(): void
    {
        $source = <<<'PHP'
            <?php
            function reads(): bool {
                try { return check(); } catch (Throwable) { return false; }
            }
            PHP;

        self::assertSame(['fixture.php::reads'], SilentCatch::inSource($source, 'fixture.php'));
    }

    /**
     * @dataProvider falsyReturns
     */
    public function testEveryFalsyReturnIsReported(string $returned): void
    {
        $source = '<?php function reads() { try { work(); } catch (Throwable) { return '.$returned.'; } }';

        self::assertSame(['fixture.php::reads'], SilentCatch::inSource($source, 'fixture.php'), $returned.' was not reported');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function falsyReturns(): iterable
    {
        yield 'false' => ['false'];
        yield 'null' => ['null'];
        yield 'zero' => ['0'];
        yield 'empty string' => ["''"];
        yield 'empty array' => ['[]'];
        yield 'nothing at all' => [''];
    }

    public function testACatchThatLogsIsAccepted(): void
    {
        $source = <<<'PHP'
            <?php
            function reads(): bool {
                try { return check(); } catch (Throwable $e) { log($e); return false; }
            }
            PHP;

        self::assertSame([], SilentCatch::inSource($source, 'fixture.php'));
    }

    public function testACatchThatRethrowsIsAccepted(): void
    {
        $source = <<<'PHP'
            <?php
            function reads(): bool {
                try { return check(); } catch (Throwable $e) { throw new RuntimeException('no', 0, $e); }
            }
            PHP;

        self::assertSame([], SilentCatch::inSource($source, 'fixture.php'));
    }

    public function testACatchReturningARealAnswerIsAccepted(): void
    {
        $source = <<<'PHP'
            <?php
            function reads(): bool {
                try { return check(); } catch (Throwable) { return true; }
            }
            PHP;

        self::assertSame([], SilentCatch::inSource($source, 'fixture.php'));
    }

    public function testAnAllowlistedSiteIsAccepted(): void
    {
        $source = '<?php function reads() { try { work(); } catch (Throwable) { return false; } }';
        $allowed = ['fixture.php::reads' => 'the caller cannot act on the reason'];

        $offenders = \array_values(\array_filter(
            SilentCatch::inSource($source, 'fixture.php'),
            static fn (string $site): bool => !isset($allowed[$site]),
        ));

        self::assertSame([], $offenders);
    }

    public function testTheWordCatchInAStringIsNotASite(): void
    {
        $source = '<?php function reads() { $s = "catch (Throwable) { return false; }"; return $s; }';

        self::assertSame([], SilentCatch::inSource($source, 'fixture.php'));
    }

    public function testEachSiteIsNamedByItsFunction(): void
    {
        $source = <<<'PHP'
            <?php
            class Reader {
                public function first() { try { work(); } catch (Throwable) { return null; } }
                public function second() { try { work(); } catch (Throwable) { return false; } }
            }
            PHP;

        self::assertSame(
            ['fixture.php::first', 'fixture.php::second'],
            SilentCatch::inSource($source, 'fixture.php'),
        );
    }
}

<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Render;

use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Render\PinReport;

class PinReportTest extends TestCase
{
    /**
     * @param array<string, list<array<string, string>>> $lists
     */
    private static function report(array $lists, int $rewritten = 0): string
    {
        return \implode("\n", PinReport::lines($lists + ['vendored' => [], 'kept' => [], 'moved' => [], 'refused' => []], 'composer.json', $rewritten));
    }

    /**
     * @return array<string, string>
     */
    private static function row(string $path = 'patch/webform/mr940.diff'): array
    {
        return ['package' => 'drupal/webform', 'title' => '3521733: bfcache', 'source' => 'https://git.drupalcode.org/project/webform/-/merge_requests/940.patch', 'path' => $path];
    }

    public function testACopiedPatchIsNamedOverItsFile(): void
    {
        $out = self::report(['vendored' => [self::row()]], 1);

        self::assertStringContainsString('1 patch copied into the site', $out);
        self::assertStringContainsString("  copied into the site:\n    drupal/webform: 3521733: bfcache\n      patch/webform/mr940.diff", $out);
        self::assertStringContainsString('composer.json: 1 declaration now names a file in the site', $out);
    }

    // The site keeps what it holds, and the run says what it would take.
    public function testAMovedRequestSaysHowToTakeIt(): void
    {
        $out = self::report(['moved' => [self::row()]]);

        self::assertStringContainsString('1 patch moved upstream', $out);
        self::assertStringContainsString("  moved since you copied it:\n    drupal/webform: 3521733: bfcache\n      patch/webform/mr940.diff", $out);
        self::assertStringContainsString('run `composer drupatch:pin --refresh` to take the new commits', $out);
    }

    public function testARefusalHeadsItsGroup(): void
    {
        $refused = ['package' => 'drupal/webform', 'title' => 'a', 'source' => 'x', 'reason' => 'the host answered 429'];

        $out = self::report(['refused' => [$refused]]);

        self::assertStringContainsString("  not copied:\n    <comment>the host answered 429</comment>\n      drupal/webform: a", $out);
    }

    public function testARunWithNothingToDoSaysSo(): void
    {
        self::assertStringContainsString('no patch is declared from a merge request', self::report([]));
    }
}

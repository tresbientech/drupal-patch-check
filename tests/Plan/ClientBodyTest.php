<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Tests\Plan;

use PHPUnit\Framework\TestCase;
use Tresbien\Drupatch\PatchConfig\Resolution;
use Tresbien\Drupatch\Plan\Client;

/**
 * The body is what `--dry-run` prints, so a case here is a case about
 * what a reviewer will read.
 */
final class ClientBodyTest extends TestCase
{
    public function testTheBodyCarriesTheDocumentsItWasGiven(): void
    {
        $body = Client::body('{"require":{}}', '{"packages":[]}', $this->resolution());

        self::assertSame('{"require":{}}', $body['composer_json']);
        self::assertSame('{"packages":[]}', $body['composer_lock']);
        self::assertTrue($body['patches']);
    }

    public function testTheBodyCarriesTheResolvedPatchesAndTheirText(): void
    {
        $body = Client::body('{}', '{}', $this->resolution());

        self::assertSame([['package' => 'drupal/webform', 'title' => 'Alter hook', 'source' => 'patches/a.patch']], $body['patch_config']);
        self::assertSame(['patches/a.patch' => "diff --git a/x b/x\n"], (array) $body['patch_files']);
    }

    public function testABareRunCarriesNoTargetAndNoCandidate(): void
    {
        $body = Client::body('{}', '{}', $this->resolution());

        self::assertSame('', $body['target_core']);
        self::assertFalse($body['reroll']);
        self::assertSame([], (array) $body['candidates']);
    }

    public function testATargetedRunCarriesWhatComposerPicked(): void
    {
        $body = Client::body('{}', '{}', $this->resolution(), '11.4.5', true, ['drupal/webform' => '6.3.1']);

        self::assertSame('11.4.5', $body['target_core']);
        self::assertTrue($body['reroll']);
        self::assertSame(['drupal/webform' => '6.3.1'], (array) $body['candidates']);
    }

    public function testEmptyMapsStayObjectsSoTheServiceCanReadThem(): void
    {
        $body = Client::body('{}', '{}', new Resolution([], [], [], '', [], []));

        $encoded = (string) \json_encode($body);

        self::assertStringContainsString('"patch_files":{}', $encoded);
        self::assertStringContainsString('"candidates":{}', $encoded);
        self::assertStringContainsString('"installed_core":{}', $encoded);
    }

    // The service reads release data that can lag a project by months. What
    // the site has on disk cannot lag, so it travels with every run.
    public function testTheBodyCarriesWhatEachInstalledReleaseDeclares(): void
    {
        $body = Client::body('{}', '{}', $this->resolution(), '', false, [], ['drupal/webform' => '^10.3 || ^11']);

        self::assertSame(['drupal/webform' => '^10.3 || ^11'], (array) $body['installed_core']);
    }

    private function resolution(): Resolution
    {
        return new Resolution(
            [['package' => 'drupal/webform', 'title' => 'Alter hook', 'source' => 'patches/a.patch']],
            ['patches/a.patch' => "diff --git a/x b/x\n"],
            [],
            '',
            [],
            [],
        );
    }
}

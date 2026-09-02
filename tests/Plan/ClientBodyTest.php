<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Plan;

use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Client;
use TresBienTech\Drupatch\PatchConfig;

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

    // The service tells a request shaped by an older release apart by
    // this, so it travels with every call.
    public function testTheBodyNamesTheClientAndItsVersion(): void
    {
        $body = Client::body('{}', '{}', $this->resolution());

        self::assertSame(Client::AGENT, $body['client']);
        self::assertStringStartsWith('drupal-patch-check/', (string) $body['client']);
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
        $body = Client::body('{}', '{}', new PatchConfig([], [], [], '', [], []));

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

    private function resolution(): PatchConfig
    {
        return new PatchConfig(
            [['package' => 'drupal/webform', 'title' => 'Alter hook', 'source' => 'patches/a.patch']],
            ['patches/a.patch' => "diff --git a/x b/x\n"],
            [],
            '',
            [],
            [],
        );
    }

    public function testAPatchWithDecidedRegionsCarriesThem(): void
    {
        $body = Client::body('{}', '{}', $this->resolution(), '', true, [], [], [
            0 => [['file' => 'src/Form.php', 'region' => 1, 'text' => '  $decided = TRUE;']],
        ]);

        self::assertSame([[
            'package' => 'drupal/webform',
            'title' => 'Alter hook',
            'source' => 'patches/a.patch',
            'resolutions' => [['file' => 'src/Form.php', 'region' => 1, 'text' => '  $decided = TRUE;']],
        ]], $body['patch_config']);
    }

    public function testAnEmptiedRegionIsCarriedAsADelete(): void
    {
        $body = Client::body('{}', '{}', $this->resolution(), '', true, [], [], [
            0 => [['file' => 'src/Form.php', 'region' => 0, 'delete' => true]],
        ]);

        self::assertSame([[
            'package' => 'drupal/webform',
            'title' => 'Alter hook',
            'source' => 'patches/a.patch',
            'resolutions' => [['file' => 'src/Form.php', 'region' => 0, 'delete' => true]],
        ]], $body['patch_config']);
    }

    public function testAPatchWithNoDecidedRegionCarriesNoResolutionsKey(): void
    {
        $body = Client::body('{}', '{}', $this->resolution(), '', true, [], [], []);

        self::assertSame(
            [['package' => 'drupal/webform', 'title' => 'Alter hook', 'source' => 'patches/a.patch']],
            $body['patch_config']
        );
    }
}

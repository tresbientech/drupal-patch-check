<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Plan;

use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Read\PatchConfig;
use TresBienTech\Drupatch\Service\Client;

/**
 * The body is what `--dry-run` prints, so a case here is a case about
 * what a reviewer will read.
 */
final class ClientBodyTest extends TestCase
{
    public function testTheBodyCarriesTheDocumentsItWasGiven(): void
    {
        $body = self::body('{"require":{}}', '{"packages":[]}');

        self::assertSame('{"require":{}}', $body['composer_json']);
        self::assertSame('{"packages":[]}', $body['composer_lock']);
        self::assertTrue($body['patches']);
    }

    // The title stays at home; the service echoes it and nothing more. The
    // text rides on the entry it belongs to, so nothing has to be joined by
    // the path the site wrote.
    public function testAnEntryCarriesItsOwnText(): void
    {
        $body = self::body('{}', '{}', $this->resolution());

        self::assertSame([[
            'package' => 'drupal/webform',
            'patch' => "diff --git a/x b/x\n",
        ]], $body['patch_config']);
    }

    public function testNoTextTravelsTwice(): void
    {
        $body = self::body('{}', '{}', $this->resolution());

        self::assertArrayNotHasKey('patch_files', $body);
        self::assertSame(1, \substr_count((string) \json_encode($body), 'diff --git'));
    }

    public function testAMergeRequestDeclarationCarriesBothFormsAndTheRequest(): void
    {
        $mr = 'https://git.drupalcode.org/project/webform/-/merge_requests/940';
        $patches = new PatchConfig(
            [['package' => 'drupal/webform', 'title' => 'Alter hook', 'source' => $mr.'.patch', 'file' => 'composer.json', 'shape' => 'compact', 'provenance' => []]],
            [$mr.'.patch' => "diff --git a/x b/x\ndeclared\n", $mr.'.diff' => "diff --git a/x b/x\nsquashed\n"],
            [],
            [],
            [],
        );

        $body = self::body('{}', '{}', $patches);

        self::assertSame([[
            'package' => 'drupal/webform',
            'patch' => "diff --git a/x b/x\ndeclared\n",
            'merge_patch' => "diff --git a/x b/x\nsquashed\n",
            'upstream' => $mr,
        ]], $body['patch_config']);
    }

    public function testAPatchWhoseTextWasHeldBackCarriesNone(): void
    {
        $patches = new PatchConfig(
            [['package' => 'drupal/webform', 'title' => 'Alter hook', 'source' => 'patches/big.patch', 'file' => 'composer.json', 'shape' => 'compact', 'provenance' => []]],
            [],
            [],
            [],
            [['package' => 'drupal/webform', 'title' => 'Alter hook', 'source' => 'patches/big.patch', 'reason' => 'above the 16 MB cap', 'file' => 'composer.json', 'shape' => 'compact', 'provenance' => []]],
        );

        $body = self::body('{}', '{}', $patches);

        self::assertSame([['package' => 'drupal/webform']], $body['patch_config']);
    }

    // The claim a person checks with --dry-run: nothing of the site's own
    // words is in the document.
    public function testTheBodyHoldsNoPathAndNoTitleOfTheSites(): void
    {
        $encoded = (string) \json_encode(self::body('{}', '{}', $this->resolution()));

        self::assertStringNotContainsString('patches/a.patch', $encoded);
        self::assertStringNotContainsString('Alter hook', $encoded);
    }

    public function testABareRunCarriesNoTargetAndNoCandidate(): void
    {
        $body = self::body('{}', '{}');

        self::assertSame('', $body['target_core']);
        self::assertFalse($body['reroll']);
        self::assertSame([], (array) $body['candidates']);
    }

    // The service tells a request shaped by an older release apart by
    // this, so it travels with every call.
    public function testTheBodyNamesTheClientAndItsVersion(): void
    {
        $body = self::body('{}', '{}');

        self::assertSame(Client::agent(), $body['client']);
        self::assertStringStartsWith('drupal-patch-check/', (string) $body['client']);
    }

    public function testATargetedRunCarriesWhatComposerPicked(): void
    {
        $body = self::body('{}', '{}', $this->resolution(), '11.4.5', true, ['drupal/webform' => '6.3.1']);

        self::assertSame('11.4.5', $body['target_core']);
        self::assertTrue($body['reroll']);
        self::assertSame(['drupal/webform' => '6.3.1'], (array) $body['candidates']);
    }

    public function testEmptyMapsStayObjectsSoTheServiceCanReadThem(): void
    {
        $body = self::body('{}', '{}', new PatchConfig([], [], [], [], []));

        $encoded = (string) \json_encode($body);

        self::assertStringContainsString('"candidates":{}', $encoded);
        self::assertStringContainsString('"installed_core":{}', $encoded);
    }

    // The service reads release data that can lag a project by months. What
    // the site has on disk cannot lag, so it travels with every run.
    public function testTheBodyCarriesWhatEachInstalledReleaseDeclares(): void
    {
        $body = self::body('{}', '{}', $this->resolution(), '', false, [], ['drupal/webform' => '^10.3 || ^11']);

        self::assertSame(['drupal/webform' => '^10.3 || ^11'], (array) $body['installed_core']);
    }

    /**
     * The request, with the declarations object built from the same config the request carries.
     *
     * @param array<string, string>                  $candidates
     * @param array<string, string>                  $declared
     * @param array<int, list<array<string, mixed>>> $resolutions
     *
     * @return array<string, mixed>
     */
    private static function body(string $json, string $lock, ?PatchConfig $patches = null, string $targetCore = '', bool $reroll = false, array $candidates = [], array $declared = [], array $resolutions = []): array
    {
        $patches ??= new PatchConfig([], [], [], [], []);

        return Client::body($json, $lock, $patches, $targetCore, $reroll, $candidates, $declared, $resolutions);
    }

    private function resolution(): PatchConfig
    {
        return new PatchConfig(
            [['package' => 'drupal/webform', 'title' => 'Alter hook', 'source' => 'patches/a.patch', 'file' => 'composer.json', 'shape' => 'compact', 'provenance' => []]],
            ['patches/a.patch' => "diff --git a/x b/x\n"],
            [],
            [],
            [],
        );
    }

    public function testAPatchWithDecidedRegionsCarriesThem(): void
    {
        $body = self::body('{}', '{}', $this->resolution(), '', true, [], [], [
            0 => [['file' => 'src/Form.php', 'region' => 1, 'text' => '  $decided = TRUE;']],
        ]);

        self::assertSame([[
            'package' => 'drupal/webform',
            'patch' => "diff --git a/x b/x\n",
            'resolutions' => [['file' => 'src/Form.php', 'region' => 1, 'text' => '  $decided = TRUE;']],
        ]], $body['patch_config']);
    }

    public function testAnEmptiedRegionIsCarriedAsADelete(): void
    {
        $body = self::body('{}', '{}', $this->resolution(), '', true, [], [], [
            0 => [['file' => 'src/Form.php', 'region' => 0, 'delete' => true]],
        ]);

        self::assertSame([[
            'package' => 'drupal/webform',
            'patch' => "diff --git a/x b/x\n",
            'resolutions' => [['file' => 'src/Form.php', 'region' => 0, 'delete' => true]],
        ]], $body['patch_config']);
    }

    public function testAPatchWithNoDecidedRegionCarriesNoResolutionsKey(): void
    {
        $body = self::body('{}', '{}', $this->resolution(), '', true, [], [], []);

        self::assertSame(
            [['package' => 'drupal/webform', 'patch' => "diff --git a/x b/x\n"]],
            $body['patch_config']
        );
    }

    // A vendored patch travels under a local path, so the record on its
    // declaration is how the service reaches the merge request behind it.
    public function testAVendoredPatchSendsItsProvenance(): void
    {
        $record = ['mr' => 'https://git.drupalcode.org/project/webform/-/merge_requests/940', 'base' => 'aaa', 'head' => 'bbb'];
        $patches = new PatchConfig(
            [['package' => 'drupal/webform', 'title' => 'Alter hook', 'source' => 'patch/webform/mr940.diff', 'file' => 'composer.json', 'shape' => 'expanded', 'provenance' => $record]],
            ['patch/webform/mr940.diff' => "diff --git a/x b/x\n"],
            [],
            [],
            [],
        );

        $body = self::body('{}', '{}', $patches);

        self::assertSame([[
            'package' => 'drupal/webform',
            'patch' => "diff --git a/x b/x\n",
            'provenance' => $record,
        ]], $body['patch_config']);
    }

    public function testADeclarationWithNoRecordSendsNoProvenanceField(): void
    {
        $body = self::body('{}', '{}', $this->resolution());

        self::assertArrayNotHasKey('provenance', $body['patch_config'][0]);
    }
}

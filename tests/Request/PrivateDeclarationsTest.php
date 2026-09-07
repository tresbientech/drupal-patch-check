<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Request;

use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Client;
use TresBienTech\Drupatch\PatchConfig;
use TresBienTech\Drupatch\Plan\Plan;
use TresBienTech\Drupatch\PrivateDeclarations;
use TresBienTech\Drupatch\Tests\PlanFactory;

final class PrivateDeclarationsTest extends TestCase
{
    use PlanFactory;

    /** A path that names a client, a ticket system and a project. */
    private const LOCAL = 'patch/acquia_dam/CUP-1341_Fix_SVG_dam_rendering.patch';

    /** A patch kept on a company host, which is as private as a local path. */
    private const HOSTED = 'https://patches.acme-internal.com/CUP-1383.patch';

    /** A merge request the service reads to find the diff a re-roll merges from. */
    private const UPSTREAM = 'https://git.drupalcode.org/project/webform/-/merge_requests/42.patch';

    private const TITLE = 'CUP-1341: Remove field Form Type from TMGMT translations';

    public function testTheKeySetSendsNoTitleAndNoPrivateSource(): void
    {
        $body = $this->body([
            ['package' => 'drupal/webform', 'title' => self::TITLE, 'source' => self::LOCAL],
            ['package' => 'drupal/pathauto', 'title' => 'CUP-1383: alias state', 'source' => self::HOSTED],
        ], true);

        self::assertSame([
            ['package' => 'drupal/webform', 'source' => 'p0'],
            ['package' => 'drupal/pathauto', 'source' => 'p1'],
        ], $body['patch_config']);
        self::assertSame(['p0' => 'diff 0', 'p1' => 'diff 1'], (array) $body['patch_files']);
    }

    public function testADrupalOrgUrlIsSentAsWritten(): void
    {
        // The service reads the merge request in it to find the diff a
        // re-roll starts from, and the URL names nothing of the site.
        $body = $this->body([
            ['package' => 'drupal/webform', 'title' => self::TITLE, 'source' => self::UPSTREAM],
            ['package' => 'drupal/pathauto', 'title' => 'In house', 'source' => self::LOCAL],
        ], true);

        self::assertSame([self::UPSTREAM, 'p0'], \array_column($body['patch_config'], 'source'));
        self::assertSame([self::UPSTREAM, 'p0'], \array_keys((array) $body['patch_files']));
    }

    // A patch kept on a company host names the company in its URL, so the
    // URL is replaced like a local path. Only drupal.org is left alone.
    public function testACompanyHostedUrlIsReplacedLikeAPath(): void
    {
        $body = $this->body([
            ['package' => 'drupal/webform', 'title' => self::TITLE, 'source' => self::HOSTED],
        ], true);

        self::assertSame(['p0'], \array_column($body['patch_config'], 'source'));
        self::assertSame(['p0'], \array_keys((array) $body['patch_files']));
    }

    public function testTwoDeclarationsNamingOnePatchShareOnePlaceholder(): void
    {
        $body = $this->body([
            ['package' => 'drupal/webform', 'title' => self::TITLE, 'source' => self::LOCAL],
            ['package' => 'drupal/webform_ui', 'title' => 'The same patch', 'source' => self::LOCAL],
        ], true, [self::LOCAL => 'diff 0']);

        self::assertSame(['p0', 'p0'], \array_column($body['patch_config'], 'source'));
        self::assertSame(['p0' => 'diff 0'], (array) $body['patch_files']);
    }

    // The service reads a title only to echo it back, so no site sends one.
    // The key decides the path, and nothing else.
    public function testTheKeyUnsetSendsThePathAsWrittenAndStillNoTitle(): void
    {
        $body = $this->body([
            ['package' => 'drupal/webform', 'title' => self::TITLE, 'source' => self::LOCAL],
        ], false);

        self::assertSame([['package' => 'drupal/webform', 'source' => self::LOCAL]], $body['patch_config']);
        self::assertSame([self::LOCAL], \array_keys((array) $body['patch_files']));
    }

    public function testTheAnswerComesBackWithTheSitesOwnWords(): void
    {
        $plan = $this->revealed([
            ['package' => 'drupal/webform', 'title' => self::TITLE, 'source' => self::LOCAL],
            ['package' => 'drupal/pathauto', 'title' => 'Alias state', 'source' => self::UPSTREAM],
        ], [
            $this->row(['package' => 'drupal/webform', 'title' => '', 'source' => 'p0']),
            $this->row(['package' => 'drupal/pathauto', 'title' => '', 'source' => self::UPSTREAM]),
        ]);

        self::assertSame([self::TITLE, 'Alias state'], \array_column($plan->patches, 'title'));
        self::assertSame([self::LOCAL, self::UPSTREAM], \array_column($plan->patches, 'source'));
    }

    public function testTheJsonOutputCarriesTheSitesOwnWords(): void
    {
        $plan = $this->revealed(
            [['package' => 'drupal/webform', 'title' => self::TITLE, 'source' => self::LOCAL]],
            [$this->row(['title' => '', 'source' => 'p0'])],
        );

        // The flag the check command prints with, so the paths read as written.
        $json = (string) \json_encode($plan->raw, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        self::assertStringContainsString(self::LOCAL, $json);
        self::assertStringNotContainsString('"p0"', $json);
    }

    public function testTheMissingFileListNamesTheSitesOwnPaths(): void
    {
        $plan = $this->revealed(
            [['package' => 'drupal/webform', 'title' => self::TITLE, 'source' => self::LOCAL]],
            [$this->row(['title' => '', 'source' => 'p0'])],
            ['p0'],
        );

        self::assertSame([self::LOCAL], $plan->missingFiles);
    }

    /**
     * The request as one site would send it, with a text per distinct source.
     *
     * @param list<array{package: string, title: string, source: string}> $patches
     * @param array<string, string>|null                                  $files
     *
     * @return array<string, mixed>
     */
    private function body(array $patches, bool $on, ?array $files = null): array
    {
        if (null === $files) {
            $files = [];
            foreach (\array_values(\array_unique(\array_column($patches, 'source'))) as $i => $source) {
                $files[$source] = 'diff '.$i;
            }
        }
        $config = new PatchConfig($patches, $files, [], '', [], []);

        return Client::body('{}', '{}', $config, PrivateDeclarations::of($config, $on));
    }

    /**
     * The plan a site reads, built from an answer that came back in placeholders.
     *
     * @param list<array{package: string, title: string, source: string}> $patches
     * @param list<array<string, mixed>>                                  $rows
     * @param list<string>                                                $missing
     */
    private function revealed(array $patches, array $rows, array $missing = []): Plan
    {
        $private = PrivateDeclarations::of(new PatchConfig($patches, [], [], '', [], []), true);
        $answer = self::wire(['target_core' => '11.4.5', 'patches' => $rows, 'missing_files' => $missing]);

        return Plan::fromArray($private->reveal($answer));
    }
}

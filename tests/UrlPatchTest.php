<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests;

use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Source\UrlPatch;

/**
 * A patch declared as a URL that names neither a merge request nor a commit:
 * a file on drupal.org, a snippet on somebody's GitLab.
 */
class UrlPatchTest extends TestCase
{
    public function testItIsNamedAfterTheFileTheUrlEndsIn(): void
    {
        $patch = UrlPatch::of('https://www.drupal.org/files/issues/2022-02-25/pathauto-3131794-15.patch', 'drupal/pathauto');

        self::assertNotNull($patch);
        self::assertSame('patch/pathauto/pathauto-3131794-15.patch', $patch->file('patch'));
    }

    public function testAQueryStringIsNotPartOfTheName(): void
    {
        $patch = UrlPatch::of('https://example.test/files/a.patch?id=7&raw=1', 'drupal/webform');

        self::assertNotNull($patch);
        self::assertSame('patch/webform/a.patch', $patch->file('patch'));
    }

    public function testTheUrlIsReadAsDeclared(): void
    {
        $patch = UrlPatch::of('https://example.test/files/a.patch?id=7', 'drupal/webform');

        self::assertNotNull($patch);
        self::assertSame('https://example.test/files/a.patch?id=7', $patch->diff());
    }

    public function testAPathAndANamelessUrlNameNoFile(): void
    {
        self::assertNull(UrlPatch::of('patches/webform/a.patch', 'drupal/webform'));
        self::assertNull(UrlPatch::of('https://example.test/files/', 'drupal/webform'));
    }

    // A package name with a separator left in it would pick the directory the
    // file lands in.
    public function testAPackageThatIsNotADrupalProjectNamesNoFile(): void
    {
        self::assertNull(UrlPatch::of('https://example.test/a.patch', 'acme/private-fork'));
    }
}

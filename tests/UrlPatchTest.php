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
    // What decides where a declared source's bytes come from: the site's
    // disk, or the host the URL names.
    public function testAUrlIsTheOneShapeThatNamesAHost(): void
    {
        self::assertTrue(UrlPatch::isUrl('http://example.test/a.patch'));
        self::assertTrue(UrlPatch::isUrl('https://www.drupal.org/files/issues/a.patch'));
        self::assertTrue(UrlPatch::isUrl('HTTPS://WWW.DRUPAL.ORG/files/issues/a.patch'));
        self::assertTrue(UrlPatch::isUrl('  https://example.test/a.patch  '));
        self::assertFalse(UrlPatch::isUrl('patches/webform/a.patch'));
        self::assertFalse(UrlPatch::isUrl(''));
    }

    // A copied patch's URL leaves the site only on these hosts, so a host that
    // merely contains one of their names stays home.
    public function testDrupalOrgIsTheExactHost(): void
    {
        self::assertTrue(UrlPatch::isDrupalOrg('https://www.drupal.org/files/issues/2024-05-02/3446484-fix.patch'));
        self::assertTrue(UrlPatch::isDrupalOrg('https://drupal.org/files/issues/a.patch'));
        self::assertTrue(UrlPatch::isDrupalOrg('HTTPS://GIT.DRUPALCODE.ORG/project/webform/-/commit/abc.diff'));
        self::assertFalse(UrlPatch::isDrupalOrg('https://drupal.org.acme.test/a.patch'));
        self::assertFalse(UrlPatch::isDrupalOrg('https://acme-drupal.org/a.patch'));
        self::assertFalse(UrlPatch::isDrupalOrg('https://www.drupal.org@files.acme.test/a.patch'));
        self::assertFalse(UrlPatch::isDrupalOrg('patch/www.drupal.org/a.patch'));
        self::assertFalse(UrlPatch::isDrupalOrg('https:///a.patch'));
        self::assertFalse(UrlPatch::isDrupalOrg(''));
    }

    public function testItIsNamedAfterTheFileTheUrlEndsIn(): void
    {
        $patch = UrlPatch::of('https://www.drupal.org/files/issues/2022-02-25/pathauto-3131794-15.patch', 'drupal/pathauto');

        self::assertNotNull($patch);
        self::assertSame('patch/pathauto/pathauto-3131794-15.patch', $patch->file('patch'));
    }

    // The folder is named after the package, so a core patch goes in core.
    public function testACorePatchIsCopiedIntoTheCoreFolder(): void
    {
        $patch = UrlPatch::of('https://www.drupal.org/files/issues/2024-05-02/3446484-fix.patch', 'drupal/core');

        self::assertNotNull($patch);
        self::assertSame('patch/core/3446484-fix.patch', $patch->file('patch'));
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

<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Read;

use ArrayObject;
use Closure;
use Composer\Composer;
use Composer\Factory;
use Composer\IO\NullIO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TresBienTech\Drupatch\Read\Declaration;
use TresBienTech\Drupatch\Read\Request;
use TresBienTech\Drupatch\Read\Site;
use TresBienTech\Drupatch\Tests\Scratch;
use TresBienTech\Drupatch\Tests\StubHost;

/**
 * What one site answers about itself, read from a directory rather than through a command.
 */
#[CoversClass(Site::class)]
#[CoversClass(Request::class)]
class SiteTest extends TestCase
{
    private const URL = 'https://www.drupal.org/files/issues/a.patch';

    private const PATCH = "diff --git a/x b/x\n--- a/x\n+++ b/x\n@@ -1 +1 @@\n-a\n+b\n";

    private string $root = '';

    protected function tearDown(): void
    {
        if ('' !== $this->root) {
            Scratch::remove($this->root);
        }
        $this->root = '';
    }

    // Both documents name the same file, and the service is told about it
    // once.
    public function testAPatchDeclaredInBothDocumentsIsReadOnce(): void
    {
        $site = $this->site(
            ['composer-patches' => ['patches-file' => 'patches.json'], 'patches' => ['drupal/webform' => ['Alter hook' => 'patches/a.patch']]],
            ['patches.json' => ['patches' => ['drupal/webform' => ['Alter hook' => 'patches/a.patch']]], 'patches/a.patch' => self::PATCH],
        );

        $request = $site->request();

        self::assertCount(1, $request->patches->patches);
        self::assertSame(['patches/a.patch' => self::PATCH], $request->patches->files);
    }

    // 1.x and 2.x read the patches file from different keys, so the release
    // the lock pins decides which document a run reads.
    public function testTheManagerDecidesWhichDocumentIsRead(): void
    {
        $onTwo = $this->site(
            ['composer-patches' => ['patches-file' => 'patches.json']],
            ['patches.json' => ['patches' => ['drupal/webform' => ['Alter hook' => 'patches/a.patch']]], 'patches/a.patch' => self::PATCH],
            '2.0.0',
        );
        self::assertCount(1, $onTwo->request()->patches->patches);

        $this->tearDown();
        $onOne = $this->site(
            ['composer-patches' => ['patches-file' => 'patches.json']],
            ['patches.json' => ['patches' => ['drupal/webform' => ['Alter hook' => 'patches/a.patch']]], 'patches/a.patch' => self::PATCH],
            '1.7.3',
        );

        self::assertSame([], $onOne->request()->patches->patches);
    }

    // The request is about the packages the service can judge (ADR 0003). A
    // fork keeps the drupal name and stays home, patch included.
    public function testTheRequestCarriesTheCheckablePackagesAlone(): void
    {
        $site = $this->site(
            ['patches' => [
                'drupal/webform' => ['Alter hook' => 'patches/a.patch'],
                'drupal/acme' => ['Ours' => 'patches/b.patch'],
            ]],
            ['patches/a.patch' => self::PATCH, 'patches/b.patch' => self::PATCH],
        );

        $request = $site->request();

        self::assertSame(['drupal/webform'], \array_column($request->patches->patches, 'package'));
        self::assertStringNotContainsString('drupal/acme', $request->composerLock);
        self::assertStringNotContainsString('drupal/acme', $request->composerJson);
        self::assertSame('6.2.9', $site->installs('drupal/acme'), 'the lock still names what it installs');
        self::assertFalse($site->canJudge('drupal/acme'));
    }

    public function testALockThatCannotBeReadNamesItself(): void
    {
        $this->site([], []);
        \unlink($this->root.'/composer.lock');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('composer.lock is not readable; run composer update first');

        Site::at($this->composerOf($this->root), new NullIO(), $this->root.'/composer.json');
    }

    // Every question but the request is answered from the two documents, so
    // an add run reads no patch the site declares.
    public function testReadingASiteFetchesNothing(): void
    {
        $asked = new ArrayObject();
        $site = $this->site(
            ['patches' => ['drupal/webform' => ['Alter hook' => self::URL]]],
            [],
            '2.0.0',
            StubHost::fetch([self::URL => [200, self::PATCH]], $asked),
        );

        self::assertSame('6.2.9', $site->installs('drupal/webform'));
        self::assertTrue($site->canJudge('drupal/webform'));
        self::assertSame($this->root, $site->root);
        self::assertTrue($site->manager->isTwo());
        $site->text();
        self::assertCount(0, $asked, 'reading the site asked no host');

        $site->request();

        self::assertSame([self::URL], (array) $asked);
    }

    public function testARequestIsReadOnce(): void
    {
        $asked = new ArrayObject();
        $site = $this->site(
            ['patches' => ['drupal/webform' => ['Alter hook' => self::URL]]],
            [],
            '2.0.0',
            StubHost::fetch([self::URL => [200, self::PATCH]], $asked),
        );

        $site->request();
        $site->request();

        self::assertCount(1, $asked);
    }

    // A patch about a package the service has no release for would leave the
    // site for an answer of `unknown`.
    public function testARequestForAPackageTheServiceCannotJudgeIsRefused(): void
    {
        $site = $this->site([], []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('this site installs drupal/acme from outside drupal.org, so the service cannot judge a patch on it');

        $site->requestFor($this->declaration('drupal/acme'), self::PATCH);
    }

    // The manager applies what the site declares for the package before the
    // new patch, so the service is told them in that order and judges the
    // new one behind them. Another package's patches are no part of it.
    public function testARequestForOnePatchCarriesThePackagesOwnPatchesBeforeIt(): void
    {
        $site = $this->site(
            ['patches' => [
                'drupal/webform' => ['Alter hook' => 'patches/a.patch'],
                'drupal/acme' => ['Fork fix' => 'patches/b.patch'],
            ]],
            ['patches/a.patch' => self::PATCH, 'patches/b.patch' => self::PATCH],
        );

        $request = $site->requestFor($this->declaration('drupal/webform'), "diff --git a/y b/y\n");

        self::assertSame(['patches/a.patch', 'patch/webform/mr940.diff'], \array_column($request->patches->patches, 'source'));
        self::assertSame(['drupal/webform', 'drupal/webform'], \array_column($request->patches->patches, 'package'));
        self::assertSame(['patches/a.patch' => self::PATCH, 'patch/webform/mr940.diff' => "diff --git a/y b/y\n"], $request->patches->files);
        self::assertSame(['drupal/webform' => '^6.2'], $request->constraints);
        self::assertSame(['drupal/webform' => '^10.3 || ^11'], $request->declaredCore, 'the vendor directory says what core the release needs');
    }

    // Singular, so a copied patch does not land in a `patches` directory the
    // site already manages by hand.
    public function testASiteThatNamesNoDirectoryGetsPatch(): void
    {
        self::assertSame('patch', $this->site([], [])->patchDirectory());
        $this->tearDown();
        self::assertSame('patch', $this->site(['drupal-patch-check' => ['hook' => true]], [])->patchDirectory());
    }

    public function testTheNamedDirectoryIsReadAsWritten(): void
    {
        self::assertSame('patchs', $this->site(['drupal-patch-check' => ['patch-directory' => '  patchs  ']], [])->patchDirectory());
    }

    public function testAValueThatNamesNoDirectoryIsRefused(): void
    {
        $site = $this->site(['drupal-patch-check' => ['patch-directory' => true]], []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('patch-directory is where a copied patch is written');

        $site->patchDirectory();
    }

    public function testAnEmptyValueIsRefused(): void
    {
        $site = $this->site(['drupal-patch-check' => ['patch-directory' => '   ']], []);

        $this->expectException(RuntimeException::class);

        $site->patchDirectory();
    }

    private function declaration(string $package): Declaration
    {
        return new Declaration($package, '3521733: Alter hook', 'patch/webform/mr940.diff', 'composer.json', true, []);
    }

    /**
     * A site on disk: drupal/webform from drupal.org, drupal/acme from a fork, and whatever the case writes beside them.
     *
     * @param array<string, mixed>                               $extra
     * @param array<string, mixed>                               $files documents by path, arrays written as JSON
     * @param ?Closure(string): array{status: int, body: string} $fetch
     */
    private function site(array $extra, array $files, string $manager = '2.0.0', ?Closure $fetch = null): Site
    {
        $this->root = \sys_get_temp_dir().'/drupatch-read-'.\bin2hex(\random_bytes(6));
        \mkdir($this->root.'/vendor/composer', 0o777, true);
        $this->write('composer.json', [
            'name' => 'site/site',
            'require' => ['drupal/webform' => '^6.2', 'drupal/acme' => '^1.0', 'cweagans/composer-patches' => '^'.$manager[0]],
            'extra' => $extra,
        ]);
        $this->write('composer.lock', ['packages' => [
            ['name' => 'drupal/webform', 'version' => '6.2.9', 'type' => 'drupal-module', 'notification-url' => 'https://packages.drupal.org/8/downloads'],
            ['name' => 'drupal/acme', 'version' => '6.2.9', 'type' => 'drupal-module', 'source' => ['type' => 'git', 'url' => 'https://github.com/acme/acme.git', 'reference' => 'f0e1d2c']],
            ['name' => 'cweagans/composer-patches', 'version' => $manager, 'type' => 'composer-plugin'],
        ], 'packages-dev' => []]);
        $this->write('vendor/composer/installed.json', ['packages' => [
            ['name' => 'drupal/webform', 'version' => '6.2.9', 'version_normalized' => '6.2.9.0', 'type' => 'drupal-module', 'require' => ['drupal/core' => '^10.3 || ^11']],
            ['name' => 'cweagans/composer-patches', 'version' => $manager, 'version_normalized' => \ltrim($manager, 'v').'.0', 'type' => 'composer-plugin'],
        ], 'dev' => false]);
        // Composer drops an installed package whose directory is gone.
        \mkdir($this->root.'/vendor/drupal/webform', 0o777, true);
        \mkdir($this->root.'/vendor/cweagans/composer-patches', 0o777, true);
        foreach ($files as $path => $body) {
            $this->write($path, $body);
        }

        return Site::at($this->composerOf($this->root), new NullIO(), $this->root.'/composer.json', $fetch);
    }

    /**
     * Composer reading this directory, whatever the process working directory is.
     */
    private function composerOf(string $root): Composer
    {
        return (new Factory())->createComposer(new NullIO(), $root.'/composer.json', true, $root);
    }

    private function write(string $path, mixed $body): void
    {
        $full = $this->root.'/'.$path;
        if (!\is_dir(\dirname($full))) {
            \mkdir(\dirname($full), 0o777, true);
        }
        \file_put_contents($full, \is_string($body) ? $body : (string) \json_encode($body, \JSON_PRETTY_PRINT));
    }
}

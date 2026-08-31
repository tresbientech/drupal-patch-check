<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Command;

use Composer\Composer;
use Composer\Factory;
use Composer\IO\NullIO;

/**
 * A site on disk the command can be run against: the two composer files,
 * an installed package the service can judge, and whatever patch and
 * conflict files a case needs.
 *
 * Composer reads the working directory and the COMPOSER variable, so the
 * fixture moves both and puts them back.
 */
final class SiteFixture
{
    public readonly string $root;

    private string $cwd = '';

    private string|false $composerEnv = false;

    /** @var list<array{string, string}> */
    private array $patches = [];

    public function __construct()
    {
        $this->root = \sys_get_temp_dir().'/drupatch-site-'.\bin2hex(\random_bytes(6));
        \mkdir($this->root.'/vendor/composer', 0o777, true);
    }

    /** Declares one patch on drupal/webform and writes its file. */
    public function declaresPatch(string $title, string $source, string $body = "diff --git a/x b/x\n--- a/x\n+++ b/x\n@@ -1 +1 @@\n-a\n+b\n"): self
    {
        $this->patches[] = [$title, $source];
        $this->write($source, $body);

        return $this;
    }

    public function write(string $path, string $body): self
    {
        $full = $this->root.'/'.$path;
        if (!\is_dir(\dirname($full))) {
            \mkdir(\dirname($full), 0o777, true);
        }
        \file_put_contents($full, $body);

        return $this;
    }

    public function has(string $path): bool
    {
        return \is_file($this->root.'/'.$path);
    }

    public function read(string $path): string
    {
        return (string) \file_get_contents($this->root.'/'.$path);
    }

    /** Writes the two composer files and enters the site. */
    public function enter(string $endpoint): Composer
    {
        $map = [];
        foreach ($this->patches as [$title, $source]) {
            $map[$title] = $source;
        }
        $this->write('composer.json', (string) \json_encode([
            'name' => 'site/site',
            'require' => ['drupal/webform' => '^6.2'],
            'extra' => [
                'patches' => ['drupal/webform' => $map],
                'drupatch' => ['endpoint' => $endpoint],
            ],
            // The plan server in the tests is plain HTTP on loopback.
            'config' => ['secure-http' => false],
        ], \JSON_PRETTY_PRINT));
        $this->write('composer.lock', (string) \json_encode([
            'packages' => [[
                'name' => 'drupal/webform',
                'version' => '6.2.9',
                'type' => 'drupal-module',
                'notification-url' => 'https://packages.drupal.org/8/downloads',
            ]],
            'packages-dev' => [],
        ], \JSON_PRETTY_PRINT));
        $this->write('vendor/composer/installed.json', (string) \json_encode([
            'packages' => [['name' => 'drupal/webform', 'version' => '6.2.9', 'version_normalized' => '6.2.9.0', 'type' => 'drupal-module']],
            'dev' => false,
        ]));

        $this->cwd = (string) \getcwd();
        $this->composerEnv = \getenv('COMPOSER');
        \chdir($this->root);
        \putenv('COMPOSER='.$this->root.'/composer.json');

        return Factory::create(new NullIO(), $this->root.'/composer.json', true);
    }

    public function leave(): void
    {
        if ('' !== $this->cwd) {
            \chdir($this->cwd);
        }
        if (false === $this->composerEnv) {
            \putenv('COMPOSER');
        } else {
            \putenv('COMPOSER='.$this->composerEnv);
        }
        self::remove($this->root);
    }

    private static function remove(string $dir): void
    {
        foreach ((array) \scandir($dir) as $entry) {
            if (!\is_string($entry) || '.' === $entry || '..' === $entry) {
                continue;
            }
            $full = $dir.'/'.$entry;
            \is_dir($full) ? self::remove($full) : @\unlink($full);
        }
        @\rmdir($dir);
    }
}

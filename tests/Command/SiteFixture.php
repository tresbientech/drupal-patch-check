<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Command;

use Composer\Composer;
use Composer\Factory;
use Composer\IO\NullIO;
use TresBienTech\Drupatch\Manager;
use TresBienTech\Drupatch\Tests\Scratch;

/**
 * A site on disk the command can be run against: the two composer files,
 * an installed package the service can judge, and whatever patch and
 * conflict files a case needs.
 *
 * Composer reads the COMPOSER variable, which the fixture sets and puts
 * back. The working directory is left alone: the site is named instead.
 */
class SiteFixture
{
    /** The drupal/core release the site has installed, empty for none. */
    private string $core = '';

    public readonly string $root;

    private string|false $composerEnv = false;

    private string|false $endpointEnv = false;

    /** @var list<array{string, string}> */
    private array $patches = [];

    /** @var list<array{string, string}> the files written again after the commit */
    private array $changed = [];

    /** @var list<string> files that get a trailing newline after the commit */
    private array $edited = [];

    /** Whether the site is a git checkout with everything committed. */
    private bool $committed = false;

    /** @var array<string, array<string, string>> the record a declaration carries, by title */
    private array $pinned = [];

    /** @var array<string, mixed> */
    private array $extra = [];

    /** The patch manager release the site has installed, empty for none. */
    private string $manager = '';

    /** @var array<string, array<string, string>> declarations on packages other than drupal/webform, by package then title */
    private array $others = [];

    /** Where the declarations go, empty for composer.json's extra.patches. */
    private string $patchesFile = '';

    /** Whether the declarations are written as a list of objects. */
    private bool $expanded = false;

    /** Whether drupal/webform is locked from a fork rather than from drupal.org. */
    private bool $forked = false;

    /** The core requirement the installed webform release declares, empty for none. */
    private string $declaredCore = '';

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

    /** Declares one patch on drupal/webform without writing a file for it. */
    public function declares(string $title, string $source): self
    {
        $this->patches[] = [$title, $source];

        return $this;
    }

    /** Declares one patch that already records where its bytes came from, which only the expanded shape can hold. */
    public function declaresPinned(string $title, string $source, string $mr, string $base): self
    {
        $this->declaresPatch($title, $source);
        $this->pinned[$title] = ['mr' => $mr, 'base' => $base];

        return $this->expanded();
    }

    /** Installs one release of the patch manager, which every version-dependent answer reads. */
    public function withManager(string $version): self
    {
        $this->manager = $version;

        return $this;
    }

    /** Locks drupal/webform from a fork, which carries the drupal name and no drupal.org notification URL. */
    public function installsFromAFork(): self
    {
        $this->forked = true;

        return $this;
    }

    /** Locks and installs drupal/core from Packagist, which is where composer takes core from. */
    public function installsCore(string $version): self
    {
        $this->core = $version;

        return $this;
    }

    /** Declares a core requirement on the installed webform release, which the vendor directory holds. */
    public function declaresCore(string $constraint): self
    {
        $this->declaredCore = $constraint;

        return $this;
    }

    /** Puts the declarations in a patches file rather than in composer.json, under the key the version reads. */
    public function inPatchesFile(string $path): self
    {
        $this->patchesFile = $path;

        return $this;
    }

    /** Writes the declarations as a list of objects, the expanded shape 2.x reads. */
    public function expanded(): self
    {
        $this->expanded = true;

        return $this;
    }

    /** Declares one patch on a package the lock does not hold, which the service is never asked about. */
    public function declaresOn(string $package, string $title, string $source): self
    {
        $this->others[$package][$title] = $source;

        return $this;
    }

    /** Adds one key under composer.json's extra. */
    public function withExtra(string $key, mixed $value): self
    {
        $this->extra[$key] = $value;

        return $this;
    }

    /** Makes the site a git checkout with every file committed, which is what the guard reads. */
    public function inGit(): self
    {
        $this->committed = true;

        return $this;
    }

    /** Adds a newline to this file once the site is committed, so git reports it changed and it still parses. */
    public function edits(string $path): self
    {
        $this->edited[] = $path;

        return $this;
    }

    /** Writes this file again once the site is committed, so git reports it changed. */
    public function leavesChanged(string $path, string $body): self
    {
        $this->changed[] = [$path, $body];

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

    /** Writes the two composer files and names the site for this run. */
    public function enter(string $endpoint): Composer
    {
        $extra = $this->extra;
        if ('' === $this->patchesFile) {
            $extra += ['patches' => ['drupal/webform' => $this->declarations()] + $this->others];
        } else {
            $extra += Manager::ofVersion($this->manager)->isOne()
                ? ['patches-file' => $this->patchesFile]
                : ['composer-patches' => ['patches-file' => $this->patchesFile]];
            $this->write($this->patchesFile, (string) \json_encode(['patches' => ['drupal/webform' => $this->declarations()] + $this->others], \JSON_PRETTY_PRINT));
        }
        $require = ['drupal/webform' => '^6.2'];
        if ('' !== $this->manager) {
            $require[Manager::PACKAGE] = '^'.Manager::ofVersion($this->manager)->line;
        }
        $this->write('composer.json', (string) \json_encode([
            'name' => 'site/site',
            'require' => $require,
            'extra' => $extra,
            // The plan server in the tests is plain HTTP on loopback.
            'config' => ['secure-http' => false],
        ], \JSON_PRETTY_PRINT));
        $locked = [$this->forked
            ? ['name' => 'drupal/webform', 'version' => '6.2.9', 'type' => 'drupal-module', 'source' => ['type' => 'git', 'url' => 'https://github.com/acme/webform.git', 'reference' => 'f0e1d2c']]
            : ['name' => 'drupal/webform', 'version' => '6.2.9', 'type' => 'drupal-module', 'notification-url' => 'https://packages.drupal.org/8/downloads'],
        ];
        $installed = [['name' => 'drupal/webform', 'version' => '6.2.9', 'version_normalized' => '6.2.9.0', 'type' => 'drupal-module']
            + ('' === $this->declaredCore ? [] : ['require' => ['drupal/core' => $this->declaredCore]])];
        if ('' !== $this->core) {
            $locked[] = ['name' => 'drupal/core', 'version' => $this->core, 'type' => 'drupal-core', 'notification-url' => 'https://packagist.org/downloads/'];
            $installed[] = ['name' => 'drupal/core', 'version' => $this->core, 'version_normalized' => $this->core.'.0', 'type' => 'drupal-core'];
        }
        if ('' !== $this->manager) {
            $locked[] = ['name' => Manager::PACKAGE, 'version' => $this->manager, 'type' => 'composer-plugin'];
            $installed[] = ['name' => Manager::PACKAGE, 'version' => $this->manager, 'version_normalized' => \ltrim($this->manager, 'v').'.0', 'type' => 'composer-plugin'];
        }
        $this->write('composer.lock', (string) \json_encode([
            'packages' => $locked,
            'packages-dev' => [],
        ], \JSON_PRETTY_PRINT));
        $this->write('vendor/composer/installed.json', (string) \json_encode([
            'packages' => $installed,
            'dev' => false,
        ]));
        // Composer drops an installed package whose directory is gone, so the
        // vendor directory holds one per lock entry.
        foreach ($installed as $package) {
            \mkdir($this->root.'/vendor/'.$package['name'], 0o777, true);
        }

        $this->commit();
        foreach ($this->changed as [$path, $body]) {
            $this->write($path, $body);
        }
        foreach ($this->edited as $path) {
            \file_put_contents($this->root.'/'.$path, "\n", \FILE_APPEND);
        }
        $this->composerEnv = \getenv('COMPOSER');
        $this->endpointEnv = \getenv('DRUPATCH_ENDPOINT');
        \putenv('COMPOSER='.$this->root.'/composer.json');
        \putenv('DRUPATCH_ENDPOINT='.$endpoint);

        // Composer reads the vendor directory from the working directory it
        // is given, so the site is named rather than entered.
        return (new Factory())->createComposer(new NullIO(), $this->root.'/composer.json', true, $this->root);
    }

    /**
     * Puts the whole site in git, so the guard reads a clean checkout rather than refusing every file.
     */
    private function commit(): void
    {
        if (!$this->committed) {
            return;
        }
        $git = 'git -C '.\escapeshellarg($this->root).' -c commit.gpgsign=false -c user.name=drupatch -c user.email=drupatch@example.invalid ';
        foreach (['init -q', 'add -Af', 'commit -q -m fixture'] as $step) {
            \exec($git.$step.' 2>&1');
        }
    }

    /**
     * The declarations of drupal/webform, in the shape this fixture writes.
     *
     * @return array<int|string, mixed>
     */
    private function declarations(): array
    {
        $out = [];
        foreach ($this->patches as [$title, $source]) {
            if ($this->expanded) {
                $entry = ['description' => $title, 'url' => $source];
                if (isset($this->pinned[$title])) {
                    $entry['extra'] = ['drupatch' => $this->pinned[$title]];
                }
                $out[] = $entry;
                continue;
            }
            $out[$title] = $source;
        }

        return $out;
    }

    public function leave(): void
    {
        if (false === $this->composerEnv) {
            \putenv('COMPOSER');
        } else {
            \putenv('COMPOSER='.$this->composerEnv);
        }
        if (false === $this->endpointEnv) {
            \putenv('DRUPATCH_ENDPOINT');
        } else {
            \putenv('DRUPATCH_ENDPOINT='.$this->endpointEnv);
        }
        Scratch::remove($this->root);
    }
}

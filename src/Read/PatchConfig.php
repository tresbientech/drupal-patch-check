<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Read;

use TresBienTech\Drupatch\Fetch\PatchText;
use TresBienTech\Drupatch\Manager;
use TresBienTech\Drupatch\Plugin;
use TresBienTech\Drupatch\Text;

/**
 * A site's patch declarations: the patches cweagans/composer-patches applies, the text of the local ones, and anything the reader could not read.
 */
class PatchConfig
{
    /** Largest number of patch texts sent in one call. */
    private const MAX_PATCH_FILES = 100;

    /**
     * @param list<Declaration>                                                           $patches
     * @param array<string, string>                                                       $files
     * @param list<string>                                                                $notes
     * @param list<array{package: string, title: string, reason: string}>                 $skipped one entry per declared patch the run did not judge
     * @param list<array{package: string, title: string, source: string, reason: string}> $unsent  one entry per patch whose text did not fit
     */
    public function __construct(
        public readonly array $patches,
        public readonly array $files,
        public readonly array $notes,
        public readonly array $skipped,
        public readonly array $unsent,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->patches;
    }

    /**
     * Reads what the site declares, from composer.json and from the patches file its manager reads.
     *
     * @param PatchText             $text       turns a declared source into the text that travels
     * @param int                   $textBudget bytes the patch text may occupy in the request
     * @param array<string, string> $checkable  packages the service can judge, to their versions
     * @param array<string, mixed>  $extra      the root package's extra
     */
    public static function read(PatchText $text, int $textBudget, array $checkable, array $extra, string $root, Manager $manager): self
    {
        $patches = [];
        $files = [];
        $unsent = [];
        $skipped = [];
        $spent = 0;
        foreach (self::declared($extra, $root, $manager) as $declaration) {
            $source = $declaration->source;
            if (!isset($checkable[$declaration->package])) {
                $skipped[] = ['package' => $declaration->package, 'title' => $declaration->title, 'reason' => 'not a drupal.org project'];
                continue;
            }
            if (isset($files[$source])) {
                $patches[] = $declaration;
                continue;
            }
            if (\count($files) >= self::MAX_PATCH_FILES) {
                $patches[] = $declaration;
                $unsent[] = self::withheld($declaration, 'more than '.self::MAX_PATCH_FILES.' patch texts');
                continue;
            }
            $read = $text->read($source);
            if ($read['withheld']) {
                $patches[] = $declaration;
                $unsent[] = self::withheld($declaration, $read['reason']);
                continue;
            }
            if ('' !== $read['reason']) {
                // 2.x ships `composer patches-doctor`, which reports an
                // unreachable source itself.
                $skipped[] = ['package' => $declaration->package, 'title' => $declaration->title, 'reason' => $manager->isTwo() ? '' : $read['reason']];
                continue;
            }
            // What the texts cost once escaped, which is what the request
            // is measured in.
            $cost = 0;
            foreach ($read['files'] as $body) {
                $cost += \strlen(\json_encode($body, \JSON_THROW_ON_ERROR));
            }
            $patches[] = $declaration;
            if ($spent + $cost > $textBudget) {
                $unsent[] = self::withheld($declaration, 'the request was full; narrow with --package to check it');
                continue;
            }
            $spent += $cost;
            $files += $read['files'];
        }
        $notes = isset($extra[Plugin::EXTRA]['private-paths'])
            ? [Text::t('extra.@key.private-paths is no longer read: no path of your own leaves the site', ['@key' => Plugin::EXTRA])]
            : [];

        return new self($patches, $files, $notes, $skipped, $unsent);
    }

    /**
     * Every patch the site declares, whatever package it is on, from both places the patch manager reads: `extra.patches` in composer.json, then the file the patches-file key names. `read()` narrows this to what the service can judge; a pin run acts on all of it.
     *
     * @param array<string, mixed> $extra the root package's extra
     *
     * @return list<Declaration>
     */
    public static function declared(array $extra, string $root, Manager $manager): array
    {
        // A site writes both of these by hand, so anything can be in them.
        $out = self::entries($extra['patches'] ?? null, Declaration::COMPOSER_JSON);
        $file = $manager->patchesFile($extra);
        if ('' === $file) {
            return $out;
        }
        $text = @\file_get_contents($root.\DIRECTORY_SEPARATOR.$file);
        $decoded = false === $text ? null : \json_decode($text, true);

        // The patch manager reads composer.json first and refuses a patch
        // whose URL its package already holds, so a source declared in both
        // places applies once and has to be judged once.
        $held = [];
        foreach ($out as $declaration) {
            $held[$declaration->package][$declaration->source] = true;
        }
        foreach (self::entries(\is_array($decoded) ? ($decoded['patches'] ?? null) : null, $file) as $declaration) {
            if (!isset($held[$declaration->package][$declaration->source])) {
                $held[$declaration->package][$declaration->source] = true;
                $out[] = $declaration;
            }
        }

        return $out;
    }

    /**
     * What the service is told about one patch the site has yet to declare: the patches this config holds for its package, then the new one.
     *
     * The manager applies the patches a site declares for a package before a
     * new one, so the service judges the new patch behind them. A patch whose
     * text did not travel cannot be applied there either.
     *
     * @param string $text the new patch as it will travel
     */
    public function adding(Declaration $declaration, string $text): self
    {
        $before = [];
        $files = [];
        foreach ($this->patches as $held) {
            if ($held->package === $declaration->package && '' !== ($this->files[$held->source] ?? '')) {
                $before[] = $held;
                $files[$held->source] = $this->files[$held->source];
            }
        }
        $files[$declaration->source] = $text;

        return new self([...$before, $declaration], $files, [], [], []);
    }

    /**
     * The declarations one document's map holds, in the order the site wrote them.
     *
     * @return list<Declaration>
     */
    private static function entries(mixed $map, string $document): array
    {
        $out = [];
        foreach (\is_array($map) ? $map : [] as $package => $entries) {
            foreach (\is_string($package) && \is_array($entries) ? $entries : [] as $key => $entry) {
                $declaration = Declaration::of($package, $key, $entry, $document);
                if (null !== $declaration && '' !== $declaration->source) {
                    $out[] = $declaration;
                }
            }
        }

        return $out;
    }

    /**
     * One patch the run declined to send the text of.
     *
     * @return array{package: string, title: string, source: string, reason: string}
     */
    private static function withheld(Declaration $declaration, string $reason): array
    {
        return ['package' => $declaration->package, 'title' => $declaration->title, 'source' => $declaration->source, 'reason' => $reason];
    }
}

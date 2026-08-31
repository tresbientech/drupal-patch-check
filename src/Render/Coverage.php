<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Render;

use Tresbien\Drupatch\Site;

/**
 * What a run actually checked.
 *
 * A run that judged nothing looks like a run where everything was fine.
 * The difference matters most in CI, where nobody reads a green job, so
 * every run says how many patches it covered and how many it held back.
 */
final class Coverage
{
    /**
     * @param list<string> $heldBackPatches
     */
    public function __construct(
        public readonly int $patches,
        public readonly array $heldBackPatches,
    ) {
    }

    /**
     * What a run covered, narrowed to the packages it was asked about.
     *
     * A run scoped with --package is answering for those packages, so a
     * patch held back on another one is not part of the answer.
     *
     * @param list<string> $packages composer names or short names, empty for the whole site
     */
    public static function of(Site $site, array $packages = []): self
    {
        $resolution = $site->patches();
        if ([] === $packages) {
            return new self(\count($resolution->patches), $resolution->heldBack);
        }

        $wanted = [];
        foreach ($packages as $name) {
            $wanted[self::shortName($name)] = true;
        }
        $patches = 0;
        foreach ($resolution->patches as $patch) {
            if (isset($wanted[self::shortName($patch['package'])])) {
                ++$patches;
            }
        }
        $heldBack = [];
        foreach ($resolution->heldBack as $line) {
            if (isset($wanted[self::shortName(\explode(' ', $line)[0])])) {
                $heldBack[] = $line;
            }
        }

        return new self($patches, $heldBack);
    }

    /**
     * A package named the way --package accepts it: either spelling of
     * drupal/webform reduces to the same key.
     */
    private static function shortName(string $package): string
    {
        $slash = \strrpos($package, '/');

        return false === $slash ? $package : \substr($package, $slash + 1);
    }

    /**
     * Whether patches were declared and none of them could be checked. A
     * run like that proves nothing, and it is the one case a green exit
     * would misrepresent.
     */
    public function isVacuous(): bool
    {
        return 0 === $this->patches && [] !== $this->heldBackPatches;
    }

    /**
     * The lines a person reads before the table: what was covered, then
     * each patch that was not.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        if ($this->isVacuous()) {
            return ['drupatch: no patch could be checked. Every declared patch is on a package the service has no release for.'];
        }

        $line = \sprintf(
            'drupatch: checked %d patch%s',
            $this->patches,
            1 === $this->patches ? '' : 'es',
        );
        if ([] !== $this->heldBackPatches) {
            $line .= \sprintf('; held back %d', \count($this->heldBackPatches));
        }

        $out = [$line];
        foreach ($this->heldBackPatches as $patch) {
            $out[] = '  held back  '.$patch;
        }

        return $out;
    }
}

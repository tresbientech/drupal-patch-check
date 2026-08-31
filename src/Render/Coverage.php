<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Render;

use Tresbien\Drupatch\Site;

/**
 * What a run actually checked.
 *
 * A run that judged nothing looks like a run where everything was fine.
 * The difference matters most in CI, where nobody reads a green job, so
 * every run says how much of the site it covered and how much it held
 * back.
 */
final class Coverage
{
    /**
     * @param list<string> $heldBackPackages
     * @param list<string> $heldBackPatches
     */
    public function __construct(
        public readonly int $packages,
        public readonly int $patches,
        public readonly array $heldBackPackages,
        public readonly array $heldBackPatches,
    ) {
    }

    public static function of(Site $site): self
    {
        return new self(
            \count($site->checkable()),
            \count($site->patches()->patches),
            $site->heldBack(),
            $site->patches()->heldBack,
        );
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
     * each package and patch that was not.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        if (0 === $this->packages) {
            return ['drupatch: nothing was checked. No installed package comes from drupal.org, so the service has no release to judge against.'];
        }

        $line = \sprintf(
            'drupatch: checked %d package%s and %d patch%s',
            $this->packages,
            1 === $this->packages ? '' : 's',
            $this->patches,
            1 === $this->patches ? '' : 'es',
        );
        $held = \count($this->heldBackPackages) + \count($this->heldBackPatches);
        if ($held > 0) {
            $line .= \sprintf('; held back %d', $held);
        }

        $out = [$line];
        foreach ($this->heldBackPackages as $package) {
            $out[] = '  held back  '.$package.' (not a drupal.org release)';
        }
        foreach ($this->heldBackPatches as $patch) {
            $out[] = '  held back  '.$patch;
        }

        return $out;
    }
}

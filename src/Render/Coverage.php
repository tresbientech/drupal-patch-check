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

    public static function of(Site $site): self
    {
        return new self(
            \count($site->patches()->patches),
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

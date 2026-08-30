<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Write;

use Tresbien\Drupatch\Plan\PatchRow;
use Tresbien\Drupatch\Plan\Reroll;

/**
 * One re-rolled patch this run wrote, and what it is worth.
 */
final class WrittenFile
{
    public function __construct(
        public readonly string $path,
        public readonly string $status,
        public readonly string $package,
        public readonly string $title,
        public readonly bool $verified,
    ) {
    }

    public static function of(PatchRow $row, Reroll $reroll, string $path): self
    {
        return new self($path, $reroll->status, $row->package, $row->title, $reroll->verified);
    }

    /**
     * A usable patch file. A conflicted one is never named by a patch
     * config, so nothing may treat it as usable.
     */
    public function isUsable(): bool
    {
        return Reroll::CLEAN === $this->status;
    }

    /**
     * The declaration this file replaces.
     */
    public function key(): string
    {
        return $this->package."\0".$this->title;
    }
}

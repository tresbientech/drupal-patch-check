<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Write;

/**
 * One change a fix makes to a site's patch declarations.
 */
final class Change
{
    public const DROPPED = 'dropped';

    public const REPOINTED = 'repointed';

    public function __construct(
        public readonly string $action,
        public readonly string $package,
        public readonly string $title,
        public readonly string $path,
    ) {
    }

    public function key(): string
    {
        return $this->package."\0".$this->title;
    }

    public function isDrop(): bool
    {
        return self::DROPPED === $this->action;
    }

    /**
     * The line the report prints for this change.
     */
    public function line(): string
    {
        return $this->isDrop()
            ? \sprintf('    - %s: %s (the release carries it)', $this->package, $this->title)
            : \sprintf('    ~ %s: %s → %s', $this->package, $this->title, $this->path);
    }
}

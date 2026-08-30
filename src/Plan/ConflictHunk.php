<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Plan;

/**
 * One undecided region: what the release has, and what the patch wanted.
 */
final class ConflictHunk
{
    private function __construct(
        public readonly int $line,
        public readonly int $releaseLine,
        public readonly string $release,
        public readonly string $patch,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Value::int($data, 'line'),
            Value::int($data, 'release_line'),
            Value::str($data, 'release'),
            Value::str($data, 'patch'),
        );
    }

    /**
     * Where the region starts in the release's file: the release's own
     * line when the server gave one, the patch's otherwise.
     */
    public function at(): int
    {
        return $this->releaseLine > 0 ? $this->releaseLine : $this->line;
    }
}

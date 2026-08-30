<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Plan;

/**
 * One file a re-roll could not merge, with the regions it left open.
 */
final class Conflict
{
    /**
     * @param list<ConflictHunk> $hunks
     */
    private function __construct(
        public readonly string $file,
        public readonly int $regions,
        public readonly array $hunks,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $hunks = [];
        foreach (Value::objects($data, 'hunks') as $hunk) {
            $hunks[] = ConflictHunk::fromArray($hunk);
        }

        return new self(Value::str($data, 'file'), Value::int($data, 'regions'), $hunks);
    }
}

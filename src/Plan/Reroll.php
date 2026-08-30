<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Plan;

/**
 * The server's re-roll of one patch onto the release it was judged
 * against.
 */
final class Reroll
{
    public const CLEAN = 'clean';

    public const CONFLICTS = 'conflicts';

    /**
     * @param list<Conflict> $conflicts
     */
    private function __construct(
        public readonly string $status,
        public readonly string $patch,
        public readonly bool $verified,
        public readonly string $verifiedBy,
        public readonly array $conflicts,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $conflicts = [];
        foreach (Value::objects($data, 'conflicts') as $conflict) {
            $conflicts[] = Conflict::fromArray($conflict);
        }

        return new self(
            Value::str($data, 'status'),
            Value::str($data, 'patch'),
            Value::bool($data, 'verified'),
            Value::str($data, 'verified_by'),
            $conflicts,
        );
    }

    /**
     * A clean merge is a patch file the site can use. Anything else is
     * work for a person.
     */
    public function isClean(): bool
    {
        return self::CLEAN === $this->status && '' !== $this->patch;
    }

    public function hasConflicts(): bool
    {
        return self::CONFLICTS === $this->status;
    }
}

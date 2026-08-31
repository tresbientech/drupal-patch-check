<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Render;

use UnexpectedValueException;

/**
 * Which shape a run prints its answer in.
 *
 * The json flag shipped before the option did, so both spellings reach
 * here and one answer comes out. Two options asking for different shapes
 * is refused rather than resolved by precedence, because a run that
 * silently ignored half of what it was told would be worse than one that
 * stopped.
 */
final class Format
{
    public const TABLE = 'table';

    public const JSON = 'json';

    /**
     * Workflow commands a runner turns into annotations. Named after what
     * reads it: the lines are a runner's own protocol, not a file format.
     */
    public const GITHUB = 'github';

    /** Every accepted value, in the order the help text lists them. */
    private const ACCEPTED = [self::TABLE, self::JSON, self::GITHUB];

    /**
     * The format a run prints in.
     *
     * @param string|null $format the --format option, null when unset
     * @param bool        $json   whether --json was passed
     */
    public static function of(?string $format, bool $json): string
    {
        if (null === $format) {
            return $json ? self::JSON : self::TABLE;
        }
        if (!\in_array($format, self::ACCEPTED, true)) {
            throw new UnexpectedValueException(\sprintf('unknown --format=%s; accepted: %s', $format, \implode(', ', self::ACCEPTED)));
        }
        if ($json && self::JSON !== $format) {
            throw new UnexpectedValueException(\sprintf('--json and --format=%s ask for different output; pass one', $format));
        }

        return $format;
    }

    /**
     * The accepted values, for the option's own description.
     *
     * @return list<string>
     */
    public static function accepted(): array
    {
        return self::ACCEPTED;
    }
}

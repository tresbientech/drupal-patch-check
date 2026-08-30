<?php

declare(strict_types=1);

namespace Tresbien\Drupatch;

use Tresbien\Drupatch\Plan\Plan;

/**
 * What a plan means for the process that asked for it.
 */
final class Outcome
{
    public const CLEAN = 0;

    public const ACTION_NEEDED = 1;

    public const FAILED = 2;

    /**
     * A plan needs action when any patch is not one of the two clean
     * verdicts, or when a package has no release for the target and
     * blocks the upgrade. The clean verdicts are the allowlist: a verdict
     * the server adds later reads as work, never as fine.
     */
    public static function of(Plan $plan): int
    {
        if ([] !== $plan->needingAction() || $plan->isBlocked()) {
            return self::ACTION_NEEDED;
        }

        return self::CLEAN;
    }
}

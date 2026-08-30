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
     * A plan fails when a patch will not apply, or carries a verdict this
     * plugin does not know. The known-good list is the allowlist, so a
     * verdict the server adds later reads as work rather than as fine.
     *
     * A verdict the service could not reach, and a package with no
     * release for the target, are reported without failing: a lagging
     * mirror is not something the repository can fix, and a scheduled job
     * that woke for one would soon be ignored. Strict adds both, for a
     * run that would rather be woken than miss a finding.
     */
    public static function of(Plan $plan, bool $strict = false): int
    {
        if ($strict && $plan->isBlocked()) {
            return self::ACTION_NEEDED;
        }
        foreach ($plan->patches as $row) {
            if ($row->fails($strict)) {
                return self::ACTION_NEEDED;
            }
        }

        return self::CLEAN;
    }
}

<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Plan;

use RuntimeException;

/**
 * The server answered with something that is not a plan.
 */
final class InvalidPlan extends RuntimeException
{
}

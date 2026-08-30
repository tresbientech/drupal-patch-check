<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Tests;

use PHPUnit\Framework\TestCase;
use Tresbien\Drupatch\Outcome;

final class OutcomeTest extends TestCase
{
    use PlanFactory;

    public function testEveryPatchApplyingOrShippedIsClean(): void
    {
        $plan = $this->planFrom(['patches' => [
            $this->row(['verdict' => 'still-needed']),
            $this->row(['verdict' => 'shipped']),
        ]]);

        self::assertSame(Outcome::CLEAN, Outcome::of($plan));
    }

    public function testAPatchThatNoLongerAppliesNeedsAction(): void
    {
        $plan = $this->planFrom(['patches' => [$this->row(['verdict' => 'needs-reroll'])]]);

        self::assertSame(Outcome::ACTION_NEEDED, Outcome::of($plan));
    }

    public function testAPatchWithoutAVerdictNeedsAction(): void
    {
        $plan = $this->planFrom(['patches' => [$this->row(['verdict' => 'unknown'])]]);

        self::assertSame(Outcome::ACTION_NEEDED, Outcome::of($plan));
    }

    public function testAVerdictTheServerAddedLaterNeedsAction(): void
    {
        $plan = $this->planFrom(['patches' => [$this->row(['verdict' => 'quarantined'])]]);

        self::assertSame(Outcome::ACTION_NEEDED, Outcome::of($plan), 'only the known-clean verdicts may exit 0');
    }

    public function testAPackageBlockingTheUpgradeNeedsActionOnItsOwn(): void
    {
        self::assertSame(Outcome::ACTION_NEEDED, Outcome::of($this->planFrom(['no_release' => ['drupal/domain']])));
    }

    public function testASiteWithNothingToSayIsClean(): void
    {
        self::assertSame(Outcome::CLEAN, Outcome::of($this->planFrom()));
    }
}

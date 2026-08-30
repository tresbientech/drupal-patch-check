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

    // A patch the service could not judge is as often a mirror that lags
    // a release as a real problem, and neither is the repository's to fix.
    public function testAPatchThatCouldNotBeJudgedDoesNotFailByItself(): void
    {
        $plan = $this->planFrom(['patches' => [$this->row(['verdict' => 'unknown'])]]);

        self::assertSame(Outcome::CLEAN, Outcome::of($plan));
        self::assertSame(Outcome::ACTION_NEEDED, Outcome::of($plan, true), 'strict asked to be woken by it');
    }

    public function testAPatchThatWillNotApplyFailsEitherWay(): void
    {
        $plan = $this->planFrom(['patches' => [$this->row(['verdict' => 'needs-reroll'])]]);

        self::assertSame(Outcome::ACTION_NEEDED, Outcome::of($plan));
        self::assertSame(Outcome::ACTION_NEEDED, Outcome::of($plan, true));
    }

    public function testAVerdictTheServerAddedLaterFailsEitherWay(): void
    {
        $plan = $this->planFrom(['patches' => [$this->row(['verdict' => 'quarantined'])]]);

        self::assertSame(Outcome::ACTION_NEEDED, Outcome::of($plan), 'only the known verdicts may exit 0');
        self::assertSame(Outcome::ACTION_NEEDED, Outcome::of($plan, true));
    }

    // A package with no release is a finding, and not a patch that broke.
    public function testABlockedPackageOnlyFailsAStrictRun(): void
    {
        $plan = $this->planFrom(['no_release' => ['drupal/domain']]);

        self::assertSame(Outcome::CLEAN, Outcome::of($plan));
        self::assertSame(Outcome::ACTION_NEEDED, Outcome::of($plan, true));
    }

    public function testEverythingTolerableTogetherIsStillClean(): void
    {
        $plan = $this->planFrom([
            'no_release' => ['drupal/domain'],
            'patches' => [
                $this->row(['verdict' => 'still-needed']),
                $this->row(['verdict' => 'shipped']),
                $this->row(['verdict' => 'unknown']),
            ],
        ]);

        self::assertSame(Outcome::CLEAN, Outcome::of($plan));
        self::assertSame(Outcome::ACTION_NEEDED, Outcome::of($plan, true));
    }

    // The scope decides what the exit code is about.
    public function testNarrowingDecidesWhatTheExitCodeIsAbout(): void
    {
        $plan = $this->planFrom(['patches' => [
            $this->row(['package' => 'drupal/token', 'verdict' => 'still-needed']),
            $this->row(['package' => 'drupal/webform', 'verdict' => 'needs-reroll']),
        ]]);

        self::assertSame(Outcome::ACTION_NEEDED, Outcome::of($plan));
        self::assertSame(Outcome::CLEAN, Outcome::of($plan->onlyPackages(['token'])));
        self::assertSame(Outcome::ACTION_NEEDED, Outcome::of($plan->onlyPackages(['webform'])));
    }

    public function testASiteWithNothingToSayIsClean(): void
    {
        self::assertSame(Outcome::CLEAN, Outcome::of($this->planFrom()));
    }
}

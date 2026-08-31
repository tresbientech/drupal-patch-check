<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Tests\Resolve;

use PHPUnit\Framework\TestCase;
use Tresbien\Drupatch\Resolve\Answer;
use Tresbien\Drupatch\Resolve\Eligibility;

final class EligibilityTest extends TestCase
{
    public function testAReleaseThatSupportsTheTargetAndRunsIsKept(): void
    {
        $eligibility = Eligibility::of(Answer::Yes, Answer::Yes);

        self::assertTrue($eligibility->keep);
        self::assertSame('', $eligibility->reason);
    }

    public function testAReleaseThatSaysItDoesNotSupportTheTargetIsLeftOutWithoutAReason(): void
    {
        $eligibility = Eligibility::of(Answer::No, Answer::Yes);

        self::assertFalse($eligibility->keep);
        self::assertSame('', $eligibility->reason, 'a release that answered no needs no explaining');
    }

    public function testAReleaseTheSitePhpCannotRunIsLeftOutWithoutAReason(): void
    {
        $eligibility = Eligibility::of(Answer::Yes, Answer::No);

        self::assertFalse($eligibility->keep);
        self::assertSame('', $eligibility->reason);
    }

    public function testAnUnreadCoreRequirementIsNotANo(): void
    {
        $eligibility = Eligibility::of(Answer::Unread, Answer::Yes);

        self::assertFalse($eligibility->keep);
        self::assertSame('its drupal/core requirement could not be read', $eligibility->reason);
    }

    public function testAnUnreadPhpRequirementIsNotAYes(): void
    {
        $eligibility = Eligibility::of(Answer::Yes, Answer::Unread);

        self::assertFalse($eligibility->keep, 'the check that used to default to true no longer decides');
        self::assertSame('its php requirement could not be read', $eligibility->reason);
    }

    public function testTheCoreReasonIsGivenWhenNeitherCouldBeRead(): void
    {
        self::assertSame(
            'its drupal/core requirement could not be read',
            Eligibility::of(Answer::Unread, Answer::Unread)->reason,
        );
    }

    public function testAnUnreadRequirementIsReportedEvenWhenTheOtherSaidNo(): void
    {
        $eligibility = Eligibility::of(Answer::Unread, Answer::No);

        self::assertFalse($eligibility->keep);
        self::assertSame('its drupal/core requirement could not be read', $eligibility->reason);
    }

    public function testAnswerCarriesABoolean(): void
    {
        self::assertSame(Answer::Yes, Answer::of(true));
        self::assertSame(Answer::No, Answer::of(false));
    }
}

<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Render;

use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\CheckCommand;
use UnexpectedValueException;

final class FormatTest extends TestCase
{
    public function testARunWithNeitherOptionPrintsTheTable(): void
    {
        self::assertSame('table', CheckCommand::format(null, false));
    }

    public function testTheJsonFlagStillChoosesJson(): void
    {
        self::assertSame('json', CheckCommand::format(null, true));
    }

    public function testTheFormatOptionChoosesJson(): void
    {
        self::assertSame('json', CheckCommand::format('json', false));
    }

    public function testTheFormatOptionChoosesTheTable(): void
    {
        self::assertSame('table', CheckCommand::format('table', false));
    }

    public function testTheFlagAndAMatchingFormatAgree(): void
    {
        self::assertSame('json', CheckCommand::format('json', true));
    }

    public function testTheFormatOptionWinsOverTheFlag(): void
    {
        self::assertSame('table', CheckCommand::format('table', true));
    }

    public function testAnUnknownFormatNamesWhatIsAccepted(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('unknown --format=xml; accepted: table, json, github');

        CheckCommand::format('xml', false);
    }

    public function testTheFormatOptionChoosesTheAnnotations(): void
    {
        self::assertSame('github', CheckCommand::format('github', false));
    }

    public function testTheFormatOptionWinsOverTheFlagForAnnotations(): void
    {
        self::assertSame('github', CheckCommand::format('github', true));
    }

    public function testAnEmptyFormatIsAnError(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('unknown --format=; accepted: table, json, github');

        CheckCommand::format('', false);
    }
}

<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Render;

use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Command\CheckCommand;
use UnexpectedValueException;

final class FormatTest extends TestCase
{
    public function testARunWithNoFormatPrintsTheTable(): void
    {
        self::assertSame('table', CheckCommand::format(null));
    }

    public function testTheFormatOptionChoosesJson(): void
    {
        self::assertSame('json', CheckCommand::format('json'));
    }

    public function testTheFormatOptionChoosesTheTable(): void
    {
        self::assertSame('table', CheckCommand::format('table'));
    }

    public function testAnUnknownFormatNamesWhatIsAccepted(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('unknown --format=xml; accepted: table, json');

        CheckCommand::format('xml');
    }

    public function testTheAnnotationFormatIsGone(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('unknown --format=github; accepted: table, json');

        CheckCommand::format('github');
    }

    public function testAnEmptyFormatIsAnError(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('unknown --format=; accepted: table, json');

        CheckCommand::format('');
    }
}

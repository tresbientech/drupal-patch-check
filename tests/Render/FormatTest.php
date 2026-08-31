<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Tests\Render;

use PHPUnit\Framework\TestCase;
use Tresbien\Drupatch\Render\Format;
use UnexpectedValueException;

final class FormatTest extends TestCase
{
    public function testARunWithNeitherOptionPrintsTheTable(): void
    {
        self::assertSame(Format::TABLE, Format::of(null, false));
    }

    public function testTheJsonFlagStillChoosesJson(): void
    {
        self::assertSame(Format::JSON, Format::of(null, true));
    }

    public function testTheFormatOptionChoosesJson(): void
    {
        self::assertSame(Format::JSON, Format::of('json', false));
    }

    public function testTheFormatOptionChoosesTheTable(): void
    {
        self::assertSame(Format::TABLE, Format::of('table', false));
    }

    public function testTheFlagAndAMatchingFormatAgree(): void
    {
        self::assertSame(Format::JSON, Format::of('json', true));
    }

    public function testTheFlagAndADifferentFormatIsAnError(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('--json and --format=table ask for different output; pass one');

        Format::of('table', true);
    }

    public function testAnUnknownFormatNamesWhatIsAccepted(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('unknown --format=xml; accepted: table, json, github');

        Format::of('xml', false);
    }

    public function testTheFormatOptionChoosesTheAnnotations(): void
    {
        self::assertSame(Format::GITHUB, Format::of('github', false));
    }

    public function testTheFlagAndTheAnnotationsIsAnError(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('--json and --format=github ask for different output; pass one');

        Format::of('github', true);
    }

    public function testAnEmptyFormatIsAnError(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('unknown --format=; accepted: table, json, github');

        Format::of('', false);
    }
}

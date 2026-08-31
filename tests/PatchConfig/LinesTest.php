<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Tests\PatchConfig;

use PHPUnit\Framework\TestCase;
use Tresbien\Drupatch\PatchConfig\Lines;

final class LinesTest extends TestCase
{
    private const DOCUMENT = <<<'JSON'
        {
            "extra": {
                "patches": {
                    "drupal/webform": {
                        "Style fix": "patchs/webform-style.patch",
                        "Update intl-tel-input": "https://www.drupal.org/files/issues/2026-01-01/webform-intl.patch"
                    },
                    "drupal/token": {
                        "Avoid rematching": "patchs/token.patch"
                    }
                }
            }
        }
        JSON;

    public function testFindsTheLineDeclaringALocalPath(): void
    {
        self::assertSame(5, Lines::in(self::DOCUMENT)->of('patchs/webform-style.patch'));
    }

    public function testFindsTheLineDeclaringAUrl(): void
    {
        self::assertSame(6, Lines::in(self::DOCUMENT)->of('https://www.drupal.org/files/issues/2026-01-01/webform-intl.patch'));
    }

    public function testFindsALaterDeclaration(): void
    {
        self::assertSame(9, Lines::in(self::DOCUMENT)->of('patchs/token.patch'));
    }

    public function testASourceThatIsNotThereAnchorsToTheFirstLine(): void
    {
        self::assertSame(1, Lines::in(self::DOCUMENT)->of('patchs/nothing.patch'));
    }

    public function testAnEmptySourceAnchorsToTheFirstLine(): void
    {
        self::assertSame(1, Lines::in(self::DOCUMENT)->of(''));
    }

    public function testASourceDeclaredTwiceAnchorsToItsFirstOccurrence(): void
    {
        $document = "{\n  \"a\": \"patchs/x.patch\",\n  \"b\": \"patchs/x.patch\"\n}";

        self::assertSame(2, Lines::in($document)->of('patchs/x.patch'));
    }

    public function testAnEmptyDocumentAnchorsToTheFirstLine(): void
    {
        self::assertSame(1, Lines::in('')->of('patchs/x.patch'));
    }

    public function testAnExternalPatchesFileIsJustAnotherDocument(): void
    {
        $document = "{\n  \"patches\": {\n    \"drupal/webform\": {\n      \"Style fix\": \"patchs/webform-style.patch\"\n    }\n  }\n}";

        self::assertSame(4, Lines::in($document)->of('patchs/webform-style.patch'));
    }

    public function testAWindowsDocumentCountsTheSameLines(): void
    {
        $document = "{\r\n  \"a\": \"patchs/x.patch\"\r\n}";

        self::assertSame(2, Lines::in($document)->of('patchs/x.patch'));
    }
}

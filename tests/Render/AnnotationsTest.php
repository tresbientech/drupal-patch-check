<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Tests\Render;

use PHPUnit\Framework\TestCase;
use Tresbien\Drupatch\PatchConfig\Lines;
use Tresbien\Drupatch\Render\Annotations;
use Tresbien\Drupatch\Tests\PlanFactory;

final class AnnotationsTest extends TestCase
{
    use PlanFactory;

    private const DOCUMENT = <<<'JSON'
        {
            "extra": {
                "patches": {
                    "drupal/webform": {
                        "Style fix": "patchs/webform-style.patch"
                    },
                    "drupal/memcache": {
                        "Transaction-aware backend": "https://www.drupal.org/files/issues/memcache.patch"
                    }
                }
            }
        }
        JSON;

    public function testARowNeedingARerollIsAnError(): void
    {
        $plan = $this->planFrom(['counts' => ['needs-reroll' => 1], 'patches' => [$this->row([
            'verdict' => 'needs-reroll', 'title' => 'Style fix', 'source' => 'patchs/webform-style.patch',
        ])]]);

        self::assertSame(
            ['::error file=composer.json,line=5::needs-reroll drupal/webform 6.2.9: Style fix'],
            $this->lines($plan),
        );
    }

    public function testAnUnclearRowIsAWarning(): void
    {
        $plan = $this->planFrom(['counts' => ['unknown' => 1], 'patches' => [$this->row([
            'verdict' => 'unknown', 'title' => 'Style fix', 'source' => 'patchs/webform-style.patch',
        ])]]);

        self::assertStringStartsWith('::warning file=composer.json,line=5::unknown drupal/webform 6.2.9: Style fix', $this->lines($plan)[0]);
    }

    public function testAShippedRowIsANotice(): void
    {
        $plan = $this->planFrom(['counts' => ['shipped' => 1], 'patches' => [$this->row([
            'verdict' => 'shipped', 'title' => 'Style fix', 'source' => 'patchs/webform-style.patch',
        ])]]);

        self::assertStringStartsWith('::notice file=composer.json,line=5::shipped drupal/webform 6.2.9: Style fix', $this->lines($plan)[0]);
    }

    public function testAPatchThatStillAppliesGetsNoAnnotation(): void
    {
        $plan = $this->planFrom(['counts' => ['still-needed' => 1], 'patches' => [$this->row([
            'verdict' => 'still-needed', 'title' => 'Style fix', 'source' => 'patchs/webform-style.patch',
        ])]]);

        self::assertSame([], $this->lines($plan));
    }

    public function testAPatchDeclaredByUrlIsAnchoredToo(): void
    {
        $plan = $this->planFrom(['counts' => ['needs-reroll' => 1], 'patches' => [$this->row([
            'package' => 'drupal/memcache', 'project' => 'memcache', 'version' => '2.8.0',
            'verdict' => 'needs-reroll', 'title' => 'Transaction-aware backend',
            'source' => 'https://www.drupal.org/files/issues/memcache.patch',
        ])]]);

        self::assertSame(
            ['::error file=composer.json,line=8::needs-reroll drupal/memcache 2.8.0: Transaction-aware backend'],
            $this->lines($plan),
        );
    }

    public function testAnExternalPatchesFileIsNamedInstead(): void
    {
        $plan = $this->planFrom(['counts' => ['needs-reroll' => 1], 'patches' => [$this->row([
            'verdict' => 'needs-reroll', 'title' => 'Style fix', 'source' => 'patchs/webform-style.patch',
        ])]]);

        $document = "{\n  \"patches\": {\n    \"drupal/webform\": {\n      \"Style fix\": \"patchs/webform-style.patch\"\n";

        self::assertSame(
            ['::error file=patches.json,line=4::needs-reroll drupal/webform 6.2.9: Style fix'],
            Annotations::lines($plan, 'patches.json', Lines::in($document)),
        );
    }

    public function testASourceThatIsNotInTheDocumentAnchorsToTheFirstLine(): void
    {
        $plan = $this->planFrom(['counts' => ['needs-reroll' => 1], 'patches' => [$this->row([
            'verdict' => 'needs-reroll', 'title' => 'Gone', 'source' => 'patchs/gone.patch',
        ])]]);

        self::assertStringStartsWith('::error file=composer.json,line=1::', $this->lines($plan)[0]);
    }

    public function testTheMessageNamesWhatARerollMustFix(): void
    {
        $plan = $this->planFrom(['counts' => ['needs-reroll' => 1], 'patches' => [$this->row([
            'verdict' => 'needs-reroll', 'title' => 'Style fix', 'source' => 'patchs/webform-style.patch',
            'result' => ['hunks_failed' => [['file' => 'src/Element/Webform.php', 'reason' => 'context differs']]],
        ])]]);

        self::assertSame(
            ['::error file=composer.json,line=5::needs-reroll drupal/webform 6.2.9: Style fix; src/Element/Webform.php: context differs'],
            $this->lines($plan),
        );
    }

    public function testOneLinePerAnnotatedRow(): void
    {
        $plan = $this->planFrom([
            'counts' => ['needs-reroll' => 1, 'shipped' => 1, 'still-needed' => 1],
            'patches' => [
                $this->row(['verdict' => 'needs-reroll', 'title' => 'Style fix', 'source' => 'patchs/webform-style.patch']),
                $this->row(['verdict' => 'shipped', 'title' => 'Style fix', 'source' => 'patchs/webform-style.patch']),
                $this->row(['verdict' => 'still-needed', 'title' => 'Style fix', 'source' => 'patchs/webform-style.patch']),
            ],
        ]);

        self::assertCount(2, $this->lines($plan));
    }

    public function testATitleCarryingAColonAndCommasSurvivesIntact(): void
    {
        $title = 'CORPUPLIFT-1703: Geodis .htaccess customizations (IP bans, HTTPS, redirects)';
        $plan = $this->planFrom(['counts' => ['needs-reroll' => 1], 'patches' => [$this->row([
            'verdict' => 'needs-reroll', 'title' => $title, 'source' => 'patchs/webform-style.patch',
        ])]]);

        $line = $this->lines($plan)[0];

        self::assertStringEndsWith($title, $line, 'the message body takes a colon and a comma unescaped');
        self::assertStringStartsWith('::error file=composer.json,line=5::needs-reroll', $line, 'nothing of the title reaches a property');
    }

    public function testAPercentSignIsEscaped(): void
    {
        $plan = $this->planFrom(['counts' => ['needs-reroll' => 1], 'patches' => [$this->row([
            'verdict' => 'needs-reroll', 'title' => '100% of hunks failed', 'source' => 'patchs/webform-style.patch',
        ])]]);

        self::assertStringEndsWith('100%25 of hunks failed', $this->lines($plan)[0]);
    }

    public function testANewlineNeverBreaksTheLine(): void
    {
        $plan = $this->planFrom(['counts' => ['needs-reroll' => 1], 'patches' => [$this->row([
            'verdict' => 'needs-reroll', 'title' => "Two\r\nlines", 'source' => 'patchs/webform-style.patch',
        ])]]);

        $lines = $this->lines($plan);

        self::assertCount(1, $lines);
        self::assertStringEndsWith('Two%0D%0Alines', $lines[0]);
    }

    public function testAPercentIsEscapedBeforeTheNewlineIsEncoded(): void
    {
        $plan = $this->planFrom(['counts' => ['needs-reroll' => 1], 'patches' => [$this->row([
            'verdict' => 'needs-reroll', 'title' => '%0A', 'source' => 'patchs/webform-style.patch',
        ])]]);

        self::assertStringEndsWith('%250A', $this->lines($plan)[0], 'a literal %0A in a title is not read back as a newline');
    }

    public function testAPlanWithNothingToSayWritesNothing(): void
    {
        self::assertSame([], $this->lines($this->planFrom(['patches' => []])));
    }

    /**
     * @return list<string>
     */
    private function lines(\Tresbien\Drupatch\Plan\Plan $plan): array
    {
        return Annotations::lines($plan, 'composer.json', Lines::in(self::DOCUMENT));
    }
}

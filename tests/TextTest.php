<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests;

use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Text;

final class TextTest extends TestCase
{
    public function testAPlaceholderTakesItsValue(): void
    {
        self::assertSame('drupal/webform 6.2.9', Text::t('@package @version', ['@package' => 'drupal/webform', '@version' => '6.2.9']));
    }

    public function testAPlaceholderNamedTwiceIsFilledTwice(): void
    {
        self::assertSame('a and a', Text::t('@one and @one', ['@one' => 'a']));
    }

    public function testANumberIsWrittenAsItReads(): void
    {
        self::assertSame('3 left', Text::t('@n left', ['@n' => 3]));
    }

    public function testATemplateWithNoPlaceholderIsItself(): void
    {
        self::assertSame('nothing to do', Text::t('nothing to do'));
    }

    // A key is the placeholder as the template writes it. A name without
    // the marker replaces the bare word wherever it falls, so a caller
    // that drops it gets the marker left over.
    public function testAKeyWithoutTheMarkerSubstitutesTheBareName(): void
    {
        self::assertSame('@drupal/webform', Text::t('@package', ['package' => 'drupal/webform']));
    }

    // A value carrying an @ is not read again, so a source with one in it
    // cannot name another placeholder.
    public function testAValueIsNotReadForPlaceholders(): void
    {
        self::assertSame('@name', Text::t('@source', ['@source' => '@name', '@name' => 'x']));
    }

    public function testOneTakesTheSingularForm(): void
    {
        self::assertSame('1 patch', Text::plural(1, '@count patch', '@count patches'));
    }

    public function testAnyOtherCountTakesThePluralForm(): void
    {
        self::assertSame('0 patches', Text::plural(0, '@count patch', '@count patches'));
        self::assertSame('2 patches', Text::plural(2, '@count patch', '@count patches'));
    }

    public function testAPluralFormTakesItsOtherPlaceholders(): void
    {
        self::assertSame('2 patches of drupal/webform', Text::plural(2, '@count patch of @package', '@count patches of @package', ['@package' => 'drupal/webform']));
    }
}

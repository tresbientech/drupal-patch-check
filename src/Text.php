<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch;

/**
 * The one way a printed sentence is built: a template with `@name` placeholders, and the values that go in them.
 *
 * Nothing here escapes or validates. Every value comes from this plugin, the
 * site's own composer.json or the service's answer, and lands on a terminal.
 */
class Text
{
    /**
     * One sentence, with every `@name` replaced by the value under that key.
     *
     * @param array<string, string|int> $values
     */
    public static function t(string $template, array $values = []): string
    {
        $replace = [];
        foreach ($values as $name => $value) {
            $replace['@'.$name] = (string) $value;
        }

        return \strtr($template, $replace);
    }

    /**
     * The one form or the many form, with `@count` filled in from the number that chose it.
     *
     * @param array<string, string|int> $values
     */
    public static function plural(int $count, string $one, string $many, array $values = []): string
    {
        return self::t(1 === $count ? $one : $many, $values + ['count' => $count]);
    }
}

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
     * One sentence, with every placeholder replaced by the value under its own name. A key is the placeholder as the template writes it, `@name` and all.
     *
     * @param array<string, string|int> $values
     */
    public static function t(string $template, array $values = []): string
    {
        return \strtr($template, \array_map(\strval(...), $values));
    }

    /**
     * The one form or the many form, with `@count` filled in from the number that chose it.
     *
     * @param array<string, string|int> $values
     */
    public static function plural(int $count, string $one, string $many, array $values = []): string
    {
        return self::t(1 === $count ? $one : $many, $values + ['@count' => $count]);
    }
}

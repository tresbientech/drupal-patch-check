<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Render;

/**
 * How much room a report row has, and how to make text fit it.
 *
 * The report puts the patch filename at the end of every row, so the
 * title takes what is left. One title width is used for every row, which
 * is what lines the filenames up into a column a reader can follow.
 *
 * Character counting goes through `mb_*`. Composer's own runtime loads
 * symfony/polyfill-mbstring, so these exist in the process this plugin
 * shares whether or not the extension is built in. Counting bytes would
 * cut a multi-byte title in half.
 */
final class Budget
{
    /** The narrowest terminal the report is laid out for. */
    public const MIN_WIDTH = 80;

    /** Past this a wider terminal only strands the filename column. */
    public const MAX_WIDTH = 120;

    /** Row indent, the mark and its space, the verdict column and its space. */
    public const PREFIX = 20;

    /** The longest filename column, so the narrowest row still fits. */
    public const TRAILING_MAX = 32;

    /** Between the title and the filename. */
    private const GAP = 2;

    /** Below this a title says nothing, so the row runs long instead. */
    private const MIN_TITLE = 24;

    /** What a shortened string ends with. */
    private const ELLIPSIS = '…';

    /**
     * A terminal width brought inside the range the report is laid out
     * for. A width of zero means the terminal did not say.
     */
    public static function clamp(int $width): int
    {
        return \max(self::MIN_WIDTH, \min(self::MAX_WIDTH, $width));
    }

    /**
     * The room a title has, given the total width and the width of the
     * filename column that follows it.
     */
    public static function title(int $width, int $trailing): int
    {
        return \max(self::MIN_TITLE, $width - self::PREFIX - self::GAP - \max(0, $trailing));
    }

    /**
     * The indent a row's detail lines start at, so they sit under the
     * title rather than under the mark.
     */
    public static function detailIndent(): string
    {
        return \str_repeat(' ', self::PREFIX);
    }

    /**
     * `$text` shortened to `$width` characters, ending in an ellipsis
     * when something was cut. Text that already fits is returned whole.
     */
    public static function fit(string $text, int $width): string
    {
        if ($width <= 0) {
            return '';
        }
        if (\mb_strlen($text) <= $width) {
            return $text;
        }
        if (1 === $width) {
            return self::ELLIPSIS;
        }

        return \rtrim(\mb_substr($text, 0, $width - 1)).self::ELLIPSIS;
    }

    /**
     * `$text` padded with spaces to `$width` characters. Text at or over
     * the width is returned unchanged.
     */
    public static function pad(string $text, int $width): string
    {
        $short = $width - \mb_strlen($text);

        return $short > 0 ? $text.\str_repeat(' ', $short) : $text;
    }
}

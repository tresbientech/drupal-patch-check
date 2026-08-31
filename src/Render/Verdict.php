<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Render;

/**
 * What a verdict looks like in a report: its mark, its colour, and where
 * it sorts.
 *
 * Both renderers read this, so a verdict cannot mean one thing in the
 * command's report and another in the post-update hook. The mark is
 * decoration: every row prints the verdict word beside it, so a terminal
 * that cannot draw the mark loses nothing a reader needs.
 */
final class Verdict
{
    /**
     * Mark, colour tag and sort rank per verdict, worst first. An empty
     * tag prints the mark unstyled.
     *
     * @var array<string, array{string, string, int}>
     */
    private const KNOWN = [
        'conflicts' => ['!', 'error', 0],
        'unknown' => ['?', 'comment', 1],
        'applies' => ['·', '', 2],
        'merged' => ['✓', 'info', 3],
    ];

    /**
     * A verdict the server added after this release. It sorts with the
     * work rather than with the finished rows, which matches the rule
     * that an unknown verdict never counts as fine.
     *
     * @var array{string, string, int}
     */
    private const UNRECOGNISED = ['*', 'comment', 1];

    public static function mark(string $verdict): string
    {
        return self::of($verdict)[0];
    }

    /**
     * The composer output tag the mark is written with, empty for none.
     */
    public static function tag(string $verdict): string
    {
        return self::of($verdict)[1];
    }

    /**
     * Where the verdict sorts. Lower comes first, so the work is at the
     * top of the report.
     */
    public static function rank(string $verdict): int
    {
        return self::of($verdict)[2];
    }

    public static function isKnown(string $verdict): bool
    {
        return isset(self::KNOWN[$verdict]);
    }

    /**
     * The mark ready to print, wrapped in its colour when it has one.
     */
    public static function marked(string $verdict): string
    {
        [$mark, $tag] = self::of($verdict);

        return '' === $tag ? $mark : '<'.$tag.'>'.$mark.'</'.$tag.'>';
    }

    /**
     * @return array{string, string, int}
     */
    private static function of(string $verdict): array
    {
        return self::KNOWN[$verdict] ?? self::UNRECOGNISED;
    }
}

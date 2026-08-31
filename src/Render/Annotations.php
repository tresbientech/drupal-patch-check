<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Render;

use Tresbien\Drupatch\PatchConfig\Lines;
use Tresbien\Drupatch\Plan\PatchRow;
use Tresbien\Drupatch\Plan\Plan;

/**
 * The verdicts as workflow commands, which a runner turns into
 * annotations on the declaring line.
 *
 * Only a row asking for a decision is written. A patch that applies and
 * is still required is the expected case, and annotating it would bury
 * the rows that are not.
 */
final class Annotations
{
    /** The annotation level each verdict is written at. */
    private const LEVELS = [
        PatchRow::CONFLICTS => 'error',
        PatchRow::UNKNOWN => 'warning',
        PatchRow::MERGED => 'notice',
    ];

    /**
     * One command per row worth annotating, in plan order.
     *
     * @return list<string>
     */
    public static function lines(Plan $plan, string $file, Lines $lines): array
    {
        $out = [];
        foreach ($plan->patches as $row) {
            $level = self::LEVELS[$row->verdict] ?? null;
            if (null === $level) {
                continue;
            }
            $out[] = \sprintf(
                '::%s file=%s,line=%d::%s',
                $level,
                $file,
                $lines->of($row->source),
                self::escape(self::message($row)),
            );
        }

        return $out;
    }

    /**
     * What the row says, in one line.
     */
    private static function message(PatchRow $row): string
    {
        $message = \sprintf('%s %s %s: %s', $row->verdict, $row->package, $row->version, $row->label());
        if ('' !== $row->firstFailure()) {
            $message .= '; '.$row->firstFailure();
        }

        return $message;
    }

    /**
     * The message as a command body.
     *
     * A property value would also have to escape the colon and the comma,
     * and patch titles carry both, so the whole message travels in the
     * body where three characters are enough. The percent sign goes first,
     * or a title already reading `%0A` would come back as a line break.
     */
    private static function escape(string $message): string
    {
        return \str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $message);
    }
}

<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Render;

use Tresbien\Drupatch\Plan\PatchRow;

/**
 * What to run after reading a report.
 *
 * The counts say what the run found, and each finding has one command
 * that clears it. A report with nothing to clear suggests nothing, which
 * is what makes a suggestion worth reading when it appears.
 */
final class NextSteps
{
    /** The command every suggestion is a flag on. */
    public const COMMAND = 'composer drupal-patch-check';

    /** What the footer is introduced by. */
    private const LABEL = 'Next:';

    /**
     * The commands worth running, worst finding first.
     *
     * @param array<string, int> $counts patches per verdict
     *
     * @return list<array{flag: string, effect: string}>
     */
    public static function of(array $counts): array
    {
        $out = [];
        $reroll = $counts[PatchRow::CONFLICTS] ?? 0;
        if ($reroll > 0) {
            $out[] = [
                'flag' => '--reroll',
                'effect' => 1 === $reroll ? 'writes the re-roll' : 'writes the '.$reroll.' re-rolls',
            ];
        }
        $shipped = $counts[PatchRow::MERGED] ?? 0;
        if ($shipped > 0) {
            $out[] = [
                'flag' => '--fix',
                'effect' => 1 === $shipped
                    ? 'drops the shipped entry from composer.json'
                    : 'drops the '.$shipped.' shipped entries from composer.json',
            ];
        }

        return $out;
    }

    /**
     * The footer as printed, empty when there is nothing to run.
     *
     * @param array<string, int> $counts patches per verdict
     *
     * @return list<string>
     */
    public static function lines(array $counts, string $indent = '  '): array
    {
        $steps = self::of($counts);
        if ([] === $steps) {
            return [];
        }
        $widest = 0;
        foreach ($steps as $step) {
            $widest = \max($widest, \strlen(self::COMMAND.' '.$step['flag']));
        }
        $lines = [];
        foreach ($steps as $i => $step) {
            $lines[] = $indent
                .(0 === $i ? self::LABEL.'  ' : \str_repeat(' ', \strlen(self::LABEL) + 2))
                .Budget::pad(self::COMMAND.' '.$step['flag'], $widest)
                .'   '.$step['effect'];
        }

        return $lines;
    }
}

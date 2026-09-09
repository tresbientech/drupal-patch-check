<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Render;

use TresBienTech\Drupatch\Text;

/**
 * What a pin run prints: what it copied into the site, what was there already, what it could not copy, and the declarations it rewrote.
 *
 * @phpstan-type PinnedRow array{package: string, title: string, source: string, path: string}
 * @phpstan-type RefusedRow array{package: string, title: string, source: string, reason: string}
 */
class PinReport
{
    /** Over the patches this run copied. */
    private const COPIED = 'copied into the site:';

    /** Over the patches whose file the site held already. */
    private const HELD = 'already in the site:';

    /** Over the patches whose merge request holds commits the copy does not. */
    private const MOVED = 'moved since you copied it:';

    /** Over the patches this run could not copy. */
    private const REFUSED = 'not copied:';

    /** The headline of a run with no merge request to act on. */
    private const NOTHING = 'no patch is declared from a merge request';

    /**
     * @param array{vendored: list<PinnedRow>, kept: list<PinnedRow>, moved: list<PinnedRow>, refused: list<RefusedRow>} $result
     * @param string                                                                                                     $declaration the file the run rewrote, empty when it rewrote none
     *
     * @return list<string>
     */
    public static function lines(array $result, string $declaration, int $rewritten): array
    {
        $lines = ['<info>'.Report::LABEL.'</info>: '.self::headline($result)];
        foreach ([[self::COPIED, $result['vendored']], [self::HELD, $result['kept']], [self::MOVED, $result['moved']]] as [$heading, $rows]) {
            foreach (self::block($heading, $rows) as $line) {
                $lines[] = $line;
            }
        }
        if ([] !== $result['moved']) {
            $lines[] = '  '.Text::t('run `@command --refresh` to take the new commits', ['command' => Report::PIN]);
        }
        foreach (self::refusals($result['refused']) as $line) {
            $lines[] = $line;
        }
        if (0 !== $rewritten && '' !== $declaration) {
            $lines[] = '';
            $lines[] = '  '.Text::plural(
                $rewritten,
                '@file: @count declaration now names a file in the site',
                '@file: @count declarations now name a file in the site',
                ['file' => $declaration]
            );
        }

        return $lines;
    }

    /**
     * What the run did, in one sentence.
     *
     * @param array{vendored: list<PinnedRow>, kept: list<PinnedRow>, moved: list<PinnedRow>, refused: list<RefusedRow>} $result
     */
    private static function headline(array $result): string
    {
        $parts = [];
        foreach ([[\count($result['vendored']), 'copied into the site'], [\count($result['kept']), 'already in the site'], [\count($result['moved']), 'moved upstream'], [\count($result['refused']), 'not copied']] as [$count, $words]) {
            if ($count > 0) {
                $parts[] = Text::plural($count, '@count patch @words', '@count patches @words', ['words' => $words]);
            }
        }

        return [] === $parts ? self::NOTHING : \implode(', ', $parts);
    }

    /**
     * One list of patches under its heading, each over the file it is about.
     *
     * @param list<PinnedRow> $rows
     *
     * @return list<string>
     */
    private static function block(string $heading, array $rows): array
    {
        if ([] === $rows) {
            return [];
        }
        $lines = ['', '  '.$heading];
        foreach ($rows as $row) {
            $lines[] = '    '.Text::t('@package: @title', ['package' => $row['package'], 'title' => $row['title']]);
            $lines[] = '      '.$row['path'];
        }

        return $lines;
    }

    /**
     * The patches this run left alone, grouped under the reason they were.
     *
     * @param list<RefusedRow> $rows
     *
     * @return list<string>
     */
    private static function refusals(array $rows): array
    {
        if ([] === $rows) {
            return [];
        }
        $lines = ['', '  '.self::REFUSED];
        $reason = '';
        foreach ($rows as $row) {
            if ($row['reason'] !== $reason) {
                $reason = $row['reason'];
                $lines[] = '    <comment>'.$reason.'</comment>';
            }
            $lines[] = '      '.Text::t('@package: @title', ['package' => $row['package'], 'title' => $row['title']]);
        }

        return $lines;
    }
}

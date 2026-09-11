<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Render;

use TresBienTech\Drupatch\Command\AddCommand;
use TresBienTech\Drupatch\Text;

/**
 * What an add run prints: the issue it resolved, the copy it made, and the verdict.
 *
 * @phpstan-import-type Outcome from AddCommand
 * @phpstan-import-type Candidate from \TresBienTech\Drupatch\Source\Ranking
 */
class AddReport
{
    /** Width of the label column, so the values line up under each other. */
    private const LABEL = 9;

    /** What a person is asked once the candidates are listed. */
    public const QUESTION = '  Which one?';

    /** Beside a merge request nobody has finished. */
    public const DRAFT = 'draft';

    /**
     * @param Outcome $result
     *
     * @return list<string>
     */
    public static function lines(array $result): array
    {
        if ('' !== $result['error']) {
            return ['', '  <error>'.$result['error'].'</error>'];
        }

        $lines = ['', '  '.Text::t('@package, issue @issue', ['@package' => $result['package'], '@issue' => $result['issue']]), ''];
        if ([] !== $result['candidates']) {
            $lines[] = '  '.Text::t('took @label', ['@label' => self::candidate($result['candidates'][$result['chosen']])]);
            $lines[] = '';
        }
        $lines[] = '  '.self::row('vendored', $result['path']);
        $lines[] = '  '.self::row('verdict', '' === $result['reason']
            ? $result['verdict']
            : Text::t('@verdict, @reason', ['@verdict' => $result['verdict'], '@reason' => $result['reason']]));

        if ('' !== $result['wrote'] && $result['wrote'] !== $result['path']) {
            $lines[] = '  '.self::row('re-roll', $result['wrote']);
        }
        foreach ($result['regions'] as $region) {
            $lines[] = '  '.self::row('', $region);
        }
        if ($result['dryRun']) {
            return [...$lines, '', '  '.Text::t('nothing written (--dry-run)')];
        }
        if (!$result['declared']) {
            return [...$lines, '', '  '.self::undeclared($result)];
        }

        return [...$lines, '', '  '.Text::t('declared @title', ['@title' => $result['title']])];
    }

    /**
     * Why a run that copied a patch declared nothing.
     *
     * @param Outcome $result
     */
    private static function undeclared(array $result): string
    {
        if ('merged' === $result['verdict']) {
            return Text::t('the release already carries this change, so nothing was declared');
        }
        if ([] !== $result['regions']) {
            return Text::plural(
                \count($result['regions']),
                'decide @count region in @file, then run `@reroll`',
                'decide @count regions in @file, then run `@reroll`',
                ['@file' => $result['wrote'], '@reroll' => Report::REROLL]
            );
        }

        return Text::t('nothing was declared: @reason', ['@reason' => $result['reason']]);
    }

    /**
     * The merge requests on one issue, in the order they are offered, for a person about to pick.
     *
     * @param non-empty-list<Candidate> $ordered
     *
     * @return list<string>
     */
    public static function candidates(array $ordered, string $issue): array
    {
        $lines = ['', '  '.Text::plural(\count($ordered), '@count merge request on issue @issue', '@count merge requests on issue @issue', ['@issue' => $issue]), ''];
        foreach ($ordered as $at => $candidate) {
            $lines[] = '  '.Text::t('[@at] @candidate', ['@at' => $at, '@candidate' => self::candidate($candidate)]);
        }

        return [...$lines, ''];
    }

    /**
     * One merge request on a line: its number, where it lands, when it was last pushed, and its title.
     *
     * @param Candidate $candidate
     */
    private static function candidate(array $candidate): string
    {
        return Text::t('!@iid  @target  @pushed  @title@draft', [
            '@iid' => \str_pad($candidate['iid'], 5),
            '@target' => \str_pad($candidate['target'], 8),
            // The stamp is ISO 8601, so the date is its first ten characters.
            '@pushed' => \substr($candidate['updated'], 0, 10),
            '@title' => $candidate['title'],
            '@draft' => $candidate['draft'] ? '  ('.self::DRAFT.')' : '',
        ]);
    }

    /**
     * One labelled value, padded to the label column.
     */
    private static function row(string $label, string $value): string
    {
        return \str_pad($label, self::LABEL).$value;
    }
}

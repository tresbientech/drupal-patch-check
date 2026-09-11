<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Render;

use TresBienTech\Drupatch\Command\UpgradeCommand;
use TresBienTech\Drupatch\Manager;
use TresBienTech\Drupatch\ManagerCommands;
use TresBienTech\Drupatch\Settings;
use TresBienTech\Drupatch\Text;
use TresBienTech\Drupatch\Write\Upgrade;

/**
 * What a manager upgrade prints: the patches the move costs, the depths it records, the layout it changes, and the settings it moves.
 *
 * @phpstan-import-type MoveReport from UpgradeCommand
 * @phpstan-import-type Refusal from UpgradeCommand
 * @phpstan-import-type Wrote from UpgradeCommand
 */
class UpgradeReport
{
    /** What a run on a site whose composer.json an earlier run moved says in place of the move. */
    public const RESUMED = 'composer.json already holds the move, so this run writes nothing';

    /** What a real run that wrote neither document says. */
    public const STAYED = 'nothing written, so the site stays on 1.x';

    /** What a dry run says when a strict refusal's re-roll would not land. */
    public const STOPS = 'a real run stops on the rows that do not re-roll clean and writes nothing';

    /** Where the settings column starts, so the arrows line up under each other. */
    private const KEY = 24;

    /** The narrowest the package column goes, whatever the run holds. */
    private const PACKAGE = 16;

    /**
     * @param MoveReport $move
     *
     * @return list<string>
     */
    public static function lines(array $move): array
    {
        if ('' !== $move['error']) {
            return ['', '  <error>'.$move['error'].'</error>'];
        }

        $lines = ['', '  '.Text::t('@package @from -> @to', ['@package' => Manager::PACKAGE, '@from' => $move['from'], '@to' => Upgrade::CONSTRAINT]), ''];
        if ($move['resumed']) {
            return [...$lines, '  '.Text::t(self::RESUMED), ...(null === $move['wrote'] ? self::finishes() : [])];
        }
        // Both lists share one pair of widths, so a package named in each
        // sits at the same column in both.
        [$package, $number] = self::columns($move['refused'], $move['depths']);
        foreach (self::refusals($move['refused'], $move['unchecked'], $package, $number, null === $move['wrote']) as $line) {
            $lines[] = $line;
        }
        foreach (self::depths($move['depths'], $package, $number) as $line) {
            $lines[] = $line;
        }
        foreach (self::layout($move['file']) as $line) {
            $lines[] = $line;
        }
        foreach (self::settings($move['renamed'], $move['dropped']) as $line) {
            $lines[] = $line;
        }
        if (null === $move['wrote']) {
            return [...$lines, '', '  '.Text::t('nothing written (--dry-run)'), ...self::finishes()];
        }

        return [...$lines, ...self::wrote($move['wrote'])];
    }

    /**
     * What the run did, and what a person runs next.
     *
     * @param Wrote $wrote
     *
     * @return list<string>
     */
    private static function wrote(array $wrote): array
    {
        $lines = [];
        if ($wrote['vendored'] > 0) {
            $lines[] = '  '.Text::plural($wrote['vendored'], '@count patch copied into this site', '@count patches copied into this site');
        }
        if ($wrote['rerolled'] > 0) {
            $lines[] = '  '.Text::plural($wrote['rerolled'], '@count patch re-rolled', '@count patches re-rolled');
        }
        foreach ($wrote['refused'] as $row) {
            $lines[] = '  <comment>'.Text::t('@title: @reason', ['@title' => $row['title'], '@reason' => $row['reason']]).'</comment>';
        }
        if ($wrote['forcible'] > 0) {
            $lines[] = '  '.Text::plural(
                $wrote['forcible'],
                'run `@reroll --force` to replace the file this run would not overwrite',
                'run `@reroll --force` to replace the @count files this run would not overwrite',
                ['@reroll' => Report::REROLL]
            );
        }
        if ([] !== $wrote['open']) {
            return [...$lines, ...self::open($wrote['open'])];
        }
        if ([] === $wrote['files']) {
            return [...$lines, '  '.Text::t(self::STAYED), ''];
        }

        return [...$lines, '  '.Text::t('wrote @files', ['@files' => \implode(', ', $wrote['files'])]), ''];
    }

    /**
     * The line that closes a move whose manager commands all finished.
     *
     * @return list<string>
     */
    public static function moved(): array
    {
        return ['', '  '.Text::t('moved to @package 2.x', ['@package' => Manager::PACKAGE])];
    }

    /**
     * The regions a person decides before the move can finish.
     *
     * @param list<array{path: string, regions: int}> $open
     *
     * @return list<string>
     */
    private static function open(array $open): array
    {
        $lines = ['', '  <comment>'.Text::plural(
            \count($open),
            '@count re-roll left regions to decide, so nothing else was written:',
            '@count re-rolls left regions to decide, so nothing else was written:'
        ).'</comment>'];
        foreach ($open as $row) {
            $lines[] = '    '.Text::plural($row['regions'], '@path  @count region', '@path  @count regions', ['@path' => $row['path']]);
        }

        return [...$lines, '', '  '.Text::t('decide them, run `@reroll`, then run this command again', ['@reroll' => Report::REROLL])];
    }

    /**
     * The manager commands a real run ends with, for a run that wrote nothing.
     *
     * @return list<string>
     */
    private static function finishes(): array
    {
        $lines = ['  '.Text::t('a real run finishes with:')];
        foreach (ManagerCommands::MOVE as $command) {
            $lines[] = '    '.Text::t('composer @command', ['@command' => ManagerCommands::line($command)]);
        }

        return $lines;
    }

    /**
     * The width of the two columns the lists share: the widest package name, and the widest patch number.
     *
     * @param list<Refusal>                                         $refused
     * @param list<array{package: string, number: int, depth: int}> $depths
     *
     * @return array{0: int, 1: int}
     */
    private static function columns(array $refused, array $depths): array
    {
        $package = self::PACKAGE;
        $number = 1;
        foreach ([...$refused, ...$depths] as $row) {
            $package = \max($package, \strlen($row['package']));
            $number = \max($number, \strlen((string) $row['number']));
        }

        return [$package, $number];
    }

    /**
     * One row's package and patch number, each in the column the run settled on.
     *
     * @param array{package: string, number: int} $row
     *
     * @return array<string, string>
     */
    private static function at(array $row, int $package, int $number): array
    {
        return [
            '@package' => \str_pad($row['package'], $package),
            '@number' => \str_pad('#'.$row['number'], $number + 1, ' ', \STR_PAD_LEFT),
        ];
    }

    /**
     * The patches a lenient apply accepts and `git apply` refuses, which is the work the move does, each with what its re-roll comes to.
     *
     * @param list<Refusal> $refused
     * @param bool          $dryRun  the run wrote nothing, so the real run is still to come
     *
     * @return list<string>
     */
    private static function refusals(array $refused, int $unchecked, int $package, int $number, bool $dryRun): array
    {
        if ([] === $refused) {
            $lines = ['  '.Text::t(0 === $unchecked ? 'the move stops no patch from applying' : 'the move stops no checked patch from applying')];
        } else {
            $lines = ['  '.Text::plural(
                \count($refused),
                '@count patch needs a re-roll before 2.x applies it:',
                '@count patches need a re-roll before 2.x applies them:'
            )];
            $stops = false;
            foreach ($refused as $row) {
                $lines[] = '    '.Text::t('@package  @number  @outcome', self::at($row, $package, $number) + ['@outcome' => self::outcome($row)]);
                $stops = $stops || !UpgradeCommand::lands($row);
            }
            if ($dryRun && $stops) {
                $lines[] = '  '.Text::t(self::STOPS);
            }
        }
        if ($unchecked > 0) {
            $lines[] = '  '.Text::plural(
                $unchecked,
                '@count patch was not checked, so this run cannot say whether 2.x applies it',
                '@count patches were not checked, so this run cannot say whether 2.x applies them'
            );
        }

        return [...$lines, ''];
    }

    /**
     * What one strict refusal's re-roll comes to, in the row's words.
     *
     * @param Refusal $row
     */
    private static function outcome(array $row): string
    {
        return match ($row['outcome']) {
            'clean' => Text::t('re-rolls clean'),
            'open' => Text::plural($row['regions'], 'leaves @count region to decide', 'leaves @count regions to decide'),
            'none' => Text::t('no re-roll: @why', ['@why' => $row['why']]),
            'shipped' => Text::t('already in the release'),
        };
    }

    /**
     * The patches whose measured level differs from the depth their package defaults to.
     *
     * @param list<array{package: string, number: int, depth: int}> $depths
     *
     * @return list<string>
     */
    private static function depths(array $depths, int $package, int $number): array
    {
        if ([] === $depths) {
            return [];
        }
        $lines = ['  '.Text::plural(
            \count($depths),
            '@count patch applies at a depth other than its package default:',
            '@count patches apply at a depth other than their package default:'
        )];
        foreach ($depths as $row) {
            $lines[] = '    '.Text::t('@package  @number  -p@depth', self::at($row, $package, $number) + ['@depth' => $row['depth']]);
        }

        return [...$lines, ''];
    }

    /**
     * Where the declarations go. An edit to composer.json invalidates composer.lock's content hash, so they move out of it once.
     *
     * @return list<string>
     */
    private static function layout(string $file): array
    {
        return [
            '  '.Text::t('layout:'),
            '    '.Text::t('@from -> @file', ['@from' => \str_pad('extra.patches', self::KEY), '@file' => $file]),
            // Under the file it moves to, at the column the arrow left it in.
            '    '.\str_pad('', self::KEY + 4).Text::t('extra.@key.patches-file', ['@key' => Settings::KEY]),
            '',
        ];
    }

    /**
     * The settings the move renames, and the ones 2.x has nothing to move to.
     *
     * @param array<string, string> $renamed 1.x key to its 2.x name
     * @param array<string, string> $dropped 1.x key to why it goes
     *
     * @return list<string>
     */
    private static function settings(array $renamed, array $dropped): array
    {
        if ([] === $renamed && [] === $dropped) {
            return [];
        }
        $lines = ['  '.Text::t('settings:')];
        foreach ($renamed as $from => $to) {
            $lines[] = '    '.Text::t('@from -> extra.@key.@to', ['@from' => \str_pad('extra.'.$from, self::KEY), '@key' => Settings::KEY, '@to' => $to]);
        }
        foreach ($dropped as $from => $why) {
            $lines[] = '    '.Text::t('@from dropped, @why', ['@from' => \str_pad('extra.'.$from, self::KEY), '@why' => $why]);
        }

        return [...$lines, ''];
    }
}

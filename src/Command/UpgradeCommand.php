<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Command;

use Composer\Composer;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TresBienTech\Drupatch\Fetch\Vendoring;
use TresBienTech\Drupatch\Manager;
use TresBienTech\Drupatch\ManagerCommands;
use TresBienTech\Drupatch\Plan\PatchRow;
use TresBienTech\Drupatch\Plan\Plan;
use TresBienTech\Drupatch\Plugin;
use TresBienTech\Drupatch\Read\PatchConfig;
use TresBienTech\Drupatch\Read\Scope;
use TresBienTech\Drupatch\Read\Site;
use TresBienTech\Drupatch\Render\UpgradeReport;
use TresBienTech\Drupatch\Service\Client;
use TresBienTech\Drupatch\Settings;
use TresBienTech\Drupatch\Text;
use TresBienTech\Drupatch\Write\Declarations;
use TresBienTech\Drupatch\Write\Move;
use TresBienTech\Drupatch\Write\PatchFiles;
use TresBienTech\Drupatch\Write\Upgrade;

/**
 * Moves a site from 1.x of the patch manager to 2.x.
 *
 * 2.x applies with `git apply` alone, at the depth each patch names. A patch
 * a lenient apply accepts and a strict one refuses stops working. A patch that
 * needs a level its package does not default to has to record one. The run
 * asks the service both questions in one call.
 *
 * @phpstan-import-type Moved from Move
 * @phpstan-import-type Declaration from Move
 *
 * @phpstan-type Ready array{composer: Composer, manager: Manager, site: Site, extra: array<string, mixed>, file: string, declared: list<Declaration>}
 * @phpstan-type Wrote array{vendored: int, rerolled: int, forcible: int, files: list<string>, open: list<array{path: string, regions: int}>, refused: list<array{title: string, reason: string, lifts: string}>}
 * @phpstan-type Outcome array{outcome: 'clean'|'open'|'none'|'shipped', regions: int, why: string}
 * @phpstan-type Refusal array{package: string, number: int, outcome: 'clean'|'open'|'none'|'shipped', regions: int, why: string}
 * @phpstan-type MoveReport array{from: string, refused: list<Refusal>, depths: list<array{package: string, number: int, depth: int}>, file: string, renamed: array<string, string>, dropped: array<string, string>, wrote: Wrote|null, error: string, resumed: bool, unchecked: int}
 */
class UpgradeCommand extends PatchCommand
{
    public const NAME = Manager::UPGRADE;

    /** What a site already on 2.x is told. */
    public const ALREADY = 'this site already runs cweagans/composer-patches 2.x';

    /** What a site with no patch manager at all is told. */
    public const NO_MANAGER = 'this site installs no cweagans/composer-patches, so there is nothing to move';

    /** Where the declarations land, which is the file 2.x reads by default. */
    public const PATCHES_FILE = 'patches.json';

    protected function configure(): void
    {
        $this
            ->setName(self::NAME)
            ->setDescription('Move this site from cweagans/composer-patches 1.x to 2.x')
            ->addOption('vendor', null, InputOption::VALUE_NONE, 'Copy every patch into this site, rather than only the ones a re-roll has to land on.')
            ->testFiles()
            ->addOption('force', null, InputOption::VALUE_NONE, 'Replace a patch file git reports as changed or untracked, and rewrite composer.json and the patches file with uncommitted changes.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print what would change and write nothing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = true === $input->getOption('dry-run');
        try {
            $dropTests = self::dropTests($input);
            $ready = $this->prepare();
            $move = match (true) {
                \is_string($ready) => self::stopped($ready),
                Settings::moved($ready['extra']) => self::resumed($ready, $dryRun),
                $dryRun => self::move($ready, $this->ask($ready, $dropTests), null),
                default => $this->perform($ready, true === $input->getOption('vendor'), true === $input->getOption('force'), $dropTests),
            };
        } catch (Throwable $e) {
            $move = self::stopped($e->getMessage());
        }

        foreach (UpgradeReport::lines($move) as $line) {
            $output->writeln($line);
        }
        if ('' !== $move['error']) {
            return Plan::ACTION_NEEDED;
        }
        if (null === $move['wrote']) {
            return Plan::CLEAN;
        }
        // A run that wrote neither document stopped on a patch, and its report
        // says which. A resumed run found both in place.
        if ([] === $move['wrote']['files'] && !$move['resumed']) {
            return Plan::ACTION_NEEDED;
        }

        $ran = ManagerCommands::run(ManagerCommands::MOVE, $input->isInteractive(), $output);
        foreach ('' === $ran['error'] ? UpgradeReport::moved() : ManagerCommands::stopped($ran) as $line) {
            $output->writeln($line);
        }

        return '' === $ran['error'] ? Plan::CLEAN : Plan::ACTION_NEEDED;
    }

    /**
     * The move itself: the patches first, then the two documents.
     *
     * The documents come last because a re-roll leaving regions open stops
     * the run, and a site whose requirement moved with a patch left open
     * installs 2.x and applies conflict markers.
     *
     * @param Ready $ready
     * @param bool  $vendorAll copy every declaration into the site, not only the ones a re-roll lands on
     * @param bool  $force     replace a patch file git reports as changed, and rewrite documents with uncommitted changes
     * @param ?bool $dropTests whether a re-roll leaves test files out, null for the service's default
     *
     * @return MoveReport
     */
    private function perform(array $ready, bool $vendorAll, bool $force, ?bool $dropTests): array
    {
        $root = $ready['site']->root;
        $tree = $this->tree($force);
        Declarations::refuseReplacing($root, [PatchConfig::COMPOSER_JSON, $ready['file']], $tree);
        // Built before the first write, so an edit composer refuses stops
        // the run with nothing on disk.
        $composerJson = Upgrade::composerJsonFor($root, $ready['extra'], $ready['file']);
        $plan = $this->ask($ready, $dropTests);
        $composer = $ready['composer'];
        $vendoring = new Vendoring($root, $this->patchText($root), Plugin::patchDirectory($ready['extra']), $ready['manager'], $tree);
        $moved = (new Move($root, $vendoring, $tree))
            ->run($plan, $ready['declared'], $vendorAll ? Scope::whole() : self::copying($plan));
        $files = self::landed($moved, $plan)
            ? Upgrade::writeTo($root, $ready['file'], $moved['declarations'], self::measured($plan), $composerJson)
            : [];

        $refused = self::why([...$moved['refused'], ...$moved['unwritten']]);

        return self::move($ready, $plan, [
            'vendored' => \count($moved['vendored']),
            'rerolled' => \count($moved['written']) - \count($moved['open']),
            'forcible' => self::forcible($refused),
            'files' => $files,
            'open' => $moved['open'],
            'refused' => $refused,
        ]);
    }

    /**
     * Whether every patch 2.x would refuse now applies. The two documents wait on it.
     *
     * A patch the release already carries is finished work; every other
     * refusal leaves a patch `git apply` refuses. A strict refusal the
     * service sent no re-roll for never reaches the write, so the plan's
     * own refusals are read as well.
     *
     * @param Moved $moved
     */
    private static function landed(array $moved, Plan $plan): bool
    {
        foreach (self::refused($plan) as $row) {
            if (!self::lands($row)) {
                return false;
            }
        }
        foreach ($moved['unwritten'] as $row) {
            if (!$row['shipped']) {
                return false;
            }
        }

        return [] === $moved['open'] && [] === $moved['refused'];
    }

    /**
     * The site as the move reads it, or the sentence that stops the run.
     *
     * @return Ready|string
     */
    private function prepare(): array|string
    {
        $composer = $this->requireComposer();
        $manager = Manager::fromComposer($composer);
        $extra = $composer->getPackage()->getExtra();
        if ($manager->isTwo()) {
            return self::ALREADY;
        }
        if (!$manager->isOne()) {
            return self::NO_MANAGER;
        }
        $named = $manager->patchesFile($extra);
        $site = Site::atWorkingDirectory($composer, $this->getIO(), $manager);

        return [
            'composer' => $composer,
            'manager' => $manager,
            'site' => $site,
            'extra' => $extra,
            'file' => '' === $named ? self::PATCHES_FILE : $named,
            'declared' => PatchConfig::declared($extra, $site->root, $manager),
        ];
    }

    /**
     * One service call, which answers both questions the move turns on and re-rolls every strict refusal.
     *
     * A dry run asks for the re-rolls too: what each comes to decides
     * whether the real run finishes.
     *
     * @param Ready $ready
     * @param ?bool $dropTests whether a re-roll leaves test files out, null for the service's default
     */
    private function ask(array $ready, ?bool $dropTests): Plan
    {
        $site = $ready['site'];

        return Client::fromComposer($ready['composer'], $this->getIO())
            ->plan($site->composerJson, $site->composerLock, $site->patches, '', true, dropTests: $dropTests);
    }

    /**
     * @param Ready      $ready
     * @param Wrote|null $wrote what the run wrote, null for a dry run
     *
     * @return MoveReport
     */
    private static function move(array $ready, Plan $plan, ?array $wrote): array
    {
        return [
            'from' => $ready['manager']->version,
            'refused' => self::refused($plan),
            'depths' => self::depths($plan),
            'file' => $ready['file'],
            'renamed' => Settings::renamed($ready['extra']),
            'dropped' => Settings::dropped($ready['extra']),
            'wrote' => $wrote,
            'error' => '',
            'resumed' => false,
            'unchecked' => \count($ready['declared']) - self::judged($plan),
        ];
    }

    /**
     * How many patches came back with a verdict. A patch on a package the request does not carry comes back with none.
     */
    private static function judged(Plan $plan): int
    {
        $count = 0;
        foreach ($plan->patches as $row) {
            if (PatchRow::UNKNOWN !== $row->verdict) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * A move whose documents an earlier run wrote: nothing to ask or write, only the manager's commands to run.
     *
     * @param Ready $ready
     *
     * @return MoveReport
     */
    private static function resumed(array $ready, bool $dryRun): array
    {
        return [
            'from' => $ready['manager']->version,
            'refused' => [],
            'depths' => [],
            'file' => $ready['file'],
            'renamed' => [],
            'dropped' => [],
            'wrote' => $dryRun ? null : ['vendored' => 0, 'rerolled' => 0, 'forcible' => 0, 'files' => [], 'open' => [], 'refused' => []],
            'error' => '',
            'resumed' => true,
            'unchecked' => 0,
        ];
    }

    /**
     * The patches a copy has to land under, which are the ones the service re-rolled.
     *
     * The service decides which patches earn a re-roll, so the run reads its
     * answer rather than judging the verdicts again.
     */
    private static function copying(Plan $plan): Scope
    {
        $out = [];
        foreach ($plan->patches as $row) {
            if (null !== $row->reroll) {
                $out[] = $row->source;
            }
        }

        return [] === $out ? Scope::none() : new Scope([], $out);
    }

    /**
     * The depth to record for each patch that needs one, keyed as the patches file reads them.
     *
     * @return array<string, int>
     */
    private static function measured(Plan $plan): array
    {
        $out = [];
        foreach ($plan->patches as $row) {
            $depth = Settings::depthFor($row->package, $row->appliesAt);
            if (null !== $depth) {
                $out[$row->key()] = $depth;
            }
        }

        return $out;
    }

    /**
     * What the run could not copy or could not write, as one list.
     *
     * @param list<array{title: string, reason: string, lifts: string}> $rows
     *
     * @return list<array{title: string, reason: string, lifts: string}>
     */
    private static function why(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = ['title' => $row['title'], 'reason' => $row['reason'], 'lifts' => $row['lifts']];
        }

        return $out;
    }

    /**
     * How many refusals `--force` takes anyway, which decides whether the report names it.
     *
     * @param list<array{title: string, reason: string, lifts: string}> $rows
     */
    private static function forcible(array $rows): int
    {
        $count = 0;
        foreach ($rows as $row) {
            if ('--force' === $row['lifts']) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * The patches a lenient apply accepts and `git apply` refuses, which is the work the move does, each with what its re-roll comes to.
     *
     * @return list<Refusal>
     */
    private static function refused(Plan $plan): array
    {
        $out = [];
        foreach ($plan->numbered() as [$row, $number]) {
            // A refusal the service's own mirror caused sets no fuzzy, and
            // such a patch applies on this site whatever manager reads it.
            if ($row->fuzzy) {
                $out[] = ['package' => $row->package, 'number' => $number] + self::outcome($row);
            }
        }

        return $out;
    }

    /**
     * Whether a strict refusal's re-roll lets the move write its documents.
     *
     * @param Refusal $row
     */
    public static function lands(array $row): bool
    {
        return \in_array($row['outcome'], ['clean', 'shipped'], true);
    }

    /**
     * What one strict refusal's re-roll comes to, judged the way a real run writes it.
     *
     * @return Outcome
     */
    private static function outcome(PatchRow $row): array
    {
        $broken = $row->rerollSyntaxErrors();

        return match (true) {
            $row->isMerged() => ['outcome' => 'shipped', 'regions' => 0, 'why' => ''],
            $row->rerollIsClean() && [] === $broken => ['outcome' => 'clean', 'regions' => 0, 'why' => ''],
            $row->openRegions() > 0 => ['outcome' => 'open', 'regions' => $row->openRegions(), 'why' => ''],
            [] !== $broken => ['outcome' => 'none', 'regions' => 0, 'why' => Text::t(PatchFiles::UNPARSEABLE, ['@file' => $broken[0]])],
            default => ['outcome' => 'none', 'regions' => 0, 'why' => PatchFiles::whyNoReroll($row)],
        };
    }

    /**
     * The patches whose measured level differs from the depth their package defaults to.
     *
     * @return list<array{package: string, number: int, depth: int}>
     */
    private static function depths(Plan $plan): array
    {
        $out = [];
        foreach ($plan->numbered() as [$row, $number]) {
            $depth = Settings::depthFor($row->package, $row->appliesAt);
            if (null !== $depth) {
                $out[] = ['package' => $row->package, 'number' => $number, 'depth' => $depth];
            }
        }

        return $out;
    }

    /**
     * @return MoveReport
     */
    private static function stopped(string $error): array
    {
        return ['from' => '', 'refused' => [], 'depths' => [], 'file' => '', 'renamed' => [], 'dropped' => [], 'wrote' => null, 'error' => $error, 'resumed' => false, 'unchecked' => 0];
    }
}

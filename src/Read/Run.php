<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Read;

use Composer\Composer;
use Composer\IO\IOInterface;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TresBienTech\Drupatch\Fetch\Candidates;
use TresBienTech\Drupatch\Plan\Plan;
use TresBienTech\Drupatch\Render\Report;
use TresBienTech\Drupatch\Service\Client;
use TresBienTech\Drupatch\Text;

/**
 * One run over the site its command read, and the call it makes: the releases composer would install for the target, and the plan narrowed to the packages asked for.
 *
 * @phpstan-type Coverage array{checked: int, skipped: list<array{package: string, title: string, reason: string}>, unsent: list<array{package: string, title: string, source: string, reason: string}>, versions: array<string, string>}
 */
class Run
{
    /** The word a run uses to ask for the newest core its constraint allows. */
    private const TARGET_LATEST = 'latest';

    /** The site as the service may be told it, read once for this run. */
    public readonly Request $request;

    /**
     * What the run judged, narrowed to its scope: the patches it asks about, the ones it never sent, the ones it sent without their text, and the release each package installs.
     *
     * @var Coverage
     */
    public readonly array $coverage;

    /** The core the plan is about; `latest` becomes a version when composer can name one. */
    public readonly string $target;

    /** @var array<string, string> */
    private readonly array $candidates;

    /**
     * @throws RuntimeException when --patch names a source the site does not declare
     */
    public function __construct(
        public readonly Site $site,
        private readonly Composer $composer,
        private readonly IOInterface $io,
        OutputInterface $notes,
        string $target,
        private readonly Scope $scope,
        /** Whether a re-roll leaves test files out, null for the service's default. */
        public readonly ?bool $dropTests = null,
    ) {
        $this->request = $site->request();
        foreach ($this->request->patches->notes as $note) {
            $notes->writeln(Report::message($note, 'comment'));
        }
        $declared = \array_column($this->request->patches->patches, 'source');
        $unknown = $scope->unknownSources($declared);
        if ([] !== $unknown) {
            throw new RuntimeException(Text::t('no patch is declared from @named; this site declares @declared', ['@named' => \implode(', ', $unknown), '@declared' => [] === $declared ? 'none' : \implode(', ', $declared)]));
        }
        $config = $this->request->patches;
        $this->coverage = [
            'checked' => \count(\array_filter($config->patches, static fn (Declaration $patch): bool => $scope->has($patch->package, $patch->source))),
            // A package outside the scope is not the run's business. A patch
            // sent without its text is noted under its own package, and the
            // transport check reads them all.
            'skipped' => \array_values(\array_filter($config->skipped, static fn (array $entry): bool => $scope->hasPackage($entry['package']))),
            'unsent' => $config->unsent,
            'versions' => $this->request->installed,
        ];
        // A bare run judges what the lock installs, so there is no
        // candidate to resolve and no repository to ask.
        $this->candidates = '' === $target ? [] : $this->resolve($target, $notes);
        $this->target = $target;
    }

    /**
     * The request the run would send.
     *
     * @param array<int, list<array<string, mixed>>> $decided regions decided, by patch position
     *
     * @return array<string, mixed>
     */
    public function body(bool $reroll, array $decided): array
    {
        return Client::body($this->request, $this->target, $reroll, $this->candidates, $decided, $this->dropTests);
    }

    /**
     * Asks the service, and reads the answer under the site's patch manager and through the packages the run named.
     *
     * @param array<int, list<array<string, mixed>>> $decided regions decided, by patch position
     *
     * @throws RuntimeException when a named package declares no patch
     */
    public function plan(bool $reroll, array $decided): Plan
    {
        // The whole site is sent because the server needs the whole lock;
        // the plan reads its rows through the scope, so everything after
        // this is about the packages that were asked for.
        $plan = Client::fromComposer($this->composer, $this->io)
            ->plan($this->request, $this->site->manager, $this->scope, $this->target, $reroll, $this->candidates, $decided, $this->dropTests);
        if (!$this->scope->isWhole() && !$plan->hasPatches()) {
            $declared = $plan->packages();
            throw new RuntimeException(Text::t('no patch is declared for @named; this site declares patches for @declared', ['@named' => \implode(', ', $this->scope->packages), '@declared' => [] === $declared ? 'nothing' : \implode(', ', $declared)]));
        }

        return $plan;
    }

    /**
     * What composer would install for each patched package, or nothing when it cannot say.
     *
     * @return array<string, string>
     */
    private function resolve(string &$target, OutputInterface $notes): array
    {
        $resolver = null;
        try {
            $resolver = Candidates::forSite($this->composer);
            $out = $this->resolveCandidates($resolver, $target);
        } catch (Throwable $e) {
            $notes->writeln(Report::message(Text::t('composer could not say which releases the target installs: @why', ['@why' => $e->getMessage()]), 'comment'));
            $out = [];
        }
        if (null !== $resolver) {
            foreach ($resolver->notes() as $note) {
                $notes->writeln(Report::message($note, 'comment'));
            }
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function resolveCandidates(Candidates $resolver, string &$target): array
    {
        // `latest` is a question. Composer answers questions about a
        // release, so it is resolved to one here: otherwise every
        // constraint is compared against the word and refuses, and the run
        // silently falls back to the service's own release table.
        if (self::TARGET_LATEST === $target) {
            $resolved = $resolver->coreTarget($this->request->constraints);
            if ('' === $resolved) {
                // The site requires no core package. The service falls
                // back to the installed core and says so; nothing here can
                // pick candidates for a core nobody named.
                return [];
            }
            $target = $resolved;
        }
        $wanted = [];
        $constraints = $this->request->constraints;
        foreach ($this->request->patches->patches as $patch) {
            $package = $patch->package;
            $wanted[$package] = $constraints[$package] ?? '';
        }
        if ([] === $wanted) {
            return [];
        }

        return $resolver->forTarget($target, $wanted);
    }
}

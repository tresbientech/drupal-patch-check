<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch;

use Composer\Composer;
use Composer\IO\IOInterface;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TresBienTech\Drupatch\Plan\Plan;
use TresBienTech\Drupatch\Render\Coverage;

/**
 * One run's site and the call it makes: the site as read, the releases composer would install for the target, and the plan narrowed to the packages asked for.
 */
class Run
{
    /** The word a run uses to ask for the newest core its constraint allows. */
    private const TARGET_LATEST = 'latest';

    public readonly Site $site;

    public readonly Coverage $coverage;

    /** The core the plan is about; `latest` becomes a version when composer can name one. */
    public readonly string $target;

    /** @var array<string, string> */
    private readonly array $candidates;

    /** @var array<string, string> */
    private readonly array $declared;

    /**
     * @throws RuntimeException when --patch names a source the site does not declare
     */
    public function __construct(
        private readonly Composer $composer,
        private readonly IOInterface $io,
        OutputInterface $notes,
        string $target,
        private readonly Scope $scope,
    ) {
        $this->site = Site::atWorkingDirectory($composer, $io);
        foreach ($this->site->patches()->notes as $note) {
            $notes->writeln('<comment>drupatch: '.$note.'</comment>');
        }
        $declared = \array_column($this->site->patches()->patches, 'source');
        $unknown = $scope->unknownSources($declared);
        if ([] !== $unknown) {
            throw new RuntimeException(\sprintf('no patch is declared from %s; this site declares %s', \implode(', ', $unknown), [] === $declared ? 'none' : \implode(', ', $declared)));
        }
        $this->coverage = Coverage::of($this->site, $scope);
        // A bare run judges what the lock installs, so there is no
        // candidate to resolve and no repository to ask.
        $this->candidates = '' === $target ? [] : $this->resolve($target, $notes);
        $this->target = $target;
        // What the site has on disk says what it supports, whatever the
        // service's copy of the release data knows.
        $this->declared = Candidates::declaredCore($composer, $this->site->checkable());
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
        return Client::body($this->site->composerJson(), $this->site->composerLock(), $this->site->patches(), $this->target, $reroll, $this->candidates, $this->declared, $decided);
    }

    /**
     * Asks the service and narrows the answer to the packages the run named.
     *
     * @param array<int, list<array<string, mixed>>> $decided regions decided, by patch position
     *
     * @throws RuntimeException when a named package declares no patch
     */
    public function plan(bool $reroll, array $decided): Plan
    {
        $plan = Client::fromComposer($this->composer, $this->io)
            ->plan($this->site->composerJson(), $this->site->composerLock(), $this->site->patches(), $this->target, $reroll, $this->candidates, $this->declared, $decided);
        // The whole site is sent because the server needs the whole lock;
        // the narrowing happens here, so everything after it is about the
        // packages that were asked for.
        if ($this->scope->isWhole()) {
            return $plan;
        }
        $declared = $plan->packages();
        $narrowed = $plan->only($this->scope);
        if (!$narrowed->hasPatches()) {
            throw new RuntimeException(\sprintf('no patch is declared for %s; this site declares patches for %s', \implode(', ', $this->scope->packages), [] === $declared ? 'nothing' : \implode(', ', $declared)));
        }

        return $narrowed;
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
            $notes->writeln('<comment>drupatch: composer could not say which releases the target installs: '.$e->getMessage().'</comment>');
            $out = [];
        }
        if (null !== $resolver) {
            foreach ($resolver->notes() as $note) {
                $notes->writeln('<comment>drupatch: '.$note.'</comment>');
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
            $resolved = $resolver->coreTarget($this->site->constraints());
            if ('' === $resolved) {
                // The site requires no core package. The service falls
                // back to the installed core and says so; nothing here can
                // pick candidates for a core nobody named.
                return [];
            }
            $target = $resolved;
        }
        $wanted = [];
        $constraints = $this->site->constraints();
        foreach ($this->site->patches()->patches as $patch) {
            $package = $patch['package'];
            $wanted[$package] = $constraints[$package] ?? '';
        }
        if ([] === $wanted) {
            return [];
        }

        return $resolver->forTarget($target, $wanted);
    }
}

<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Fetch;

use TresBienTech\Drupatch\Manager;
use TresBienTech\Drupatch\Read\Scope;
use TresBienTech\Drupatch\Source\Commit;
use TresBienTech\Drupatch\Source\Header;
use TresBienTech\Drupatch\Source\MergeRequest;
use TresBienTech\Drupatch\Source\Provenance;
use TresBienTech\Drupatch\Source\UrlPatch;
use TresBienTech\Drupatch\Write\Copied;
use TresBienTech\Drupatch\Write\SiteFile;
use TresBienTech\Drupatch\Write\WorkingTree;

/**
 * Copies the patches a site declares as a URL into the site: a merge request between the two commits it names, a commit as its URL already names it, and any other URL as it stands.
 *
 * @phpstan-type CopiedRow array{package: string, title: string, source: string, path: string, provenance: array<string, string>}
 * @phpstan-type RefusedRow array{package: string, title: string, source: string, reason: string, lifts: string}
 */
class Vendoring
{
    /** Where a copy goes when the site names no directory. Singular, so it stays apart from a `patches` directory the site manages by hand. */
    public const DIRECTORY = 'patch';

    /** @var array<string, array{base: string, head: string}|string> the commits of each request the run asked about, or why it could not read them */
    private array $commits = [];

    /**
     * @param PatchText        $text      reads over the site's own network, and keeps what it read
     * @param string           $directory where a copy is written, relative to the site root
     * @param Manager          $manager   decides whether a copy's provenance can live on its declaration
     * @param WorkingTree|null $tree      what git says about a file this run would replace, null to replace it anyway
     */
    public function __construct(
        private readonly string $root,
        private readonly PatchText $text,
        private readonly string $directory,
        private readonly Manager $manager,
        private readonly ?WorkingTree $tree = null,
    ) {
    }

    /**
     * Copies every declaration in scope that names a URL.
     *
     * @param list<array{package: string, title: string, source: string, provenance: array<string, string>}> $declarations
     * @param bool                                                                                           $refresh      take the new bytes of a request that moved since the site copied it
     */
    public function run(array $declarations, Scope $scope, bool $dryRun, bool $refresh = false): Copied
    {
        $vendored = [];
        $kept = [];
        $moved = [];
        $refused = [];
        foreach ($declarations as $declaration) {
            $upstream = self::upstreamOf($declaration, $refresh);
            if (null === $upstream || !$scope->has($declaration['package'], $declaration['source'])) {
                continue;
            }
            $path = $upstream->file($this->directory);
            $held = \is_file($this->root.\DIRECTORY_SEPARATOR.$path);
            // A commit pins its own bytes, so a copy of one is done. A merge
            // request is asked about, because somebody may have pushed to it.
            if ($held && (!$upstream instanceof MergeRequest || !$this->hasMoved($upstream, $path, $declaration['provenance']))) {
                $kept[] = self::row($declaration, $path, $this->lift($path, $dryRun));
                continue;
            }
            if ($held && !$refresh) {
                $moved[] = self::row($declaration, $path, $this->lift($path, $dryRun));
                continue;
            }
            $reason = $held ? $this->replaceable($path) : '';
            // Git stopped this one, and `--force` takes it anyway. Every
            // other refusal below stands whatever flags the run was given.
            $guarded = '' !== $reason;
            $provenance = [];
            if ('' === $reason) {
                [$reason, $provenance] = $upstream instanceof MergeRequest
                    ? $this->fromRequest($upstream, $path, $dryRun)
                    : $this->fromUrl($upstream, $path, $dryRun);
            }
            if ('' === $reason) {
                $vendored[] = self::row($declaration, $path, $provenance);
                continue;
            }
            $refused[] = ['package' => $declaration['package'], 'title' => $declaration['title'], 'source' => $declaration['source'], 'reason' => $reason, 'lifts' => $guarded ? '--force' : ''];
        }

        return Copied::after($declarations, $vendored, $kept, $moved, $refused);
    }

    /**
     * Where one declaration's bytes come from: the source, when it names a merge request, a commit or a URL.
     *
     * A copy the site already holds names a file, so the source says nothing
     * about where its bytes came from. The record its declaration carries
     * does, and a refresh run reads it to reach the merge request again. A
     * commit pins its own bytes and drupal.org does not rewrite an uploaded
     * file, so a merge request is the only source worth asking about twice.
     *
     * @param array{package: string, title: string, source: string, provenance: array<string, string>} $declaration
     */
    private static function upstreamOf(array $declaration, bool $refresh): MergeRequest|Commit|UrlPatch|null
    {
        $found = MergeRequest::of($declaration['source'])
            ?? Commit::of($declaration['source'])
            ?? UrlPatch::of($declaration['source'], $declaration['package']);
        if (null !== $found || !$refresh) {
            return $found;
        }
        $recorded = $declaration['provenance']['mr'] ?? '';

        return '' === $recorded ? null : MergeRequest::of($recorded.'.diff');
    }

    /**
     * One declaration with what the run found for it.
     *
     * @param array{package: string, title: string, source: string, provenance: array<string, string>} $declaration
     * @param array<string, string>                                                                    $provenance  what the run recorded about the copy, which replaces what the declaration held
     *
     * @return CopiedRow
     */
    private static function row(array $declaration, string $path, array $provenance): array
    {
        return [
            'package' => $declaration['package'],
            'title' => $declaration['title'],
            'source' => $declaration['source'],
            'path' => $path,
            'provenance' => $provenance,
        ];
    }

    /**
     * Whether the request holds commits the site's copy does not. A copy that records no head commit is left alone, since nothing says what it holds.
     *
     * @param array<string, string> $declared what the declaration records about the copy
     */
    private function hasMoved(MergeRequest $request, string $path, array $declared): bool
    {
        $head = $declared['head'] ?? Header::read((string) @\file_get_contents($this->root.\DIRECTORY_SEPARATOR.$path))['head'] ?? null;
        if (null === $head) {
            return false;
        }
        $commits = $this->commitsOf($request);

        return \is_array($commits) && $commits['head'] !== $head;
    }

    /**
     * The record of a copy the run is keeping. An old `# drupatch` line is moved onto the declaration and cut from the file, so a repository heals on first use; on 1.x the line stays, because a compact declaration holds no `extra`.
     *
     * @return array<string, string>
     */
    private function lift(string $path, bool $dryRun): array
    {
        $full = $this->root.\DIRECTORY_SEPARATOR.$path;
        $text = (string) @\file_get_contents($full);
        $header = Provenance::of(Header::read($text));
        if ([] === $header || !$this->manager->isTwo()) {
            return [];
        }
        if (!$dryRun) {
            @\file_put_contents($full, Header::body($text));
        }

        return $header;
    }

    /**
     * The commits a request is taken between, or why they could not be read. Asked once per request, so the moved check and the copy that follows it share one answer.
     *
     * @return array{base: string, head: string}|string
     */
    private function commitsOf(MergeRequest $request): array|string
    {
        return $this->commits[$request->url] ??= $this->readCommits($request);
    }

    /**
     * @return array{base: string, head: string}|string
     */
    private function readCommits(MergeRequest $request): array|string
    {
        $decoded = $this->text->askJson($request->api());
        if (\is_string($decoded)) {
            return $decoded;
        }
        $base = $decoded['diff_refs']['base_sha'] ?? null;
        $head = $decoded['diff_refs']['head_sha'] ?? null;

        // GitLab fills the commits in after a request is opened, and a
        // request whose branch is gone holds none.
        return \is_string($base) && \is_string($head)
            ? ['base' => $base, 'head' => $head]
            : 'the merge request names no commits yet';
    }

    /**
     * Why the file the site holds may not be replaced, empty when it may.
     */
    private function replaceable(string $path): string
    {
        return null === $this->tree ? '' : $this->tree->refusal($this->root, $path);
    }

    /**
     * Copies a merge request between the two commits it names, or says why it did not.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function fromRequest(MergeRequest $request, string $path, bool $dryRun): array
    {
        // The commits are read first, so the diff is asked for between two of
        // them and the record describes the bytes the file holds.
        $commits = $this->commitsOf($request);
        if (\is_string($commits)) {
            return [$commits, []];
        }

        return $this->put($path, $request->compare($commits['base'], $commits['head']), ['mr' => $request->url] + $commits, $dryRun);
    }

    /**
     * Copies what one URL serves: a commit, which the URL pins already, or a file somebody uploaded.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function fromUrl(Commit|UrlPatch $upstream, string $path, bool $dryRun): array
    {
        $provenance = $upstream instanceof Commit ? ['commit' => $upstream->sha] : ['url' => $upstream->url];

        return $this->put($path, $upstream->diff(), $provenance, $dryRun);
    }

    /**
     * Reads one diff and writes it, and reports where its bytes came from. A reason means nothing was written.
     *
     * @param array<string, string> $provenance where the bytes came from
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function put(string $path, string $url, array $provenance, bool $dryRun): array
    {
        $read = $this->text->read($url);
        if ('' !== $read['reason']) {
            return [$read['reason'], []];
        }
        $body = $read['files'][$url] ?? '';
        $record = Provenance::of($provenance + ['fetched' => \date('Y-m-d')]);
        if ($dryRun) {
            return ['', $record];
        }

        // The path is built from the source and the site's own directory
        // setting, so it stays under the root.
        return SiteFile::put($this->root.\DIRECTORY_SEPARATOR.$path, $body) ? ['', $record] : [$path.' could not be written', []];
    }
}

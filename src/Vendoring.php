<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch;

use TresBienTech\Drupatch\Write\WorkingTree;

/**
 * Copies the patches a site declares as a URL into the site: a merge request between the two commits it names, a commit as its URL already names it, and any other URL as it stands.
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
     * @param WorkingTree|null $tree      what git says about a file this run would replace, null to replace it anyway
     */
    public function __construct(
        private readonly string $root,
        private readonly PatchText $text,
        private readonly string $directory,
        private readonly ?WorkingTree $tree = null,
    ) {
    }

    /**
     * Copies every declaration in scope that names a URL.
     *
     * @param list<array{package: string, title: string, source: string}> $declarations
     * @param bool                                                        $refresh      take the new bytes of a request that moved since the site copied it
     *
     * @return array{vendored: list<array{package: string, title: string, source: string, path: string}>, kept: list<array{package: string, title: string, source: string, path: string}>, moved: list<array{package: string, title: string, source: string, path: string}>, refused: list<array{package: string, title: string, source: string, reason: string}>}
     */
    public function run(array $declarations, Scope $scope, bool $dryRun, bool $refresh = false): array
    {
        $vendored = [];
        $kept = [];
        $moved = [];
        $refused = [];
        foreach ($declarations as $declaration) {
            $upstream = MergeRequest::of($declaration['source'])
                ?? Commit::of($declaration['source'])
                ?? UrlPatch::of($declaration['source'], $declaration['package']);
            if (null === $upstream || !$scope->has($declaration['package'], $declaration['source'])) {
                continue;
            }
            $path = $upstream->file($this->directory);
            $held = \is_file($this->root.\DIRECTORY_SEPARATOR.$path);
            // A commit pins its own bytes, so a copy of one is done. A merge
            // request is asked about, because somebody may have pushed to it.
            if ($held && (!$upstream instanceof MergeRequest || !$this->hasMoved($upstream, $path))) {
                $kept[] = $declaration + ['path' => $path];
                continue;
            }
            if ($held && !$refresh) {
                $moved[] = $declaration + ['path' => $path];
                continue;
            }
            $reason = $held ? $this->replaceable($path) : '';
            if ('' === $reason) {
                $reason = $upstream instanceof MergeRequest
                    ? $this->fromRequest($upstream, $path, $dryRun)
                    : $this->fromUrl($upstream, $path, $dryRun);
            }
            if ('' === $reason) {
                $vendored[] = $declaration + ['path' => $path];
                continue;
            }
            $refused[] = $declaration + ['reason' => $reason];
        }

        return ['vendored' => $vendored, 'kept' => $kept, 'moved' => $moved, 'refused' => $refused];
    }

    /**
     * Whether the request holds commits the site's copy does not. A copy whose header cannot be read is left alone, since nothing says what it holds.
     */
    private function hasMoved(MergeRequest $request, string $path): bool
    {
        $header = Header::read((string) @\file_get_contents($this->root.\DIRECTORY_SEPARATOR.$path));
        if (!isset($header['head'])) {
            return false;
        }
        $commits = $this->commitsOf($request);

        return \is_array($commits) && $commits['head'] !== $header['head'];
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
        $answer = $this->text->ask($request->api());
        if (\is_string($answer)) {
            return $answer;
        }
        $decoded = \json_decode($answer['body'], true);
        $base = \is_array($decoded) ? ($decoded['diff_refs']['base_sha'] ?? null) : null;
        $head = \is_array($decoded) ? ($decoded['diff_refs']['head_sha'] ?? null) : null;

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
     */
    private function fromRequest(MergeRequest $request, string $path, bool $dryRun): string
    {
        // The commits are read first, so the diff is asked for between two of
        // them and the header describes the bytes the file holds.
        $commits = $this->commitsOf($request);
        if (\is_string($commits)) {
            return $commits;
        }

        return $this->put($path, $request->compare($commits['base'], $commits['head']), ['mr' => $request->url] + $commits, $dryRun);
    }

    /**
     * Copies what one URL serves: a commit, which the URL pins already, or a file somebody uploaded.
     */
    private function fromUrl(Commit|UrlPatch $upstream, string $path, bool $dryRun): string
    {
        $provenance = $upstream instanceof Commit ? ['commit' => $upstream->sha] : ['url' => $upstream->url];

        return $this->put($path, $upstream->diff(), $provenance, $dryRun);
    }

    /**
     * Reads one diff and writes it under its header, or says why nothing was written.
     *
     * @param array<string, string> $provenance where the bytes came from
     */
    private function put(string $path, string $url, array $provenance, bool $dryRun): string
    {
        $read = $this->text->read($url);
        if ('' !== $read['reason']) {
            return $read['reason'];
        }
        if ($dryRun) {
            return '';
        }
        $body = $read['files'][$url] ?? '';
        $file = Header::line($provenance + ['fetched' => \date('Y-m-d'), 'sha256' => Header::hash($body)]).$body;
        $full = $this->root.\DIRECTORY_SEPARATOR.$path;
        // The path is built from the source and the site's own directory
        // setting, so it stays under the root.
        if (!@\mkdir(\dirname($full), 0o777, true) && !\is_dir(\dirname($full))) {
            return $path.' could not be written';
        }

        return false === @\file_put_contents($full, $file) ? $path.' could not be written' : '';
    }
}

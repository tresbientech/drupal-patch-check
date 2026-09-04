<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch;

use Closure;
use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Util\HttpDownloader;
use Throwable;

/**
 * The text of one declared patch, read from the site's disk or fetched from the host the site named.
 */
class PatchText
{
    /** Largest patch taken, from disk or from a host. */
    public const MAX_BYTES = 16 * 1024 * 1024;

    /** This plugin's subdirectory of composer's cache, shared with the install notice marker. */
    public const CACHE_DIR = 'drupatch';

    /** How long a fetched patch is reused. */
    private const CACHE_SECONDS = 86400;

    /** How long one fetch may take. */
    private const TIMEOUT_SECONDS = 30;

    /**
     * @param Closure(string): array{status: int, body: string} $fetch
     * @param string                                            $cacheDir where fetched patches are kept, empty to keep none
     */
    public function __construct(
        private readonly string $root,
        private readonly Closure $fetch,
        private readonly string $cacheDir,
    ) {
    }

    /**
     * Reads through composer, so the credentials, proxy and TLS settings the site already has apply.
     */
    public static function fromComposer(Composer $composer, IOInterface $io, string $root): self
    {
        $downloader = new HttpDownloader($io, $composer->getConfig());
        $cache = (string) $composer->getConfig()->get('cache-dir');

        return new self($root, static function (string $url) use ($downloader): array {
            $response = $downloader->get($url, [
                'http' => ['method' => 'GET', 'timeout' => self::TIMEOUT_SECONDS],
                'max_file_size' => self::MAX_BYTES + 1,
                'retry-auth-failure' => false,
            ]);

            return ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody()];
        }, '' === $cache ? '' : $cache.\DIRECTORY_SEPARATOR.self::CACHE_DIR);
    }

    /**
     * Every text one declared source yields, keyed by the source it travels under, or why not.
     *
     * `withheld` separates a patch the run holds back from one it could not
     * get at all: the first keeps its row and its verdict, the second is a
     * skipped patch.
     *
     * @return array{files: array<string, string>, reason: string, withheld: bool}
     */
    public function read(string $source): array
    {
        return PatchConfig::isUrl($source) ? $this->fromHost($source) : $this->fromDisk($source);
    }

    /**
     * @return array{files: array<string, string>, reason: string, withheld: bool}
     */
    private function fromDisk(string $source): array
    {
        $full = self::under($this->root, $source);
        $size = null === $full ? false : \filesize($full);
        if (null === $full || false === $size) {
            return self::refused('no file at that path');
        }
        if ($size > self::MAX_BYTES) {
            return self::held('above the '.(self::MAX_BYTES >> 20).' MB cap');
        }
        $text = @\file_get_contents($full);

        return false === $text ? self::refused('the file could not be read') : self::got([$source => $text]);
    }

    /**
     * The merge request a source came from, empty when it came from somewhere else.
     */
    public static function mergeRequest(string $source): string
    {
        $mr = '#^(https://git\.drupalcode\.org/project/[^/]+/-/merge_requests/\d+)\.(patch|diff)$#';

        return 1 === \preg_match($mr, \trim($source), $found) ? $found[1] : '';
    }

    /**
     * Where the fix for a patch taken from a URL belongs: its merge request, or the drupal.org issue whose number the file name carries, or nothing when the URL says neither.
     */
    public static function upstream(string $source): string
    {
        $request = self::mergeRequest($source);
        if ('' !== $request) {
            return $request;
        }
        $path = \parse_url(\trim($source), \PHP_URL_PATH);
        // An issue number is seven digits, and file names put it at the
        // start, the end or the middle; about half carry none.
        if (\is_string($path) && 1 === \preg_match('/(?<!\d)(\d{7})(?!\d)/', \basename($path), $found)) {
            return 'https://www.drupal.org/i/'.$found[1];
        }

        return '';
    }

    /**
     * The squashed form of a merge request patch URL, empty when the source is not one.
     *
     * A series applies its later diffs onto blobs its earlier commits left
     * markers in. The merge request's own diff carries one diff per file
     * and cannot, so a merge runs on that instead.
     */
    public static function sibling(string $source): string
    {
        $request = self::mergeRequest($source);

        return '' !== $request && \str_ends_with(\trim($source), '.patch') ? $request.'.diff' : '';
    }

    /**
     * The patch a URL names, with the squashed form beside it when the URL is a merge request.
     *
     * @return array{files: array<string, string>, reason: string, withheld: bool}
     */
    private function fromHost(string $url): array
    {
        $main = $this->one($url);
        $sibling = self::sibling($url);
        if ('' !== $main['reason'] || '' === $sibling) {
            return $main;
        }
        $squashed = $this->one($sibling);

        // The squashed form only improves a merge. Without it the declared
        // form still merges, less cleanly.
        return '' === $squashed['reason'] ? self::got($main['files'] + $squashed['files']) : $main;
    }

    /**
     * @return array{files: array<string, string>, reason: string, withheld: bool}
     */
    private function one(string $url): array
    {
        $cached = $this->cached($url);
        if (null !== $cached) {
            return self::got([$url => $cached]);
        }
        try {
            $answer = ($this->fetch)($url);
        } catch (Throwable $e) {
            return self::refused('it could not be reached: '.$e->getMessage());
        }
        if (200 !== $answer['status']) {
            return self::refused('the host answered '.$answer['status']);
        }
        if (\strlen($answer['body']) > self::MAX_BYTES) {
            return self::held('above the '.(self::MAX_BYTES >> 20).' MB cap');
        }
        if (!self::isDiff($answer['body'])) {
            return self::refused('what came back is not a diff');
        }
        $this->keep($url, $answer['body']);

        return self::got([$url => $answer['body']]);
    }

    /**
     * Whether the bytes a host returned are a patch. The boundary: a login page and an error page both arrive with a 200.
     */
    public static function isDiff(string $text): bool
    {
        foreach (\explode("\n", \str_replace(["\r\n", "\r"], "\n", $text)) as $line) {
            if (\str_starts_with($line, 'diff --git ') || \str_starts_with($line, '+++ ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The patch kept for this URL, or null when none is held or the one held is a day old.
     */
    private function cached(string $url): ?string
    {
        if ('' === $this->cacheDir) {
            return null;
        }
        $at = @\filemtime($this->kept($url));
        if (false === $at || $at < \time() - self::CACHE_SECONDS) {
            return null;
        }
        $text = @\file_get_contents($this->kept($url));

        return false === $text ? null : $text;
    }

    private function keep(string $url, string $text): void
    {
        if ('' === $this->cacheDir) {
            return;
        }
        @\mkdir($this->cacheDir, 0o777, true);
        @\file_put_contents($this->kept($url), $text);
    }

    /**
     * Where this URL's patch is kept.
     */
    private function kept(string $url): string
    {
        return $this->cacheDir.\DIRECTORY_SEPARATOR.\hash('sha256', $url).'.patch';
    }

    /**
     * @param array<string, string> $files
     *
     * @return array{files: array<string, string>, reason: string, withheld: bool}
     */
    private static function got(array $files): array
    {
        return ['files' => $files, 'reason' => '', 'withheld' => false];
    }

    /**
     * A source the run could not turn into a patch.
     *
     * @return array{files: array<string, string>, reason: string, withheld: bool}
     */
    private static function refused(string $reason): array
    {
        return ['files' => [], 'reason' => $reason, 'withheld' => false];
    }

    /**
     * A patch the run has and does not send.
     *
     * @return array{files: array<string, string>, reason: string, withheld: bool}
     */
    private static function held(string $reason): array
    {
        return ['files' => [], 'reason' => $reason, 'withheld' => true];
    }

    /**
     * A declared path as a real file under the site root, or null. A patch declaration is site input, so a path leaving the root is refused here.
     */
    public static function under(string $root, string $source): ?string
    {
        $path = \realpath($root.\DIRECTORY_SEPARATOR.\ltrim($source, '/\\'));
        if (false === $path || !\is_file($path)) {
            return null;
        }

        return \str_starts_with($path, $root.\DIRECTORY_SEPARATOR) ? $path : null;
    }
}

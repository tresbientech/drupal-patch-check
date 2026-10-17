<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Service;

use Composer\Composer;
use Composer\Downloader\TransportException;
use Composer\InstalledVersions;
use Composer\IO\IOInterface;
use Composer\Util\HttpDownloader;
use OutOfBoundsException;
use RuntimeException;
use Throwable;
use TresBienTech\Drupatch\Fetch\PatchText;
use TresBienTech\Drupatch\Manager;
use TresBienTech\Drupatch\Plan\Plan;
use TresBienTech\Drupatch\Read\Request;
use TresBienTech\Drupatch\Read\Scope;
use TresBienTech\Drupatch\Source\IssueReference;
use TresBienTech\Drupatch\Source\MergeRequest;
use TresBienTech\Drupatch\Source\UrlPatch;
use TresBienTech\Drupatch\Text;

/**
 * Asks the api for one upgrade plan, sending only what the service can check.
 */
class Client
{
    /** The package name composer knows this plugin by. */
    public const PACKAGE = 'tresbientech/drupal-patch-check';

    /** What agent() stands in for a release with, so no version literal lives here to fall behind the tag. */
    public const UNKNOWN_VERSION = 'unknown';

    public const DEFAULT_ENDPOINT = 'https://api.tresbien.tech/v1/composer/scan';

    /** Whole-request budget. */
    private const TIMEOUT_SECONDS = 15;

    /** Largest plan accepted. */
    private const MAX_RESPONSE_BYTES = 64 * 1024 * 1024;

    /** The repository that serves drupal.org releases. */
    private const DRUPAL_NOTIFICATION = 'https://packages.drupal.org/8/downloads';

    /** The provenance keys a request always carries. `url` joins them only on drupal.org's hosts, since a URL elsewhere can point at a private host. */
    private const PROVENANCE_SENT = ['mr', 'commit', 'base'];

    /** The composer type drupal.org gives a sub-module. */
    private const METAPACKAGE = 'metapackage';

    public function __construct(
        private readonly HttpDownloader $downloader,
        private readonly string $endpoint = self::DEFAULT_ENDPOINT,
    ) {
    }

    /**
     * The endpoint comes from the environment, never from composer.json: a site's
     * own config must not be able to redirect where its patches are sent.
     */
    public static function fromComposer(Composer $composer, IOInterface $io): self
    {
        $endpoint = \getenv('DRUPATCH_ENDPOINT');
        if (!\is_string($endpoint) || '' === $endpoint) {
            $endpoint = self::DEFAULT_ENDPOINT;
        }

        return new self(new HttpDownloader($io, $composer->getConfig()), $endpoint);
    }

    /**
     * What the request says it is: this plugin's name and the release
     * composer installed. The service logs it as `client_version` and
     * judges nothing by it. A checkout reports its branch rather than a
     * version, so a working copy is distinguishable from a release.
     */
    public static function agent(): string
    {
        try {
            $version = InstalledVersions::getPrettyVersion(self::PACKAGE);
        } catch (OutOfBoundsException) {
            // Not registered: a path repository, or an install layout that
            // never wrote the package into installed.php.
            $version = null;
        }

        return 'drupal-patch-check/'.($version ?? self::UNKNOWN_VERSION);
    }

    /**
     * The two composer documents as the service receives them: the keys it reads, narrowed to what it can judge, and every package the lock names.
     *
     * @param array<mixed> $composerJson the site's composer.json, decoded once per run
     *
     * @return array{json: string, lock: string, packages: array<string, string>, locked: array<string, string>}
     */
    public static function filter(array $composerJson, string $composerLock): array
    {
        $lock = self::decode($composerLock);

        $packages = [];
        $locked = [];
        $slim = [];
        foreach (['packages', 'packages-dev'] as $section) {
            $entries = $lock[$section] ?? null;
            if (!\is_array($entries)) {
                continue;
            }
            foreach ($entries as $entry) {
                if (!\is_array($entry)) {
                    continue;
                }
                $name = $entry['name'] ?? '';
                $version = $entry['version'] ?? '';
                if (!\is_string($name) || '' === $name || !\is_string($version) || '' === $version) {
                    continue;
                }
                // Recorded before the test, so a package the service
                // cannot judge can still be named with its release.
                $locked[$name] = $version;
                if (!self::isCheckable($name, $entry)) {
                    continue;
                }
                $packages[$name] = $version;
                $slim[$section][] = self::entry($entry, $name, $version);
            }
        }

        return [
            'json' => self::encode(self::filterJson($composerJson, $packages)),
            'lock' => self::encode($slim),
            'packages' => $packages,
            'locked' => $locked,
        ];
    }

    /**
     * @param Manager|null                           $manager     the patch manager the answer is read under, null to read it as the service sent it
     * @param Scope                                  $scope       what the run acts on, which the plan reads its rows through
     * @param array<string, string>                  $candidates  composer name to the release composer would install
     * @param array<int, list<array<string, mixed>>> $resolutions regions a person decided, by patch position
     * @param bool|null                              $dropTests   whether a re-roll leaves out the files the release package omits, null for the service's default
     *
     * @throws RuntimeException when the call or the answer failed
     */
    public function plan(Request $request, ?Manager $manager, Scope $scope, string $targetCore = '', bool $reroll = false, array $candidates = [], array $resolutions = [], ?bool $dropTests = null): Plan
    {
        $body = \json_encode(self::body($request, $targetCore, $reroll, $candidates, $resolutions, $dropTests), \JSON_THROW_ON_ERROR);

        try {
            $response = $this->downloader->get($this->endpoint, [
                'http' => [
                    'method' => 'POST',
                    'header' => ['Content-Type: application/json', 'Accept: application/json'],
                    'content' => $body,
                    'timeout' => self::TIMEOUT_SECONDS,
                ],
                'max_file_size' => self::MAX_RESPONSE_BYTES,
                // A 401 or 403 is reported like any other status; composer
                // does not ask for credentials.
                'retry-auth-failure' => false,
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException($this->reason($e), 0, $e);
        }

        $decoded = \json_decode((string) $response->getBody(), true);
        if (!\is_array($decoded)) {
            throw new RuntimeException('the service answered with something that is not JSON');
        }

        return Plan::fromArray($decoded, $request->patches->patches, $manager, $scope);
    }

    /**
     * Everything the request carries, in one place, so `--dry-run` shows the body rather than a description of it.
     *
     * @param array<string, string>                  $candidates
     * @param array<int, list<array<string, mixed>>> $resolutions regions a person decided, by patch position
     * @param bool|null                              $dropTests   whether a re-roll leaves out the files the release package omits, null for the service's default
     *
     * @return array<string, mixed>
     */
    public static function body(Request $request, string $targetCore = '', bool $reroll = false, array $candidates = [], array $resolutions = [], ?bool $dropTests = null): array
    {
        $patches = $request->patches;
        $config = [];
        foreach ($patches->patches as $i => $patch) {
            $source = $patch->source;
            // A path names a client, a ticket and a project, and the service
            // reads a package, a version and a patch text. So the entry holds
            // its own text and nothing the site wrote.
            $entry = ['package' => $patch->package];
            if (isset($patches->files[$source])) {
                $entry['patch'] = $patches->files[$source];
            }
            $sibling = PatchText::sibling($source);
            if ('' !== $sibling && isset($patches->files[$sibling])) {
                $entry['merge_patch'] = $patches->files[$sibling];
            }
            // A merge request names nothing of the site, and the service
            // reads it to say whether the release already holds the change.
            $upstream = MergeRequest::of($source);
            if (null !== $upstream) {
                $entry['upstream'] = $upstream->url;
            }
            // Where a vendored patch's bytes came from, which is how the
            // service reaches the merge request behind a local file.
            $sent = \array_flip(self::PROVENANCE_SENT);
            if (UrlPatch::isDrupalOrg($patch->provenance['url'] ?? '')) {
                $sent['url'] = true;
            }
            $record = \array_intersect_key($patch->provenance, $sent);
            if ([] !== $record) {
                $entry['provenance'] = $record;
            }
            if ([] !== ($resolutions[$i] ?? [])) {
                $entry['resolutions'] = $resolutions[$i];
            }
            $config[] = $entry;
        }

        return [
            'composer_json' => $request->composerJson,
            'composer_lock' => $request->composerLock,
            'client' => self::agent(),
            'patches' => true,
            'patch_config' => $config,
            'target_core' => $targetCore,
            'reroll' => $reroll,
            // What composer itself picked, when it was in reach. The
            // server's own answer comes from a daily copy of drupal.org
            // and one constraint at a time.
            'candidates' => (object) $candidates,
            // What each installed release says it needs of core, read from
            // the site's own vendor directory. The service's copy of the
            // release data can be months behind; this cannot.
            'installed_core' => (object) $request->declaredCore,
            // Sent only when the run named a flag, so the service's own
            // rule decides otherwise.
        ] + (null === $dropTests ? [] : ['drop_tests' => $dropTests]);
    }

    /**
     * One lock entry as the service receives it.
     *
     * @param array<string, mixed> $entry
     *
     * @return array<string, mixed>
     */
    private static function entry(array $entry, string $name, string $version): array
    {
        $out = ['name' => $name, 'version' => $version];
        $type = $entry['type'] ?? '';
        if (\is_string($type) && '' !== $type) {
            $out['type'] = $type;
        }
        $stamp = $entry['extra']['drupal']['datestamp'] ?? null;
        if (\is_string($stamp) || \is_int($stamp)) {
            $out['extra'] = ['drupal' => ['datestamp' => (string) $stamp]];
        }
        // The commit the installed release was cut from: the service tries
        // it first as the base of a re-roll.
        $reference = $entry['source']['reference'] ?? null;
        if (\is_string($reference) && '' !== $reference) {
            $out['source'] = ['reference' => $reference];
        }
        if (self::METAPACKAGE === $type) {
            $requires = [];
            foreach ((array) ($entry['require'] ?? []) as $dep => $constraint) {
                if (\is_string($dep) && \str_starts_with($dep, 'drupal/') && \is_string($constraint)) {
                    $requires[$dep] = $constraint;
                }
            }
            if ([] !== $requires) {
                $out['require'] = $requires;
            }
        }

        return $out;
    }

    /**
     * Whether the service has a drupal.org release to judge this package against.
     *
     * @param array<string, mixed> $entry
     */
    private static function isCheckable(string $name, array $entry): bool
    {
        return \in_array($name, IssueReference::CORE_PACKAGES, true)
            || self::DRUPAL_NOTIFICATION === ($entry['notification-url'] ?? null);
    }

    /**
     * The keys the service reads from composer.json, with every package map narrowed to what it can judge.
     *
     * @param array<string, mixed>  $json
     * @param array<string, string> $packages
     *
     * @return array<string, mixed>
     */
    private static function filterJson(array $json, array $packages): array
    {
        $out = [];
        foreach (['require', 'require-dev'] as $key) {
            $narrowed = self::onlyPackages($json[$key] ?? null, $packages);
            if ([] !== $narrowed) {
                $out[$key] = $narrowed;
            }
        }
        if (\is_string($json['minimum-stability'] ?? null)) {
            $out['minimum-stability'] = $json['minimum-stability'];
        }
        if (\is_bool($json['prefer-stable'] ?? null)) {
            $out['prefer-stable'] = $json['prefer-stable'];
        }

        return $out;
    }

    /**
     * A package-keyed map with the packages the service cannot judge removed.
     *
     * @param array<string, string> $packages
     *
     * @return array<string, mixed>
     */
    private static function onlyPackages(mixed $map, array $packages): array
    {
        if (!\is_array($map)) {
            return [];
        }
        $out = [];
        foreach ($map as $name => $value) {
            if (\is_string($name) && isset($packages[$name])) {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $text): array
    {
        $decoded = \json_decode($text, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * Encodes as an object even when nothing survived the filter.
     *
     * @param array<string, mixed> $value
     */
    private static function encode(array $value): string
    {
        return \json_encode((object) $value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }

    /**
     * Says what stopped the call: the status the server answered with, or why the request never went out.
     */
    private function reason(Throwable $e): string
    {
        $host = \parse_url($this->endpoint, \PHP_URL_HOST);
        $host = \is_string($host) && '' !== $host ? $host : $this->endpoint;
        if ($e instanceof TransportException) {
            $status = $e->getStatusCode();
            if (null !== $status && $status >= 400) {
                $reason = self::serverReason($e->getResponse());

                return Text::t(
                    '' === $reason ? '@host answered @status, patches not checked' : '@host answered @status (@reason), patches not checked',
                    ['@host' => $host, '@status' => $status, '@reason' => $reason]
                );
            }
        }

        return Text::t('@host did not answer (@why), patches not checked', ['@host' => $host, '@why' => self::clip($e->getMessage())]);
    }

    /**
     * The `error` field of a failed response, clipped; empty when the body carries none.
     */
    private static function serverReason(?string $body): string
    {
        $decoded = \json_decode((string) $body, true);
        $error = \is_array($decoded) ? $decoded['error'] ?? '' : '';

        return \is_string($error) ? self::clip($error) : '';
    }

    /**
     * The first sentence of a message, short enough for one line.
     */
    private static function clip(string $message): string
    {
        $collapsed = \preg_replace('/\s+/', ' ', $message);
        $message = \trim($collapsed ?? $message);
        $stop = \strpos($message, '. ');
        if (false !== $stop) {
            $message = \substr($message, 0, $stop);
        }

        return \strlen($message) > 160 ? \substr($message, 0, 157).'…' : $message;
    }
}

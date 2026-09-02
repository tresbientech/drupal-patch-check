<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch;

use Composer\Composer;
use Composer\Downloader\TransportException;
use Composer\IO\IOInterface;
use Composer\Util\HttpDownloader;
use RuntimeException;
use Throwable;
use TresBienTech\Drupatch\Plan\Plan;

/**
 * Asks the api for one upgrade plan, sending only what the service can check.
 */
class Client
{
    /**
     * What the request says it is. The service reads it to tell a request
     * shaped by an older release apart from a current one.
     */
    public const AGENT = 'drupal-patch-check/'.self::VERSION;

    /** Bumped with each release. */
    public const VERSION = '0.9.0';

    public const DEFAULT_ENDPOINT = 'https://api.tresbien.tech/v1/composer/scan';

    /** Whole-request budget. */
    private const TIMEOUT_SECONDS = 15;

    /** Largest plan accepted. */
    private const MAX_RESPONSE_BYTES = 64 * 1024 * 1024;

    /** The repository that serves drupal.org releases. */
    private const DRUPAL_NOTIFICATION = 'https://packages.drupal.org/8/downloads';

    /** The composer type drupal.org gives a sub-module. */
    private const METAPACKAGE = 'metapackage';

    /**
     * Core's packages, which are published to Packagist rather than to packages.drupal.org.
     */
    private const CORE_PACKAGES = [
        'drupal/core',
        'drupal/core-recommended',
        'drupal/core-composer-scaffold',
        'drupal/core-dev',
        'drupal/core-project-message',
    ];

    public function __construct(
        private readonly HttpDownloader $downloader,
        private readonly string $endpoint = self::DEFAULT_ENDPOINT,
    ) {
    }

    public static function fromComposer(Composer $composer, IOInterface $io): self
    {
        $endpoint = $composer->getPackage()->getExtra()['drupatch']['endpoint'] ?? null;
        if (!\is_string($endpoint) || '' === $endpoint) {
            $endpoint = self::DEFAULT_ENDPOINT;
        }

        return new self(new HttpDownloader($io, $composer->getConfig()), $endpoint);
    }

    /**
     * The two composer documents as the service receives them: the keys it reads, narrowed to what it can judge, and every package the lock names.
     *
     * @return array{json: string, lock: string, packages: array<string, string>, locked: array<string, string>}
     */
    public static function filter(string $composerJson, string $composerLock): array
    {
        $lock = self::decode($composerLock);
        $json = self::decode($composerJson);

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
            'json' => self::encode(self::filterJson($json, $packages)),
            'lock' => self::encode($slim),
            'packages' => $packages,
            'locked' => $locked,
        ];
    }

    /**
     * @param array<string, string>                  $candidates  composer name to the release composer would install
     * @param array<string, string>                  $declared    composer name to the core requirement its installed release declares
     * @param array<int, list<array<string, mixed>>> $resolutions regions a person decided, by patch position
     *
     * @throws RuntimeException when the call or the answer failed
     */
    public function plan(string $composerJson, string $composerLock, PatchConfig $patches, string $targetCore = '', bool $reroll = false, array $candidates = [], array $declared = [], array $resolutions = []): Plan
    {
        $body = \json_encode(self::body($composerJson, $composerLock, $patches, $targetCore, $reroll, $candidates, $declared, $resolutions), \JSON_THROW_ON_ERROR);

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
            throw new RuntimeException('the plan could not be read');
        }

        return Plan::fromArray($decoded);
    }

    /**
     * Everything the request carries, in one place, so `--dry-run` shows the body rather than a description of it.
     *
     * @param array<string, string>                  $candidates
     * @param array<string, string>                  $declared
     * @param array<int, list<array<string, mixed>>> $resolutions regions a person decided, by patch position
     *
     * @return array<string, mixed>
     */
    public static function body(string $composerJson, string $composerLock, PatchConfig $patches, string $targetCore = '', bool $reroll = false, array $candidates = [], array $declared = [], array $resolutions = []): array
    {
        $config = [];
        foreach ($patches->patches as $i => $patch) {
            if ([] !== ($resolutions[$i] ?? [])) {
                $patch['resolutions'] = $resolutions[$i];
            }
            $config[] = $patch;
        }

        return [
            'composer_json' => $composerJson,
            'composer_lock' => $composerLock,
            'client' => self::AGENT,
            'patches' => true,
            'patch_files' => (object) $patches->files,
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
            'installed_core' => (object) $declared,
        ];
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
        return \in_array($name, self::CORE_PACKAGES, true)
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
        $narrowedPatches = self::onlyPackages($json['extra']['patches'] ?? null, $packages);
        if ([] !== $narrowedPatches) {
            $out['extra'] = ['patches' => $narrowedPatches];
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

                return \sprintf('%s answered %d%s, patches not checked', $host, $status, '' === $reason ? '' : ' ('.$reason.')');
            }
        }

        return \sprintf('%s did not answer (%s), patches not checked', $host, self::clip($e->getMessage()));
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

<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Request;

use Tresbien\Drupatch\Plan\Value;

/**
 * The two composer documents as the service receives them.
 *
 * The service reads five keys of composer.json and two fields of each lock
 * entry. Everything else a site keeps in those files is its own: private
 * repository URLs, build scripts, its name, and every package that is not
 * a drupal.org release. None of it is built into a request.
 *
 * A package is checkable when composer.lock records that it came from
 * packages.drupal.org, or when it is one of core's own packages. The
 * reasoning, and the two rules rejected for it, are in ADR 0003.
 */
final class Filtered
{
    /** The repository that serves drupal.org releases. */
    private const DRUPAL_NOTIFICATION = 'https://packages.drupal.org/8/downloads';

    /**
     * Core's packages, which are published to Packagist rather than to
     * packages.drupal.org.
     */
    private const CORE_PACKAGES = [
        'drupal/core',
        'drupal/core-recommended',
        'drupal/core-composer-scaffold',
        'drupal/core-dev',
        'drupal/core-project-message',
    ];

    /**
     * @param array<string, string> $packages checkable package to its installed version
     * @param list<string>          $heldBack drupal/ packages the service cannot judge
     */
    private function __construct(
        public readonly string $composerJson,
        public readonly string $composerLock,
        public readonly array $packages,
        public readonly array $heldBack,
    ) {
    }

    public static function of(string $composerJson, string $composerLock): self
    {
        $lock = self::decode($composerLock);
        $json = self::decode($composerJson);

        $packages = [];
        $heldBack = [];
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
                $entry = Value::keyed($entry);
                $name = \is_string($entry['name'] ?? null) ? $entry['name'] : '';
                $version = \is_string($entry['version'] ?? null) ? $entry['version'] : '';
                if ('' === $name || '' === $version) {
                    continue;
                }
                if (!self::isCheckable($name, $entry)) {
                    if (\str_starts_with($name, 'drupal/')) {
                        $heldBack[] = $name;
                    }
                    continue;
                }
                $packages[$name] = $version;
                $slim[$section][] = ['name' => $name, 'version' => $version];
            }
        }

        return new self(
            self::encode(self::filterJson($json, $packages)),
            self::encode($slim),
            $packages,
            $heldBack,
        );
    }

    /**
     * Whether the service has a drupal.org release to judge this package
     * against. A name says nothing: a fork of drupal/webform in a company
     * repository carries it, and so does a private drupal/acme_module.
     *
     * @param array<string, mixed> $entry
     */
    private static function isCheckable(string $name, array $entry): bool
    {
        if (\in_array($name, self::CORE_PACKAGES, true)) {
            return true;
        }

        return self::DRUPAL_NOTIFICATION === ($entry['notification-url'] ?? null);
    }

    /**
     * The five keys the service reads, with every package map narrowed to
     * what it can judge.
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
        $extra = $json['extra'] ?? null;
        $narrowedPatches = self::onlyPackages(\is_array($extra) ? ($extra['patches'] ?? null) : null, $packages);
        if ([] !== $narrowedPatches) {
            $out['extra'] = ['patches' => $narrowedPatches];
        }

        return $out;
    }

    /**
     * A package-keyed map with the packages the service cannot judge
     * removed.
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

        return \is_array($decoded) ? Value::keyed($decoded) : [];
    }

    /**
     * Encodes as an object even when nothing survived the filter. An empty
     * PHP array is a JSON list, and the service reads these two documents
     * into structs.
     *
     * @param array<string, mixed> $value
     */
    private static function encode(array $value): string
    {
        return \json_encode((object) $value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }
}

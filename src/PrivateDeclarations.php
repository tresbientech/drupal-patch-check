<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch;

/**
 * What the site's own words do on the way out and the way back.
 *
 * A title and a local path can name a client, a ticket system and a project,
 * and the service reads neither: it reads a patch, its package, its version
 * and its text. So the title never travels, and a site that sets the key
 * sends a placeholder for every path of its own. Both are put back on the
 * answer before anything prints, writes or serialises.
 */
class PrivateDeclarations
{
    /**
     * @param list<array{package: string, title: string, source: string}> $patches      the declarations, in the order the request lists them
     * @param array<string, string>                                       $placeholders declared source to the name it travels under, empty when the site keeps none back
     */
    private function __construct(
        private readonly array $patches,
        private readonly array $placeholders,
    ) {
    }

    /**
     * Names every source the site keeps to itself, in the order the sources first appear. Nothing is named when the site did not ask, which leaves every source travelling as written.
     */
    public static function of(PatchConfig $patches, bool $private): self
    {
        $placeholders = [];
        foreach ($private ? $patches->patches : [] as $patch) {
            $source = $patch['source'];
            if (isset($placeholders[$source]) || self::isPublic($source)) {
                continue;
            }
            $placeholders[$source] = 'p'.\count($placeholders);
        }

        return new self($patches->patches, $placeholders);
    }

    /**
     * The declarations as the request sends them: no title, and a placeholder where the source is the site's own.
     *
     * @param list<array<string, mixed>> $config
     *
     * @return list<array<string, mixed>>
     */
    public function config(array $config): array
    {
        $out = [];
        foreach ($config as $entry) {
            unset($entry['title']);
            $entry['source'] = $this->travelling((string) ($entry['source'] ?? ''));
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * The patch texts keyed by the name they travel under, so the service finds each one under the source it was given.
     *
     * @param array<string, string> $files
     *
     * @return array<string, string>
     */
    public function files(array $files): array
    {
        $out = [];
        foreach ($files as $source => $text) {
            $out[$this->travelling($source)] = $text;
        }

        return $out;
    }

    /**
     * The answer with the site's own words back in it: every row's title and source, and every path the service says it never received.
     *
     * @param array<mixed> $decoded
     *
     * @return array<mixed>
     */
    public function reveal(array $decoded): array
    {
        // The service answers one row per declaration it was sent, in the
        // order it was sent them, so position pairs them. The answer is
        // remote, so a row past the end of the declarations is left alone.
        foreach (\array_keys((array) ($decoded['plan']['patches'] ?? [])) as $i) {
            if (!isset($this->patches[$i])) {
                continue;
            }
            $decoded['plan']['patches'][$i]['title'] = $this->patches[$i]['title'];
            $decoded['plan']['patches'][$i]['source'] = $this->patches[$i]['source'];
        }
        $declared = \array_flip($this->placeholders);
        foreach ((array) ($decoded['plan']['missing_files'] ?? []) as $i => $name) {
            $decoded['plan']['missing_files'][$i] = $declared[(string) $name] ?? $name;
        }

        return $decoded;
    }

    /**
     * The name one source travels under: its placeholder, or the source itself when it names nothing of the site.
     */
    private function travelling(string $source): string
    {
        return $this->placeholders[$source] ?? $source;
    }

    /**
     * A source drupal.org serves. It names nothing of the site, and the service reads the merge request in it to find the diff a re-roll merges from, so replacing it would take that base away for no gain.
     */
    private static function isPublic(string $source): bool
    {
        if (!PatchConfig::isUrl($source)) {
            return false;
        }
        $host = \strtolower((string) \parse_url(\trim($source), \PHP_URL_HOST));

        foreach (['drupal.org', 'drupalcode.org'] as $domain) {
            if ($host === $domain || \str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }
}

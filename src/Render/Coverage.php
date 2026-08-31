<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Render;

use TresBienTech\Drupatch\Site;

/**
 * What a run actually checked, and which packages it skipped.
 */
class Coverage
{
    /**
     * @param list<array{package: string, title: string, reason: string}> $skipped
     */
    public function __construct(
        public readonly int $patches,
        public readonly array $skipped,
    ) {
    }

    /**
     * What a run covered, narrowed to the packages it was asked about.
     *
     * @param list<string> $packages composer names or short names, empty for the whole site
     */
    public static function of(Site $site, array $packages = []): self
    {
        $config = $site->patches();
        if ([] === $packages) {
            return new self(\count($config->patches), $config->skipped);
        }

        $wanted = [];
        foreach ($packages as $name) {
            $wanted[self::shortName($name)] = true;
        }
        $patches = 0;
        foreach ($config->patches as $patch) {
            if (isset($wanted[self::shortName($patch['package'])])) {
                ++$patches;
            }
        }
        $skipped = [];
        foreach ($config->skipped as $entry) {
            if (isset($wanted[self::shortName($entry['package'])])) {
                $skipped[] = $entry;
            }
        }

        return new self($patches, $skipped);
    }

    /**
     * A package named the way --package accepts it: either spelling of drupal/webform reduces to the same key.
     */
    private static function shortName(string $package): string
    {
        $slash = \strrpos($package, '/');

        return false === $slash ? $package : \substr($package, $slash + 1);
    }

    /**
     * Whether patches were declared and none of them could be checked.
     */
    public function isVacuous(): bool
    {
        return 0 === $this->patches && [] !== $this->skipped;
    }

    /**
     * The lines a person reads before the table: what was covered, then one line per package the run left alone.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        if ($this->isVacuous()) {
            return ['drupatch: no patch could be checked. Every declared patch is on a package the service has no release for.'];
        }

        $groups = self::grouped($this->skipped);
        $line = \sprintf('drupatch: checked %d patch%s', $this->patches, 1 === $this->patches ? '' : 'es');
        if ([] !== $groups) {
            $line .= \sprintf(
                '; skipped %d on %d package%s',
                \count($this->skipped),
                \count($groups),
                1 === \count($groups) ? '' : 's',
            );
        }

        $out = [$line];
        foreach ($groups as $group) {
            $out[] = \sprintf(
                '  skipped  %s, %d patch%s (%s)',
                $group['package'],
                $group['count'],
                1 === $group['count'] ? '' : 'es',
                $group['reason'],
            );
        }

        return $out;
    }

    /**
     * The skipped patches as one entry per package and reason, in the order they were declared.
     *
     * @param list<array{package: string, title: string, reason: string}> $skipped
     *
     * @return list<array{package: string, reason: string, count: int}>
     */
    private static function grouped(array $skipped): array
    {
        $groups = [];
        foreach ($skipped as $entry) {
            $seen = false;
            foreach ($groups as $i => $group) {
                if ($group['package'] === $entry['package'] && $group['reason'] === $entry['reason']) {
                    $groups[$i] = ['package' => $group['package'], 'reason' => $group['reason'], 'count' => $group['count'] + 1];
                    $seen = true;
                    break;
                }
            }
            if (!$seen) {
                $groups[] = ['package' => $entry['package'], 'reason' => $entry['reason'], 'count' => 1];
            }
        }

        return $groups;
    }
}

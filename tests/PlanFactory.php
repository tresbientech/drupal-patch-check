<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests;

use TresBienTech\Drupatch\Plan\Plan;

/**
 * Builds plans for tests the way the plugin builds them in production:
 * through the same parse the server's JSON goes through, so a test can
 * never assert on a shape the boundary would refuse.
 */
trait PlanFactory
{
    /**
     * @param array<string, mixed> $overrides
     */
    private function planFrom(array $overrides = []): Plan
    {
        $flat = $overrides + [
            'target_core' => '11.4.5',
            'counts' => [],
            'package_counts' => [],
            'patches' => [],
        ];

        return Plan::fromArray(self::wire($flat));
    }

    /**
     * The shape the server answers with: the scan at the top level, the
     * patch half nested under `plan`. Tests name the fields flat, so the
     * split lives here rather than in every case.
     *
     * @param array<string, mixed> $flat
     *
     * @return array<string, mixed>
     */
    private static function wire(array $flat): array
    {
        $nested = [];
        $body = [];
        $blocked = [];
        foreach ($flat as $key => $value) {
            if ('no_release' === $key) {
                $blocked = (array) $value;
            } elseif (\in_array($key, ['counts', 'patches', 'missing_files', 'warnings'], true)) {
                $nested[$key] = $value;
            } elseif ('package_counts' === $key) {
                $body['counts'] = $value;
            } else {
                $body[$key] = $value;
            }
        }
        // The service states a blocked package on its scan row, and a
        // warning that opens with a package name is that row's sentence.
        $rows = [];
        $loose = [];
        foreach ((array) ($nested['warnings'] ?? []) as $warning) {
            $loose[] = $warning;
        }
        foreach ($blocked as $package) {
            $row = ['package' => $package, 'status' => 'no_release'];
            foreach ($loose as $i => $warning) {
                if (\str_starts_with((string) $warning, $package.' ')) {
                    $row['note'] = \substr((string) $warning, \strlen($package) + 1);
                    unset($loose[$i]);
                    break;
                }
            }
            $rows[] = $row;
        }
        if ([] !== $rows) {
            $body['rows'] = $rows;
        }
        if (isset($nested['warnings'])) {
            $nested['warnings'] = \array_values($loose);
        }
        $body['plan'] = $nested;

        return $body;
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function row(array $fields = []): array
    {
        return $fields + [
            'package' => 'drupal/webform',
            'project' => 'webform',
            'version' => '6.2.9',
            'title' => 'Fix the alter hook',
            'source' => 'patchs/webform.patch',
            'verdict' => 'applies',
        ];
    }

    /**
     * @param array<string, mixed> $reroll
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function rerolledRow(array $reroll, array $fields = []): array
    {
        return $this->row($fields + ['verdict' => 'conflicts', 'result' => ['reroll' => $reroll]]);
    }

    /**
     * @return array{path: string, status: string, package: string, title: string, verified: bool, unioned: list<array{file: string, line: int}>, regions: int}
     */
    private function writtenFile(string $path, string $status = 'clean', string $package = 'drupal/webform', string $title = 'Fix a', bool $verified = true, int $regions = 0): array
    {
        return ['path' => $path, 'status' => $status, 'package' => $package, 'title' => $title, 'verified' => $verified, 'unioned' => [], 'regions' => $regions];
    }
}

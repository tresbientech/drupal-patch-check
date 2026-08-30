<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Tests;

use Tresbien\Drupatch\Plan\Plan;
use Tresbien\Drupatch\Write\WrittenFile;

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
        foreach ($flat as $key => $value) {
            if (\in_array($key, ['counts', 'no_release', 'patches', 'missing_files', 'warnings'], true)) {
                $nested[$key] = $value;
            } elseif ('package_counts' === $key) {
                $body['counts'] = $value;
            } else {
                $body[$key] = $value;
            }
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
            'verdict' => 'still-needed',
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
        return $this->row($fields + ['verdict' => 'needs-reroll', 'result' => ['reroll' => $reroll]]);
    }

    private function writtenFile(string $path, string $status = 'clean', string $package = 'drupal/webform', string $title = 'Fix a', bool $verified = true): WrittenFile
    {
        return new WrittenFile($path, $status, $package, $title, $verified);
    }
}

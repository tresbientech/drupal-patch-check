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
        return Plan::fromArray($overrides + [
            'target_core' => '11.4.5',
            'counts' => [],
            'package_counts' => [],
            'patches' => [],
        ]);
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

<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Write;

use Composer\Util\ProcessExecutor;

/**
 * What git says about a file the plugin is about to rewrite.
 */
class WorkingTree
{
    public const NOT_A_CHECKOUT = 'the site is not in git, so an overwritten file could not be restored';

    public const UNCOMMITTED = 'it has uncommitted changes';

    public const UNTRACKED = 'it has never been committed';

    public function __construct(private readonly ProcessExecutor $process)
    {
    }

    /**
     * True when git reports the file as changed or untracked.
     */
    public function isModified(string $root, string $path): bool
    {
        $status = $this->status($root, $path);

        return null !== $status && '' !== $status;
    }

    /**
     * Why this file may not be replaced, empty when it may.
     */
    public function refusal(string $root, string $path): string
    {
        $status = $this->status($root, $path);
        if (null === $status) {
            return self::NOT_A_CHECKOUT;
        }
        if ('' === $status) {
            return '';
        }

        return \str_starts_with(\ltrim($status), '??') ? self::UNTRACKED : self::UNCOMMITTED;
    }

    /**
     * The porcelain line for one path, or null when git did not answer.
     */
    private function status(string $root, string $path): ?string
    {
        $output = '';
        $status = $this->process->execute(['git', 'status', '--porcelain', '--', $path], $output, $root);
        if (0 !== $status || !\is_string($output)) {
            return null;
        }

        return \trim($output);
    }
}

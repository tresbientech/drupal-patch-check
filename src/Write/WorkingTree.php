<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Write;

use Composer\Util\ProcessExecutor;

/**
 * Whether a file the plugin is about to rewrite already carries changes
 * nobody has committed.
 */
final class WorkingTree
{
    public function __construct(private readonly ProcessExecutor $process)
    {
    }

    /**
     * True when git reports the file as modified. A site that is not a
     * git checkout, or a machine without git, reports false: the plugin
     * cannot tell, and refusing then would block every such site.
     */
    public function isModified(string $root, string $path): bool
    {
        $output = '';
        $status = $this->process->execute(['git', 'status', '--porcelain', '--', $path], $output, $root);

        return 0 === $status && \is_string($output) && '' !== \trim($output);
    }
}

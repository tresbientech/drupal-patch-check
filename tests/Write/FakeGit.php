<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Write;

use Composer\Util\ProcessExecutor;

/**
 * Answers one canned git result, so the guard is tested without a
 * repository.
 */
final class FakeGit extends ProcessExecutor
{
    /**
     * @param string $path the file the answer is about, empty for every file
     */
    public function __construct(
        private readonly int $status,
        private readonly string $porcelain,
        private readonly string $path = '',
    ) {
        parent::__construct();
    }

    /**
     * @param string|non-empty-list<string> $command
     */
    public function execute($command, &$output = null, ?string $cwd = null): int
    {
        $asked = \is_array($command) ? \end($command) : $command;
        $output = '' === $this->path || $asked === $this->path ? $this->porcelain : '';

        return $this->status;
    }
}

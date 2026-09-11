<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Write;

use Composer\Util\ProcessExecutor;

/**
 * Answers canned git results, so the guard is tested without a
 * repository.
 */
class FakeGit extends ProcessExecutor
{
    /** @var list<string> the last argument of every command asked, in order */
    public array $asked = [];

    /**
     * @param string                            $path    the file the answer is about, empty for every file
     * @param array<string, array{int, string}> $answers exit status and output per last argument, tried before the canned answer
     */
    public function __construct(
        private readonly int $status,
        private readonly string $porcelain,
        private readonly string $path = '',
        private readonly array $answers = [],
    ) {
        parent::__construct();
    }

    /**
     * The oldest supported composer declares $cwd untyped, so a native
     * type here is fatal under `composer lowest`.
     *
     * @param string|non-empty-list<string> $command
     * @param ?string                       $cwd
     */
    public function execute($command, &$output = null, $cwd = null): int
    {
        $asked = \is_array($command) ? \end($command) : $command;
        $this->asked[] = $asked;
        if (isset($this->answers[$asked])) {
            [$status, $output] = $this->answers[$asked];

            return $status;
        }
        $output = '' === $this->path || $asked === $this->path ? $this->porcelain : '';

        return $this->status;
    }
}

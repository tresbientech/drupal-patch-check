<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Command;

use Composer\Util\Platform;
use TresBienTech\Drupatch\Tests\Scratch;

/**
 * A composer binary that records how each run started it and exits as the case says, named by COMPOSER_BINARY until the case leaves.
 */
class ComposerStub
{
    /** What the stub exits with for the command a case makes fail. */
    public const FAILED = 3;

    private readonly string $dir;

    private readonly string|false $previous;

    public function __construct()
    {
        $this->dir = \sys_get_temp_dir().'/drupatch-composer-'.\bin2hex(\random_bytes(6));
        \mkdir($this->dir, 0o777, true);
        \file_put_contents($this->dir.'/composer', \sprintf(<<<'STUB'
            <?php
            $args = array_slice($argv, 1);
            file_put_contents(__DIR__.'/runs', json_encode(['args' => $args, 'memory_limit' => ini_get('memory_limit')])."\n", FILE_APPEND);
            echo 'stub ran ', $args[0], "\n";
            fwrite(STDERR, 'stub notes '.$args[0]."\n");
            exit(is_file(__DIR__.'/fails') && file_get_contents(__DIR__.'/fails') === $args[0] ? %d : 0);
            STUB, self::FAILED));
        \touch($this->dir.'/runs');
        $this->previous = Platform::getEnv('COMPOSER_BINARY');
        Platform::putEnv('COMPOSER_BINARY', $this->dir.'/composer');
    }

    /** Makes one command exit with FAILED. */
    public function failing(string $command): self
    {
        \file_put_contents($this->dir.'/fails', $command);

        return $this;
    }

    /** Leaves COMPOSER_BINARY unset, as an entry point other than bin/composer can. */
    public function unnamed(): self
    {
        Platform::clearEnv('COMPOSER_BINARY');

        return $this;
    }

    /**
     * Every start, in order: the arguments after the script, and the memory limit the child ran under.
     *
     * @return list<array{args: list<string>, memory_limit: string}>
     */
    public function runs(): array
    {
        $out = [];
        foreach (\file($this->dir.'/runs', \FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $run = (array) \json_decode($line, true);
            $out[] = [
                'args' => \array_values(\array_map(\strval(...), (array) ($run['args'] ?? []))),
                'memory_limit' => (string) ($run['memory_limit'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * The first argument of every start, which is the command it ran.
     *
     * @return list<string>
     */
    public function commands(): array
    {
        return \array_map(static fn (array $run): string => $run['args'][0], $this->runs());
    }

    public function leave(): void
    {
        false === $this->previous
            ? Platform::clearEnv('COMPOSER_BINARY')
            : Platform::putEnv('COMPOSER_BINARY', $this->previous);
        Scratch::remove($this->dir);
    }
}

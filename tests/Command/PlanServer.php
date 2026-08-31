<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Command;

use RuntimeException;

/**
 * A local server that answers every request with one canned plan, so the
 * command can be driven end to end without the api.
 */
final class PlanServer
{
    private mixed $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    public readonly string $endpoint;

    private readonly string $dir;

    /**
     * @param array<string, mixed> $plan
     */
    public function __construct(array $plan)
    {
        $this->dir = \sys_get_temp_dir().'/drupatch-plan-'.\bin2hex(\random_bytes(6));
        \mkdir($this->dir, 0o777, true);
        \file_put_contents($this->dir.'/plan.json', (string) \json_encode($plan));
        \file_put_contents($this->dir.'/router.php', <<<'ROUTER'
            <?php
            header('Content-Type: application/json');
            echo file_get_contents(__DIR__ . '/plan.json');
            ROUTER);

        $port = self::freePort();
        $this->endpoint = 'http://127.0.0.1:'.$port.'/scan';
        $this->process = \proc_open(
            [\PHP_BINARY, '-S', '127.0.0.1:'.$port, '-t', $this->dir, $this->dir.'/router.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $this->pipes
        );
        self::waitFor($port);
    }

    public function stop(): void
    {
        if (\is_resource($this->process)) {
            \proc_terminate($this->process);
            \proc_close($this->process);
        }
        foreach ((array) \glob($this->dir.'/*') as $file) {
            if (\is_string($file)) {
                @\unlink($file);
            }
        }
        @\rmdir($this->dir);
    }

    private static function freePort(): int
    {
        $socket = \stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if (false === $socket) {
            throw new RuntimeException('no port: '.$error);
        }
        $name = (string) \stream_socket_get_name($socket, false);
        \fclose($socket);

        return (int) \substr($name, (int) \strrpos($name, ':') + 1);
    }

    private static function waitFor(int $port): void
    {
        for ($i = 0; $i < 100; ++$i) {
            $probe = @\fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
            if (false !== $probe) {
                \fclose($probe);

                return;
            }
            \usleep(50_000);
        }
        throw new RuntimeException('the plan server did not start');
    }
}

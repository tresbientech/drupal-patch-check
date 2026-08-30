<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Plan;

use Composer\Composer;
use Composer\Downloader\TransportException;
use Composer\IO\IOInterface;
use Composer\Util\HttpDownloader;
use RuntimeException;
use Throwable;
use Tresbien\Drupatch\PatchConfig\Resolution;

/**
 * Asks the api for one upgrade plan.
 *
 * Requests go through composer's own downloader, so the proxy and
 * certificate settings the site already has are honoured and no HTTP
 * library is added to the site's dependencies.
 */
final class Client
{
    public const DEFAULT_ENDPOINT = 'https://api.tresbien.tech/v1/composer/scan';

    /**
     * Whole-request budget. Composer's connect timeout is a fixed 10s and
     * counts inside it.
     */
    private const TIMEOUT_SECONDS = 15;

    /** Largest plan accepted, matching the api's own body cap. */
    private const MAX_RESPONSE_BYTES = 4 * 1024 * 1024;

    public function __construct(
        private readonly HttpDownloader $downloader,
        private readonly string $endpoint = self::DEFAULT_ENDPOINT,
    ) {
    }

    public static function fromComposer(Composer $composer, IOInterface $io): self
    {
        $own = Value::object(Value::keyed($composer->getPackage()->getExtra()), 'drupatch');
        $endpoint = Value::str($own, 'endpoint', self::DEFAULT_ENDPOINT);

        return new self(new HttpDownloader($io, $composer->getConfig()), '' === $endpoint ? self::DEFAULT_ENDPOINT : $endpoint);
    }

    /**
     * @throws RuntimeException when the call or the answer failed
     */
    public function plan(string $composerJson, string $composerLock, Resolution $patches, string $targetCore = '', bool $reroll = false): Plan
    {
        // `patches` turns on the half that judges them; `patch_config`
        // carries the declarations this plugin resolved, so the server
        // never has to guess a patch manager's shape.
        $body = \json_encode([
            'composer_json' => $composerJson,
            'composer_lock' => $composerLock,
            'patches' => true,
            'patch_files' => (object) $patches->files,
            'patch_config' => $patches->patches,
            'target_core' => $targetCore,
            'reroll' => $reroll,
        ], \JSON_THROW_ON_ERROR);

        try {
            $response = $this->downloader->get($this->endpoint, [
                'http' => [
                    'method' => 'POST',
                    'header' => ['Content-Type: application/json', 'Accept: application/json'],
                    'content' => $body,
                    'timeout' => self::TIMEOUT_SECONDS,
                ],
                'max_file_size' => self::MAX_RESPONSE_BYTES,
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException($this->reason($e), 0, $e);
        }

        $decoded = \json_decode((string) $response->getBody(), true);
        if (!\is_array($decoded)) {
            throw new RuntimeException('the plan could not be read');
        }

        return Plan::fromArray($decoded);
    }

    /**
     * Says what stopped the call in the words a person needs: the status
     * the server answered with, or why the request never went out.
     */
    private function reason(Throwable $e): string
    {
        $host = \parse_url($this->endpoint, \PHP_URL_HOST);
        $host = \is_string($host) && '' !== $host ? $host : $this->endpoint;
        if ($e instanceof TransportException) {
            $status = $e->getStatusCode();
            if (null !== $status && $status >= 400) {
                return \sprintf('%s answered %d, patches not checked', $host, $status);
            }
        }

        return \sprintf('%s not reached (%s), patches not checked', $host, self::clip($e->getMessage()));
    }

    /**
     * The first sentence of a message, short enough for one line.
     */
    private static function clip(string $message): string
    {
        $collapsed = \preg_replace('/\s+/', ' ', $message);
        $message = \trim($collapsed ?? $message);
        $stop = \strpos($message, '. ');
        if (false !== $stop) {
            $message = \substr($message, 0, $stop);
        }

        return \strlen($message) > 120 ? \substr($message, 0, 117).'…' : $message;
    }
}

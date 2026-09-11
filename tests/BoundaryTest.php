<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The three tiers hold: the service namespace calls api.tresbien.tech, the
 * fetch namespace reaches the site's own network, everything else no network.
 */
class BoundaryTest extends TestCase
{
    /** What only a class calling the service may name. */
    private const SERVICE = ['DRUPATCH_ENDPOINT', 'api.tresbien.tech/v1/'];

    /** What only a class opening a connection may name. */
    private const CONNECTING = ['HttpDownloader'];

    public function testOnlyTheServiceNamespaceNamesTheEndpoint(): void
    {
        foreach (self::sources() as $path => $text) {
            foreach (self::SERVICE as $token) {
                if (\str_contains($text, $token)) {
                    self::assertStringStartsWith('Service/', $path, $path.' names '.$token);
                }
            }
        }
    }

    public function testOnlyTheTwoRemoteNamespacesOpenAConnection(): void
    {
        foreach (self::sources() as $path => $text) {
            foreach (self::CONNECTING as $token) {
                if (\str_contains($text, $token)) {
                    self::assertMatchesRegularExpression('#^(Service|Fetch)/#', $path, $path.' names '.$token);
                }
            }
        }
    }

    /**
     * Every source file, keyed by its path under `src/`.
     *
     * @return array<string, string>
     */
    private static function sources(): array
    {
        $root = \dirname(__DIR__).'/src';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        $out = [];
        foreach ($files as $file) {
            if ('php' === $file->getExtension()) {
                $out[\str_replace('\\', '/', \substr($file->getPathname(), \strlen($root) + 1))] = (string) \file_get_contents($file->getPathname());
            }
        }
        \ksort($out);
        self::assertNotEmpty($out);

        return $out;
    }
}

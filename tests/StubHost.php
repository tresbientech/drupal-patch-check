<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests;

use ArrayObject;
use Closure;

/**
 * A host that answers what a case says, and 404 for anything else.
 */
class StubHost
{
    /**
     * @param array<string, array{int, string}> $answers status and body per URL
     * @param ArrayObject<int, string>|null     $asked   every URL the run reached, in order
     *
     * @return Closure(string): array{status: int, body: string}
     */
    public static function fetch(array $answers, ?ArrayObject $asked = null): Closure
    {
        return static function (string $url) use ($answers, $asked): array {
            $asked?->append($url);
            [$status, $body] = $answers[$url] ?? [404, ''];

            return ['status' => $status, 'body' => $body];
        };
    }
}

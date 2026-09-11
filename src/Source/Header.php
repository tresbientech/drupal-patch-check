<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Source;

/**
 * The `# drupatch` line older releases wrote at the top of a vendored patch, holding what a declaration now records under its own `extra`.
 *
 * Nothing writes one. A run that touches such a file on a 2.x site moves the
 * object onto the declaration and cuts the line, so a repository heals.
 */
class Header
{
    /** What the line opens with. One JSON object follows. */
    public const PREFIX = '# drupatch ';

    /**
     * What the header of this patch says, empty when its first line is not one.
     *
     * @return array<string, string>
     */
    public static function read(string $patch): array
    {
        $line = \rtrim(\strtok($patch, "\n") ?: '', "\r");
        if (!\str_starts_with($line, self::PREFIX)) {
            return [];
        }
        $decoded = \json_decode(\substr($line, \strlen(self::PREFIX)), true);
        if (!\is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $key => $value) {
            if (\is_string($key) && \is_string($value)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * The diff itself: everything under the header, or the whole file when it has none.
     */
    public static function body(string $patch): string
    {
        if (!\str_starts_with($patch, self::PREFIX)) {
            return $patch;
        }
        $cut = \strpos($patch, "\n");

        return false === $cut ? '' : \substr($patch, $cut + 1);
    }
}

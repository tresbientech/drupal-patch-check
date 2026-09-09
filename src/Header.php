<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch;

/**
 * The first line of a patch the site vendored: where the diff came from, the commits it was taken between, and the hash of the bytes below it.
 *
 * The plugin and the service both read it, so the keys and their order are a
 * contract rather than a rendering choice.
 */
class Header
{
    /** What the line opens with. One JSON object follows. */
    public const PREFIX = '# drupatch ';

    /** Every key the line may hold, in the order it is written. */
    private const KEYS = ['mr', 'commit', 'url', 'base', 'head', 'fetched', 'rerolled', 'sha256'];

    /**
     * The header line for a vendored patch, ending in a newline.
     *
     * @param array<string, string> $fields
     */
    public static function line(array $fields): string
    {
        $out = [];
        foreach (self::KEYS as $key) {
            if (isset($fields[$key]) && '' !== $fields[$key]) {
                $out[$key] = $fields[$key];
            }
        }

        return self::PREFIX.\json_encode($out, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)."\n";
    }

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

    /**
     * What the header records about the bytes under it, so a later run can tell whether they still are what the merge request served.
     */
    public static function hash(string $body): string
    {
        return \hash('sha256', $body);
    }
}

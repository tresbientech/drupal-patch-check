<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\PatchConfig;

/**
 * One declared patch, whichever way a patch manager writes it: a string
 * under a title key, or an object naming its own title and patch.
 *
 * Reading and rewriting both go through here, so a shape one understands
 * is never a shape the other misses.
 */
final class Entry
{
    /** Keys an entry written as an object may name its patch with. */
    public const SOURCE_KEYS = ['url', 'source', 'path', 'patch'];

    /** Keys an entry written as an object may name itself with. */
    public const TITLE_KEYS = ['description', 'title', 'label'];

    /**
     * The title of one entry: its key when it has one, otherwise what the
     * object calls itself.
     */
    public static function title(int|string $key, mixed $entry): string
    {
        if (\is_string($key)) {
            return $key;
        }
        if (\is_array($entry)) {
            foreach (self::TITLE_KEYS as $titleKey) {
                if (\is_string($entry[$titleKey] ?? null)) {
                    return $entry[$titleKey];
                }
            }
        }

        return '';
    }

    /**
     * The patch an entry names: the string itself, or the object's url,
     * source, path or patch.
     */
    public static function source(mixed $entry): string
    {
        if (\is_string($entry)) {
            return \trim($entry);
        }
        if (!\is_array($entry)) {
            return '';
        }
        foreach (self::SOURCE_KEYS as $sourceKey) {
            if (\is_string($entry[$sourceKey] ?? null)) {
                return \trim($entry[$sourceKey]);
            }
        }

        return '';
    }

    /**
     * The same entry pointing at a different patch, keeping everything
     * else it said.
     */
    public static function withSource(mixed $entry, string $source): mixed
    {
        if (!\is_array($entry)) {
            return $source;
        }
        foreach (self::SOURCE_KEYS as $sourceKey) {
            if (isset($entry[$sourceKey])) {
                $entry[$sourceKey] = $source;

                return $entry;
            }
        }
        $entry['url'] = $source;

        return $entry;
    }

    public static function isUrl(string $source): bool
    {
        $s = \strtolower(\trim($source));

        return \str_starts_with($s, 'http://') || \str_starts_with($s, 'https://');
    }
}

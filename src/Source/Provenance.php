<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Source;

/**
 * Where a vendored patch's diff came from, recorded on the patch definition under `extra.drupatch`.
 *
 * The patch manager copies the definition's `extra` into `patches.lock.json`
 * untouched. The plugin sends `mr`, `commit` and `base`, and `url` when it is
 * on drupal.org or git.drupalcode.org, as their own request field, so the keys
 * are a contract rather than a rendering choice.
 *
 * A hash of the bytes is not among them. 2.x hashes every patch it locks,
 * a local file included, and refuses one whose bytes moved.
 */
class Provenance
{
    /** The key provenance lives under, inside a definition's `extra`. */
    public const KEY = 'drupatch';

    /** Every key provenance may hold, in the order it is written. */
    private const KEYS = ['mr', 'commit', 'url', 'base', 'head', 'fetched', 'rerolled'];

    /**
     * The provenance for these fields, narrowed to the keys it may hold and ordered.
     *
     * @param array<string, string> $fields
     *
     * @return array<string, string>
     */
    public static function of(array $fields): array
    {
        $out = [];
        foreach (self::KEYS as $key) {
            if (isset($fields[$key]) && '' !== $fields[$key]) {
                $out[$key] = $fields[$key];
            }
        }

        return $out;
    }

    /**
     * The provenance a patch definition's `extra` holds, empty when it holds none.
     *
     * @param mixed $extra the definition's `extra`, which a site writes by hand
     *
     * @return array<string, string>
     */
    public static function read(mixed $extra): array
    {
        $record = \is_array($extra) ? ($extra[self::KEY] ?? null) : null;
        if (!\is_array($record)) {
            return [];
        }
        $out = [];
        foreach ($record as $key => $value) {
            if (\is_string($key) && \is_string($value)) {
                $out[$key] = $value;
            }
        }

        return self::of($out);
    }
}
